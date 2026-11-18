<?php
declare(strict_types=1);
namespace Cajeer\Logs\Sdk;

/** Synchronous HTTPS client; retries reuse the exact bytes and idempotency key. */
final class CajeerLogsClient
{
    private readonly string $url;
    public function __construct(string $baseUrl, private readonly string $token, private readonly bool $signed = false,
        private readonly float $timeout = 10, private readonly int $attempts = 3, string $apiPrefix = '/api/v1', bool $allowInsecureHttp = false, private readonly ?string $signingKeyId = null, private readonly ?string $signingSecret = null)
    {
        $u = parse_url($baseUrl);
        if (!$u || !in_array($u['scheme'] ?? '', ['http','https'], true) || empty($u['host']) || isset($u['user'],$u['pass']) || isset($u['user']) || isset($u['query']) || isset($u['fragment']) || !in_array($u['path'] ?? '', ['', '/'], true)
            || !filter_var($baseUrl, FILTER_VALIDATE_URL) || !preg_match('~^/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~D', $apiPrefix)
            || !preg_match('/^[A-Za-z0-9._~-]{1,512}$/D', $token) || !is_finite($timeout) || $timeout <= 0 || $timeout > 120 || $attempts < 1 || $attempts > 10) {
            throw new \InvalidArgumentException('Invalid client configuration.');
        }
        if ($u['scheme'] === 'http' && !$allowInsecureHttp && !in_array($u['host'], ['localhost','127.0.0.1','[::1]'], true)) { throw new \InvalidArgumentException('HTTPS required outside loopback.'); }
        if (($signingKeyId===null)!==($signingSecret===null) || ($signingKeyId!==null && (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$signingKeyId) || !preg_match('/^sk_[a-f0-9]{64}$/D',$signingSecret)))) throw new \InvalidArgumentException('Invalid separate signing configuration.');
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

    private static function encodeEvent(array $event): string
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
        return $body;
    }

    public function ingest(array $event, ?string $idempotencyKey = null): array
    {
        return $this->sendBody(self::encodeEvent($event),$idempotencyKey ?? bin2hex(random_bytes(16)));
    }

    /** HTTP 202 confirms durable admission, not committed event storage. */
    public function ingestAsync(array $event, ?string $idempotencyKey = null): array
    {
        return $this->sendAsyncBody(self::encodeEvent($event),$idempotencyKey ?? bin2hex(random_bytes(16)));
    }

    /** Resume a delivery using the exact persisted UTF-8 JSON bytes and original key. */
    public function sendBody(string $body,string $key): array
    {
        return $this->postBody($body,$key,false);
    }

    /** Persist the exact body and key before first sending if process restart recovery is required. */
    public function sendAsyncBody(string $body,string $key): array
    {
        return $this->postBody($body,$key,true);
    }

    private function postBody(string $body,string $key,bool $async): array
    {
        if (strlen($body)>1048576 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D',$key)) { throw new \InvalidArgumentException('Invalid body or idempotency key.'); }
        $decoded=json_decode($body,false,64,JSON_THROW_ON_ERROR);
        $events=$decoded instanceof \stdClass ? (property_exists($decoded,'events')?$decoded->events:[$decoded]) : null;
        if (!is_array($events) || !array_is_list($events) || count($events)<1 || count($events)>100) throw new \InvalidArgumentException('Invalid serialized event batch.');
        $url=$this->url.($async?'/async':'');
        for ($i=0;$i<$this->attempts;$i++) {
            $headers=['Content-Type: application/json','Authorization: Bearer '.$this->token,'Idempotency-Key: '.$key,'Connection: close'];
            if ($this->signed || $this->signingKeyId!==null) {
                $ts=(string)time(); $nonce=bin2hex(random_bytes(16));
                $prefix=$this->signingKeyId===null?['cajeerlogs-ingest-v1']:['cajeerlogs-ingest-v2',$this->signingKeyId];
                if ($this->signingKeyId!==null) $headers[]='X-Log-Key-Id: '.$this->signingKeyId;
                $canonical=implode("\n",[...$prefix,'POST',parse_url($url,PHP_URL_PATH),$ts,$nonce,$key,hash('sha256',$body)]);
                array_push($headers,'X-Log-Timestamp: '.$ts,'X-Log-Nonce: '.$nonce,'X-Log-Signature: '.hash_hmac('sha256',$canonical,$this->signingSecret??$this->token));
            }
            $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers),'content'=>$body,'timeout'=>$this->timeout,'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0], 'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]]);
            $status=null; $wait=0.25*2**$i;
            $stream=@fopen($url,'rb',false,$ctx);
            if ($stream!==false) {
                try {
                    $meta=stream_get_meta_data($stream);
                    foreach ($meta['wrapper_data'] ?? [] as $header) {
                        if (preg_match('~^HTTP/\S+ (\d{3})~',$header,$m)) { $status=(int)$m[1]; }
                        if (preg_match('~^Retry-After:\s*(\d+)\s*$~i',$header,$m)) { $wait=max($wait,(float)$m[1]); }
                    }
                    $raw=stream_get_contents($stream,65537);
                    if (in_array($status,$async?[200,202]:[200,201],true) && is_string($raw) && strlen($raw)<=65536) {
                        $r=json_decode($raw,true);
                        if ($async && is_array($r) && ($r['accepted']??null)===true && ($r['received']??null)===count($events)
                            && ($r['replayed']??null)===($status===200)
                            && self::validJobId($r['job_id']??null)
                            && in_array($r['status']??null,['pending','running','completed','dead','cancelled'],true)) { return $r; }
                        if (!$async && is_array($r) && ($r['accepted']??null)===true && ($r['received']??null)===count($events) && ($r['stored']??null)===($status===200?0:count($events)) && ($r['rejected']??null)===0 && ($r['replayed']??null)===($status===200) && is_array($r['event_ids']??null) && count($r['event_ids'])===count($events) && array_is_list($r['event_ids']) && array_all($r['event_ids'],static fn($id): bool=>is_string($id)&&preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$id)===1) && count(array_unique($r['event_ids']))===count($events)) { return $r; }
                    }
                } finally { fclose($stream); }
            }
            if ($i+1===$this->attempts || $wait>30 || ($status!==null && !in_array($status,[200,201,408,429,500,502,503,504],true))) { throw new DeliveryException($status,$key); }
            usleep((int)($wait*1000000)+random_int(0,100000));
        }
        throw new \LogicException('Unreachable');
    }

    private static function validJobId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[1-9][0-9]{0,18}$/D',$id)===1
            && (strlen($id)<19 || strcmp($id,'9223372036854775807')<=0);
    }

    /** Authenticated read of a committed job. Redirects and unbounded bodies are rejected. */
    public function jobStatus(string $id,?int $expectedCount=null): array
    {
        if (!self::validJobId($id) || ($expectedCount!==null && ($expectedCount<1 || $expectedCount>100))) throw new \InvalidArgumentException('Invalid job ID or count.');
        $url=$this->url.'/jobs/'.$id;
        $ctx=stream_context_create(['http'=>['method'=>'GET','header'=>'Authorization: Bearer '.$this->token."\r\nConnection: close",'timeout'=>$this->timeout,'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0],
            'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]]);
        $stream=@fopen($url,'rb',false,$ctx);
        if ($stream===false) throw new JobStatusException(null);
        try {
            $headers=stream_get_meta_data($stream)['wrapper_data']??[];
            $status=null;
            foreach ($headers as $line) if (preg_match('~^HTTP/\S+ (\d{3})~',$line,$m)) $status=(int)$m[1];
            if ($status!==200) throw new JobStatusException($status);
            $raw=stream_get_contents($stream,65537);
        } finally { fclose($stream); }
        if (!is_string($raw) || strlen($raw)>65536) throw new \UnexpectedValueException('Invalid job status body.');
        $row=json_decode($raw,true);
        if (!is_array($row) || ($row['id']??null)!==$id || !in_array($row['status']??null,['pending','running','completed','dead','cancelled'],true)
            || !is_int($row['attempts']??null) || !is_int($row['max_attempts']??null) || $row['attempts']<0 || $row['max_attempts']<1 || $row['attempts']>$row['max_attempts'])
            throw new \UnexpectedValueException('Invalid job status.');
        if ($row['status']==='completed') {
            $r=$row['result']??null;$ids=$r['event_ids']??null;
            if (!is_array($r) || !is_array($ids) || !array_is_list($ids) || count($ids)<1 || count($ids)>100
                || ($r['stored']??null)!==count($ids) || ($expectedCount!==null && count($ids)!==$expectedCount)
                || !array_all($ids,static fn($v): bool => is_string($v) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$v)===1)
                || count(array_unique($ids))!==count($ids)) throw new \UnexpectedValueException('Invalid job completion.');
        } elseif (($row['result']??null)!==null) throw new \UnexpectedValueException('Unexpected unfinished job result.');
        return $row;
    }

    /** Never re-enqueue after HTTP 202: poll durable state until completion. */
    public function waitForJob(array $receipt,float $timeoutSeconds=30.0,float $pollSeconds=0.5): array
    {
        $id=$receipt['job_id']??null;$count=$receipt['received']??null;
        if (!self::validJobId($id) || !is_int($count) || $count<1 || $count>100 || !is_finite($timeoutSeconds) || $timeoutSeconds<=0 || $timeoutSeconds>3600
            || !is_finite($pollSeconds) || $pollSeconds<0.05 || $pollSeconds>30) throw new \InvalidArgumentException('Invalid async receipt or polling limits.');
        $deadline=hrtime(true)+(int)($timeoutSeconds*1000000000);
        while (true) {
            try {
                $row=$this->jobStatus($id,$count);
                if ($row['status']==='completed') return $row;
                if (in_array($row['status'],['dead','cancelled'],true)) throw new JobFailedException($id,$row['status']);
            } catch (JobStatusException $e) {
                if ($e->httpStatus!==null && !in_array($e->httpStatus,[408,429,500,502,503,504],true)) throw $e;
            }
            $remaining=$deadline-hrtime(true);
            if ($remaining<=0) throw new JobPendingException($id);
            usleep((int)min($pollSeconds*1000000,$remaining/1000));
        }
    }
}
