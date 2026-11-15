<?php
declare(strict_types=1);
namespace Cajeer\Logs\Sdk;

/** Synchronous HTTPS client; retries reuse the exact bytes and idempotency key. */
final class CajeerLogsClient
{
    private readonly string $url;
    public function __construct(string $baseUrl, private readonly string $token, private readonly bool $signed = false,
        private readonly float $timeout = 10, private readonly int $attempts = 3, string $apiPrefix = '/api/v1', bool $allowInsecureHttp = false)
    {
        $u = parse_url($baseUrl);
        if (!$u || !in_array($u['scheme'] ?? '', ['http','https'], true) || empty($u['host']) || isset($u['user'],$u['pass']) || isset($u['user']) || isset($u['query']) || isset($u['fragment']) || !in_array($u['path'] ?? '', ['', '/'], true)
            || !filter_var($baseUrl, FILTER_VALIDATE_URL) || !preg_match('~^/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~D', $apiPrefix)
            || !preg_match('/^[A-Za-z0-9._~-]{1,512}$/D', $token) || !is_finite($timeout) || $timeout <= 0 || $timeout > 120 || $attempts < 1 || $attempts > 10) {
            throw new \InvalidArgumentException('Invalid client configuration.');
        }
        if ($u['scheme'] === 'http' && !$allowInsecureHttp && !in_array($u['host'], ['localhost','127.0.0.1','[::1]'], true)) { throw new \InvalidArgumentException('HTTPS required outside loopback.'); }
        $this->url = rtrim($baseUrl,'/').$apiPrefix.'/ingest';
    }

    private static function validateEvent(array $item): void
    {
        $json=json_encode($item,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (strlen($json)>65536) throw new \InvalidArgumentException('Event exceeds 64 KiB.');
        $e=json_decode($json,false,32,JSON_THROW_ON_ERROR);
        $fields=['timestamp','source','level','message','context','service','host','trace_id','request_id','tags'];
        if (!$e instanceof \stdClass || array_diff(array_keys(get_object_vars($e)),$fields)!==[]) throw new \InvalidArgumentException('Invalid event fields.');
        $text=static function(mixed $v,int $max): bool { return is_string($v) && trim($v)!=='' && strlen($v)<=$max; };
        if (!$text($e->source??null,100) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$e->source) || !$text($e->level??null,32) || !in_array(strtolower($e->level),['debug','info','notice','warning','error','critical','alert','emergency'],true) || !$text($e->message??null,16384)) throw new \InvalidArgumentException('Invalid required event field.');
        $time=$e->timestamp??null;
        if (!is_string($time) || !preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D',$time,$m) || str_starts_with($time,'0000')) throw new \InvalidArgumentException('Invalid timestamp.');
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP',$m[1].'.'.str_pad($m[2]??'',6,'0').($m[3]==='Z'?'+00:00':$m[3]));
        $errors=\DateTimeImmutable::getLastErrors();
        if (!$date || ($errors!==false && ($errors['warning_count']||$errors['error_count']))) throw new \InvalidArgumentException('Invalid timestamp.');
        $utc=$date->setTimezone(new \DateTimeZone('UTC'))->format('Y');
        if (strlen($utc)!==4 || $utc==='0000') throw new \InvalidArgumentException('Invalid timestamp.');
        foreach (['service'=>255,'host'=>255,'trace_id'=>128,'request_id'=>128] as $key=>$max) if (isset($e->$key) && !$text($e->$key,$max)) throw new \InvalidArgumentException('Invalid optional event field.');
        if (property_exists($e,'context') && (!$e->context instanceof \stdClass || strlen(json_encode($e->context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))>32768)) throw new \InvalidArgumentException('Invalid context.');
        if (property_exists($e,'tags') && (!is_array($e->tags) || count($e->tags)>32 || !array_all($e->tags,static fn($tag)=>$text($tag,128)))) throw new \InvalidArgumentException('Invalid tags.');
        $nodes=0;
        $walk=static function(mixed $v,int $depth) use (&$walk,&$nodes): void {
            if ($depth>24 || ++$nodes>8192) throw new \InvalidArgumentException('Event structure exceeds limits.');
            if (is_string($v) && preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$v)) throw new \InvalidArgumentException('Invalid string.');
            if (is_array($v)||$v instanceof \stdClass) {
                if (count((array)$v)>2048) throw new \InvalidArgumentException('Event structure exceeds limits.');
                foreach ($v as $key=>$value) { if (strlen((string)$key)>256 || preg_match('/[\x00-\x1f\x7f]/',(string)$key)) throw new \InvalidArgumentException('Invalid key.'); $walk($value,$depth+1); }
            }
        };
        $walk($e,0);
    }

    public function ingest(array $event, ?string $idempotencyKey = null): array
    {
        $payload = array_is_list($event) ? ['events'=>$event] : $event;
        if (array_key_exists('events',$payload) && count($payload)!==1) throw new \InvalidArgumentException('Invalid batch wrapper.');
        $events = array_key_exists('events',$payload) ? $payload['events'] : [$payload];
        if (!is_array($events) || !array_is_list($events) || count($events)<1 || count($events)>100) { throw new \InvalidArgumentException('Expected 1..100 events.'); }
        foreach ($events as &$item) {
            if (!is_array($item) || array_is_list($item)) { throw new \InvalidArgumentException('Expected event object.'); }
            if (isset($item['context']) && $item['context'] === []) { $item['context'] = new \stdClass(); }
            self::validateEvent($item);
        }
        unset($item);
        $payload = isset($payload['events']) ? ['events'=>$events] : $events[0];
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $key = $idempotencyKey ?? bin2hex(random_bytes(16));
        if (strlen($body)>1048576 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D',$key)) { throw new \InvalidArgumentException('Invalid body or idempotency key.'); }
        for ($i=0;$i<$this->attempts;$i++) {
            $headers=['Content-Type: application/json','Authorization: Bearer '.$this->token,'Idempotency-Key: '.$key,'Connection: close'];
            if ($this->signed) {
                $ts=(string)time(); $nonce=bin2hex(random_bytes(16));
                $canonical=implode("\n",['cajeerlogs-ingest-v1','POST',parse_url($this->url,PHP_URL_PATH),$ts,$nonce,$key,hash('sha256',$body)]);
                array_push($headers,'X-Log-Timestamp: '.$ts,'X-Log-Nonce: '.$nonce,'X-Log-Signature: '.hash_hmac('sha256',$canonical,$this->token));
            }
            $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers),'content'=>$body,'timeout'=>$this->timeout,'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0], 'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]]);
            $status=null; $wait=0.25*2**$i;
            $stream=@fopen($this->url,'rb',false,$ctx);
            if ($stream!==false) {
                try {
                    $meta=stream_get_meta_data($stream);
                    foreach ($meta['wrapper_data'] ?? [] as $header) {
                        if (preg_match('~^HTTP/\S+ (\d{3})~',$header,$m)) { $status=(int)$m[1]; }
                        if (preg_match('~^Retry-After:\s*(\d+)\s*$~i',$header,$m)) { $wait=max($wait,(float)$m[1]); }
                    }
                    $raw=stream_get_contents($stream,65537);
                    if (in_array($status,[200,201],true) && is_string($raw) && strlen($raw)<=65536) {
                        $r=json_decode($raw,true);
                        if (is_array($r) && ($r['accepted']??null)===true && ($r['received']??null)===count($events) && ($r['stored']??null)===($status===200?0:count($events)) && ($r['rejected']??null)===0 && ($r['replayed']??null)===($status===200) && is_array($r['event_ids']??null) && count($r['event_ids'])===count($events) && array_all($r['event_ids'],static fn($id): bool=>is_string($id)&&$id!=='')) { return $r; }
                    }
                } finally { fclose($stream); }
            }
            if ($i+1===$this->attempts || $wait>30 || ($status!==null && !in_array($status,[200,201,408,429,500,502,503,504],true))) { throw new DeliveryException($status,$key); }
            usleep((int)($wait*1000000)+random_int(0,100000));
        }
        throw new \LogicException('Unreachable');
    }
}
