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
    public const VERSION = 2;
    public const MAX_CONTAINER_ITEMS = 2048;
    public const MAX_EVENT_NODES = 8192;
    private const LEVELS = ['debug','info','notice','warning','error','critical','alert','emergency'];
    private const FIELDS = ['timestamp','source','level','message','context','service','host','trace_id','request_id','tags'];

    public function __construct(private readonly Redactor $redactor = new Redactor()) {}

    /** @return list<IngestEvent> */
    public function parse(Request $request): array
    {
        IngestEnvelope::validate($request,self::MAX_BODY_BYTES);
        $data = StrictEventJson::decode($request->body());
        if (!$data instanceof stdClass) { self::invalid('Expected a log event or an events object.'); }
        $batch = [$data];
        if (property_exists($data,'events')) {
            if (count(get_object_vars($data)) !== 1 || !is_array($data->events) || $data->events === [] || count($data->events)>self::MAX_BATCH) { self::invalid('events must contain 1..100 objects.'); }
            $batch = $data->events;
        }
        $events = [];
        foreach ($batch as $index=>$event) {
            try { $events[] = $this->event($event); }
            catch (HttpException $e) { throw new HttpException($e->status,$e->error,'Event '.$index.': '.$e->getMessage(),$e->headers,['event_index'=>$index]+$e->details); }
        }
        return $events;
    }

    /** Recheck decoded durable jobs and internal writes; the normalized encoding may be
     * larger than the original HTTP event after masking and escaping. */
    public function forStorage(IngestEvent $event,int $version=self::VERSION): IngestEvent
    {
        return $this->event((object)[
            'source'=>$event->source,'level'=>$event->level,'message'=>$event->message,'context'=>$event->context,
            'timestamp'=>$event->timestamp,'service'=>$event->service,'host'=>$event->host,
            'trace_id'=>$event->traceId,'request_id'=>$event->requestId,'tags'=>$event->tags,
        ], 131072,$version);
    }

    private function event(mixed $event,int $maxBytes=self::MAX_EVENT_BYTES,int $version=self::VERSION): IngestEvent
    {
        if (!$event instanceof stdClass || array_diff(array_keys(get_object_vars($event)),self::FIELDS)!==[]) { self::invalid('Invalid event fields.',null,'unknown_fields'); }
        $nodes=0; $this->checkJson($event,0,$nodes,null,$version);
        if (strlen(json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > $maxBytes) { throw new HttpException(413,'event_too_large','Event exceeds size limit.',details:['reason'=>'event_size']); }
        $source = self::field($event,'source',100,true);
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$source)) { self::invalid('source must be a registered source slug.','source'); }
        $level = strtolower(self::field($event,'level',32,true));
        if (!in_array($level,self::LEVELS,true)) { self::invalid('Invalid PSR-3 level.','level'); }
        $message = self::field($event,'message',16384,true);
        $timestamp = self::timestamp(self::field($event,'timestamp',40,true));
        $context = property_exists($event,'context') ? $event->context : new stdClass();
        if (!$context instanceof stdClass || strlen(json_encode($context,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))>32768) { self::invalid('context must be an object of at most 32768 bytes.','context'); }
        $tags = property_exists($event,'tags') ? $event->tags : [];
        if (!is_array($tags) || !array_is_list($tags) || count($tags)>32) { self::invalid('tags must be an array of at most 32 strings.','tags'); }
        foreach ($tags as &$tag) {
            if (!is_string($tag) || trim($tag)==='' || strlen($tag)>128) { self::invalid('Invalid tag.','tags'); }
            $tag = $this->masked($tag,128,'tags');
        }
        unset($tag);
        $optional = fn(string $name,int $max): ?string => ($v=self::field($event,$name,$max,false))===null ? null : $this->masked($v,$max,$name);
        $context = $this->redactor->value($context);
        if (strlen(json_encode($context,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))>32768) { self::invalid('Masked context exceeds 32768 bytes.','context'); }
        return new IngestEvent($source,$level,$this->masked($message,16384,'message'),$context,$timestamp,
            $optional('service',255),$optional('host',255),$optional('trace_id',128),$optional('request_id',128),array_values(array_unique($tags)));
    }

    private function masked(string $value,int $max,string $field): string
    {
        $masked=$this->redactor->text($value);
        if (strlen($masked)>$max) { self::invalid('Masked field exceeds size limit.',$field,'masked_size'); }
        return $masked;
    }

    private function checkJson(mixed $value,int $depth,int &$nodes,?string $field=null,int $version=self::VERSION): void
    {
        if ($depth>24 || (++$nodes>self::MAX_EVENT_NODES && $version>=2)) self::invalid('Event structure exceeds validation limits.',$field,'structure_limit');
        if (is_string($value)) {
            if (!preg_match('//u',$value) || str_contains($value,"\0") || ($version>=2 && preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))) self::invalid('Unsupported characters in JSON string.',$field,'invalid_character');
            return;
        }
        if (is_float($value) && !is_finite($value)) self::invalid('Event contains an unsupported JSON value.',$field,'invalid_number');
        if (is_array($value) || $value instanceof stdClass) {
            if (is_array($value) && !array_is_list($value)) self::invalid('JSON arrays must be lists.',$field);
            if ($version>=2 && count((array)$value)>self::MAX_CONTAINER_ITEMS) self::invalid('JSON container exceeds item limit.',$field,'structure_limit');
            foreach ($value as $key=>$item) {
                $key=(string)$key;
                if (!preg_match('//u',$key) || str_contains($key,"\0") || ($version>=2 && (strlen($key)>256 || preg_match('/[\x00-\x1f\x7f]/',$key)))) self::invalid('Invalid JSON key.',$field,'invalid_key');
                $this->checkJson($item,$depth+1,$nodes,$depth===0 && in_array($key,self::FIELDS,true)?$key:$field,$version);
            }
        } elseif ($value!==null && !is_int($value) && !is_float($value) && !is_bool($value)) self::invalid('Unsupported JSON value.',$field);
    }

    /** Admission-only time policy: never reevaluate already accepted durable jobs or replay. */
    public static function checkTime(array $events,int $maxAge,int $maxFuture,int $now): void
    {
        foreach ($events as $index=>$event) {
            $time=new DateTimeImmutable($event->timestamp);
            $seconds=(int)$time->format('U')+(int)$time->format('u')/1000000;
            if (($maxAge>0 && $seconds<$now-$maxAge) || ($maxFuture>0 && $seconds>$now+$maxFuture)) {
                throw new HttpException(422,'invalid_payload','Event '.$index.': Timestamp outside configured admission window.',details:['event_index'=>$index,'field'=>'timestamp','reason'=>'timestamp_window']);
            }
        }
    }

    private static function field(stdClass $event,string $key,int $max,bool $required): ?string
    {
        $v = $event->{$key} ?? null;
        if ($v===null && !$required) { return null; }
        if (!is_string($v) || trim($v)==='' || strlen($v)>$max) { self::invalid('Invalid '.$key.'.',$key); }
        return $v;
    }

    private static function timestamp(string $v): string
    {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D',$v,$m) || str_starts_with($v,'0000')) { self::invalid('timestamp must be RFC3339 with a timezone and at most 6 fractional digits.','timestamp'); }
        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP',$m[1].'.'.str_pad($m[2]??'',6,'0').($m[3]==='Z'?'+00:00':$m[3]));
        $errors = DateTimeImmutable::getLastErrors();
        if ($time===false || ($errors!==false && ($errors['warning_count'] || $errors['error_count']))) { self::invalid('Invalid timestamp.','timestamp'); }
        $utc = $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        if (strlen($utc)!==27 || str_starts_with($utc,'0000')) { self::invalid('Timestamp outside supported range.','timestamp'); }
        return $utc;
    }

    private static function invalid(string $message,?string $field=null,string $reason='invalid_field'): never { throw new HttpException(422,'invalid_payload',$message,details:['reason'=>$reason]+($field===null?[]:['field'=>$field])); }
}
