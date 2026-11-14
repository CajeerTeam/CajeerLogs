<?php
declare(strict_types=1);
namespace Cajeer\Logs\Log;

use Cajeer\Logs\Http\{HttpException,Request};
use DateTimeImmutable;
use DateTimeZone;
use stdClass;

final class IngestValidator
{
    public const MAX_BATCH = 100;
    public const MAX_BODY_BYTES = 1048576;
    public const MAX_EVENT_BYTES = 65536;
    private const LEVELS = ['debug','info','notice','warning','error','critical','alert','emergency'];
    private const FIELDS = ['timestamp','source','level','message','context','service','host','trace_id','request_id','tags'];

    public function __construct(private readonly Redactor $redactor = new Redactor()) {}

    /** @return list<IngestEvent> */
    public function parse(Request $request): array
    {
        if (strlen($request->body())>self::MAX_BODY_BYTES) { throw new HttpException(413,'payload_too_large','Ingest body exceeds 1 MiB.'); }
        $type = strtolower(trim(explode(';', $request->header('Content-Type', '') ?? '', 2)[0]));
        if ($type !== 'application/json' && !str_ends_with($type, '+json')) { throw new HttpException(415,'unsupported_media_type','Expected JSON.'); }
        if (!in_array(strtolower($request->header('Content-Encoding','identity') ?? 'identity'),['','identity'],true)) { throw new HttpException(415,'unsupported_media_type','Compressed request bodies are not supported.'); }
        try { $data = json_decode($request->body(), false, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
        catch (\JsonException) { throw new HttpException(400,'invalid_json','Malformed JSON request body.'); }
        if (!$data instanceof stdClass) { self::invalid('Expected a log event or an events object.'); }
        $batch = [$data];
        if (property_exists($data,'events')) {
            if (count(get_object_vars($data)) !== 1 || !is_array($data->events) || $data->events === [] || count($data->events)>self::MAX_BATCH) { self::invalid('events must contain 1..100 objects.'); }
            $batch = $data->events;
        }
        $events = [];
        foreach ($batch as $index=>$event) {
            try { $events[] = $this->event($event); }
            catch (HttpException $e) { throw new HttpException($e->status,$e->error,'Event '.$index.': '.$e->getMessage()); }
        }
        return $events;
    }

    /** Recheck decoded durable jobs and internal writes; the normalized encoding may be
     * larger than the original HTTP event after masking and escaping. */
    public function forStorage(IngestEvent $event): IngestEvent
    {
        return $this->event((object)[
            'source'=>$event->source,'level'=>$event->level,'message'=>$event->message,'context'=>$event->context,
            'timestamp'=>$event->timestamp,'service'=>$event->service,'host'=>$event->host,
            'trace_id'=>$event->traceId,'request_id'=>$event->requestId,'tags'=>$event->tags,
        ], 131072);
    }

    private function event(mixed $event,int $maxBytes=self::MAX_EVENT_BYTES): IngestEvent
    {
        if (!$event instanceof stdClass || array_diff(array_keys(get_object_vars($event)),self::FIELDS)!==[]) { self::invalid('Invalid event fields.'); }
        $this->checkJson($event);
        if (strlen(json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > $maxBytes) { throw new HttpException(413,'event_too_large','Event exceeds size limit.'); }
        $source = self::field($event,'source',100,true);
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$source)) { self::invalid('source must be a registered source slug.'); }
        $level = strtolower(self::field($event,'level',32,true));
        if (!in_array($level,self::LEVELS,true)) { self::invalid('Invalid PSR-3 level.'); }
        $message = self::field($event,'message',16384,true);
        $timestamp = self::timestamp(self::field($event,'timestamp',40,true));
        $context = property_exists($event,'context') ? $event->context : new stdClass();
        if (!$context instanceof stdClass || strlen(json_encode($context,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))>32768) { self::invalid('context must be an object of at most 32768 bytes.'); }
        $tags = property_exists($event,'tags') ? $event->tags : [];
        if (!is_array($tags) || !array_is_list($tags) || count($tags)>32) { self::invalid('tags must be an array of at most 32 strings.'); }
        foreach ($tags as &$tag) {
            if (!is_string($tag) || trim($tag)==='' || strlen($tag)>128) { self::invalid('Invalid tag.'); }
            $tag = $this->masked($tag,128);
        }
        unset($tag);
        $optional = fn(string $name,int $max): ?string => ($v=self::field($event,$name,$max,false))===null ? null : $this->masked($v,$max);
        $context = $this->redactor->value($context);
        if (strlen(json_encode($context,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))>32768) { self::invalid('Masked context exceeds 32768 bytes.'); }
        return new IngestEvent($source,$level,$this->masked($message,16384),$context,$timestamp,
            $optional('service',255),$optional('host',255),$optional('trace_id',128),$optional('request_id',128),array_values(array_unique($tags)));
    }

    private function masked(string $value,int $max): string
    {
        $masked=$this->redactor->text($value);
        if (strlen($masked)>$max) { self::invalid('Masked field exceeds size limit.'); }
        return $masked;
    }

    private function checkJson(mixed $value, int $depth=0): void
    {
        if ($depth>24) { self::invalid('Event nesting exceeds 24 levels.'); }
        if ((is_string($value) && str_contains($value,"\0")) || (is_float($value) && !is_finite($value))) { self::invalid('Event contains an unsupported JSON value.'); }
        if (is_array($value) || $value instanceof stdClass) {
            foreach ($value as $key=>$item) {
                if (str_contains((string)$key,"\0")) { self::invalid('Invalid JSON key.'); }
                $this->checkJson($item,$depth+1);
            }
        }
    }

    private static function field(stdClass $event,string $key,int $max,bool $required): ?string
    {
        $v = $event->{$key} ?? null;
        if ($v===null && !$required) { return null; }
        if (!is_string($v) || trim($v)==='' || strlen($v)>$max) { self::invalid('Invalid '.$key.'.'); }
        return $v;
    }

    private static function timestamp(string $v): string
    {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D',$v,$m) || str_starts_with($v,'0000')) { self::invalid('timestamp must be RFC3339 with a timezone and at most 6 fractional digits.'); }
        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP',$m[1].'.'.str_pad($m[2]??'',6,'0').($m[3]==='Z'?'+00:00':$m[3]));
        $errors = DateTimeImmutable::getLastErrors();
        if ($time===false || ($errors!==false && ($errors['warning_count'] || $errors['error_count']))) { self::invalid('Invalid timestamp.'); }
        $utc = $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        if (strlen($utc)!==27 || str_starts_with($utc,'0000')) { self::invalid('Timestamp outside supported range.'); }
        return $utc;
    }

    private static function invalid(string $message): never { throw new HttpException(422,'invalid_payload',$message); }
}
