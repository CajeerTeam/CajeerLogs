<?php
declare(strict_types=1);
namespace Cajeer\Logs\Log;

use Cajeer\Logs\Http\HttpException;
use stdClass;

/** Canonical bounded batch shared by HTTP, durable queue and the SQL repository. */
final class IngestBatch
{
    public const JOB_BYTES = 2097152;
    public const SQL_BYTES = 4194304;
    public const JOB_DECODE_BYTES = 8388608;
    private const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** @return list<IngestEvent> */
    public static function normalize(array $events, int $maxBytes=self::SQL_BYTES,int $version=IngestValidator::VERSION): array
    {
        if (!array_is_list($events) || count($events)<1 || count($events)>IngestValidator::MAX_BATCH) {
            throw new HttpException(422,'invalid_payload','Expected 1..100 log events.');
        }
        $validator=new IngestValidator(); $result=[]; $size=2;
        foreach ($events as $event) {
            if (!$event instanceof IngestEvent) throw new HttpException(422,'invalid_payload','Invalid normalized event.');
            $event=$validator->forStorage($event,$version);
            $size+=strlen(json_encode($event,self::FLAGS))+($result===[]?0:1);
            if ($size>$maxBytes) throw new HttpException(413,'payload_too_large','Normalized batch exceeds size limit.');
            $result[]=$event;
        }
        return $result;
    }

    /** HTTP parser has already normalized and redacted these events. Bound the
     * compact representation before obtaining transactional row locks. */
    public static function checkSize(array $events,int $maxBytes): void
    {
        if (!array_is_list($events) || count($events)<1 || count($events)>IngestValidator::MAX_BATCH) throw new HttpException(422,'invalid_payload','Expected 1..100 log events.');
        $size=2;
        foreach ($events as $i=>$event) {
            if (!$event instanceof IngestEvent) throw new HttpException(422,'invalid_payload','Invalid normalized event.');
            $size+=strlen(json_encode($event,self::FLAGS))+($i===0?0:1);
            if ($size>$maxBytes) throw new HttpException(413,'payload_too_large','Normalized batch exceeds size limit.');
        }
    }

    /** Check exact source coverage; numeric slugs are legal PHP array keys. */
    public static function sourceMap(array $events,array $sources): array
    {
        $expected=[];
        foreach ($events as $event) $expected[$event->source]=true;
        if (count($expected)!==count($sources)) throw new HttpException(422,'invalid_payload','Invalid batch source mapping.');
        foreach ($sources as $slug=>$id) {
            if (!isset($expected[$slug]) || !is_string($id)) throw new HttpException(422,'invalid_payload','Invalid batch source mapping.');
            $sources[$slug]=LogQuery::uuid($id);
        }
        return $sources;
    }

    /** Serialize the exact durable payload before acquiring the queue capacity lock. */
    public static function encodeJob(array $events,array $sources): string
    {
        $events=self::normalize($events,self::JOB_BYTES);
        $sources=self::sourceMap($events,$sources);
        $body=json_encode(['events'=>$events,'sources'=>(object)$sources,'validation_version'=>IngestValidator::VERSION],self::FLAGS);
        if (strlen($body)>self::JOB_BYTES) throw new HttpException(413,'payload_too_large','Normalized job exceeds 2 MiB.');
        return $body;
    }

    /** Decode historical normalized event shape without coercion, missing fields or extra data. */
    public static function decodeEvents(array $payload,int $version=IngestValidator::VERSION): array
    {
        if (!array_is_list($payload) || count($payload)<1 || count($payload)>IngestValidator::MAX_BATCH) throw new HttpException(422,'invalid_payload','Invalid durable event list.');
        $fields=['source','level','message','context','timestamp','service','host','traceId','requestId','tags'];
        $events=[];
        foreach ($payload as $e) {
            if (!$e instanceof stdClass || count(get_object_vars($e))!==count($fields) || array_diff($fields,array_keys(get_object_vars($e)))!==[]) throw new HttpException(422,'invalid_payload','Invalid durable event fields.');
            foreach (['source','level','message','timestamp'] as $f) if (!is_string($e->$f)) throw new HttpException(422,'invalid_payload','Invalid durable event field type.');
            foreach (['service','host','traceId','requestId'] as $f) if ($e->$f!==null && !is_string($e->$f)) throw new HttpException(422,'invalid_payload','Invalid durable event field type.');
            if (!$e->context instanceof stdClass || !is_array($e->tags)) throw new HttpException(422,'invalid_payload','Invalid durable context or tags.');
            $events[]=new IngestEvent($e->source,$e->level,$e->message,$e->context,$e->timestamp,$e->service,$e->host,$e->traceId,$e->requestId,$e->tags);
        }
        return self::normalize($events,self::JOB_BYTES,$version);
    }

    /** PostgreSQL JSONB adds whitespace; the compact encoding is the authoritative size. */
    public static function decodeJob(string $body): array
    {
        if (strlen($body)>self::JOB_DECODE_BYTES) throw new HttpException(413,'payload_too_large','Stored job exceeds decoding limit.');
        $p=json_decode($body,false,64,JSON_THROW_ON_ERROR);
        if (!$p instanceof stdClass || array_diff(array_keys(get_object_vars($p)),['events','sources','validation_version'])!==[] || (property_exists($p,'validation_version') && $p->validation_version!==IngestValidator::VERSION) || !isset($p->events,$p->sources) || !is_array($p->events) || (!$p->sources instanceof stdClass && !is_array($p->sources))) throw new HttpException(422,'invalid_payload','Invalid durable job.');
        $version=$p->validation_version??1;
        $events=self::decodeEvents($p->events,$version);
        // Old releases encoded maps with contiguous numeric keys as arrays.
        $sources=self::sourceMap($events,(array)$p->sources);
        if (strlen(json_encode(['events'=>$events,'sources'=>(object)$sources]+(property_exists($p,'validation_version')?['validation_version'=>$p->validation_version]:[]),self::FLAGS))>self::JOB_BYTES) throw new HttpException(413,'payload_too_large','Normalized job exceeds 2 MiB.');
        return [$events,$sources,$version];
    }
}
