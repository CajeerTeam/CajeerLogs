<?php
declare(strict_types=1);
namespace Cajeer\Logs\Log;

use Cajeer\Logs\Http\HttpException;
use DateTimeImmutable;
use DateTimeZone;
use stdClass;

/** Validated, canonical filters. All SQL values are bound by the search engine. */
final class LogQuery
{
    public const LEVELS=['debug','info','notice','warning','error','critical','alert','emergency'];
    private const FILTERS=['project_id','source_id','source','level','service','host','trace_id','request_id','from','to','q','tag','context','q_mode','levels','sources','source_ids','services','hosts','trace_ids','request_ids','tags_all','tags_any','context_path','context_equals'];
    public const LIST_FILTERS=['levels'=>32,'sources'=>100,'source_ids'=>36,'services'=>255,'hosts'=>255,'trace_ids'=>128,'request_ids'=>128,'tags_all'=>128,'tags_any'=>128];
    private function __construct(public readonly array $filters, public readonly int $limit, public readonly ?string $cursor, public readonly string $interval, public readonly string $format, public readonly string $consistency) {}

    public static function parse(array $input,string $mode='list'): self
    {
        $allowed=match($mode) {
            'list'=>[...self::FILTERS,'limit','cursor','consistency'],
            'export'=>[...self::FILTERS,'limit','cursor','format','consistency'],
            'stats'=>[...self::FILTERS,'interval'],
            'detail'=>['project_id','source_id'],
            default=>throw new \LogicException('Unknown query mode.'),
        };
        if (array_diff(array_keys($input),$allowed)!==[]) { self::invalid('Unknown query parameter.'); }
        // Bound total decoded filter bytes even for callers bypassing the HTTP gateway.
        $bytes=0; foreach ($input as $v) { if (!is_string($v)) self::invalid('Query parameters must be strings.'); $bytes+=strlen($v); }
        if ($bytes>16384) self::invalid('Query filters exceed 16 KiB.');
        $f=['project_id'=>self::uuid(self::value($input,'project_id',36))];
        if (array_key_exists('source_id',$input)) { $f['source_id']=self::uuid(self::value($input,'source_id',36)); }
        foreach (['source'=>100,'level'=>32,'service'=>255,'host'=>255,'trace_id'=>128,'request_id'=>128,'q'=>512,'tag'=>128] as $key=>$max) {
            if (array_key_exists($key,$input)) { $f[$key]=self::value($input,$key,$max); }
        }
        if (isset($f['source']) && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$f['source'])) { self::invalid('Invalid source slug.'); }
        if (isset($f['level']) && !in_array($f['level'],self::LEVELS,true)) { self::invalid('Invalid PSR-3 level.'); }
        $count=0;
        foreach (self::LIST_FILTERS as $key=>$max) {
            if (!array_key_exists($key,$input)) continue;
            $items=self::strings(self::value($input,$key,4096),$key,$max,16);
            foreach ($items as &$item) {
                if ($key==='levels' && !in_array($item,self::LEVELS,true)) self::invalid('Invalid PSR-3 level.');
                if ($key==='sources' && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$item)) self::invalid('Invalid source slug.');
                if ($key==='source_ids') $item=self::uuid($item);
            } unset($item);
            $count+=count($items);
            if ($count>64) self::invalid('Too many filter values.');
            $items=array_values(array_unique($items)); sort($items,SORT_STRING);
            $f[$key]=json_encode($items,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }
        if (array_key_exists('q_mode',$input)) {
            $modeValue=self::value($input,'q_mode',6);
            if (!isset($f['q']) || !in_array($modeValue,['web','plain','phrase'],true)) self::invalid('q_mode requires q and must be web, plain or phrase.');
            // Preserve the existing fingerprint for default web search.
            if ($modeValue!=='web') $f['q_mode']=$modeValue;
        }
        foreach (['from','to'] as $key) { if (array_key_exists($key,$input)) { $f[$key]=self::timestamp(self::value($input,$key,40)); } }
        if (isset($f['from'],$f['to']) && strcmp($f['from'],$f['to'])>=0) { self::invalid('from must precede to.'); }
        if (array_key_exists('context',$input)) {
            $raw=self::value($input,'context',4096);
            $object=self::json($raw);
            if (!$object instanceof stdClass) { self::invalid('context must be a JSON object.'); }
            self::checkJson($object);
            // Preserve numeric precision by binding the original JSON to PostgreSQL.
            $f['context']=$raw;
        }
        if (array_key_exists('context_path',$input)) {
            $path=self::strings(self::value($input,'context_path',2048),'context_path',256,8);
            $f['context_path']=json_encode($path,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if (!self::boundedRange($f)) self::invalid('context_path requires from and to spanning at most 31 days.');
        }
        if (array_key_exists('context_equals',$input)) {
            if (!isset($f['context_path'])) self::invalid('context_equals requires context_path.');
            $raw=self::value($input,'context_equals',4096); $value=self::json($raw);
            if (is_array($value)||$value instanceof stdClass) self::invalid('context_equals must be a JSON scalar.');
            self::checkJson($value); $f['context_equals']=$raw;
        }
        $limit=50;
        if (array_key_exists('limit',$input)) {
            $v=self::value($input,'limit',3);
            if (!preg_match('/^[1-9][0-9]{0,2}$/D',$v) || (int)$v>100) { self::invalid('limit must be 1..100.'); }
            $limit=(int)$v;
        }
        $cursor=array_key_exists('cursor',$input)?self::value($input,'cursor',1024):null;
        $interval=array_key_exists('interval',$input)?self::value($input,'interval',6):'hour';
        if (!in_array($interval,['minute','5min','hour','day'],true)) { self::invalid('interval must be minute, 5min, hour or day.'); }
        if ($mode==='stats' && (!isset($f['from'],$f['to']) || (new DateTimeImmutable($f['to']))>(new DateTimeImmutable($f['from']))->modify('+31 days'))) { self::invalid('Statistics require from and to spanning at most 31 days.'); }
        if ($mode==='stats' && in_array($interval,['minute','5min'],true)) {
            $max=$interval==='minute'?'+6 hours':'+2 days';
            if (new DateTimeImmutable($f['to'])>(new DateTimeImmutable($f['from']))->modify($max)) self::invalid('Statistics interval exceeds its bucket budget.');
        }
        $format=array_key_exists('format',$input)?self::value($input,'format',4):'json';
        if (!in_array($format,['json','csv'],true)) { self::invalid('format must be json or csv.'); }
        $consistency=array_key_exists('consistency',$input)?self::value($input,'consistency',8):'live';
        if (!in_array($consistency,['live','snapshot'],true)) self::invalid('consistency must be live or snapshot.');
        return new self($f,$limit,$cursor,$interval,$format,$consistency);
    }

    private static function value(array $input,string $key,int $max): string
    {
        $v=$input[$key]??null;
        if (!is_string($v) || trim($v)==='' || strlen($v)>$max || str_contains($v,"\0") || !preg_match('//u',$v)) { self::invalid('Invalid '.$key.'.'); }
        return $v;
    }
    private static function json(string $raw): mixed
    {
        try {
            // Reject duplicate object keys before json_decode loses them. Bind the
            // original JSON to PostgreSQL, not the PHP numeric representation.
            StrictEventJson::decode('{"context":'.$raw.'}');
            return json_decode($raw,false,16,JSON_THROW_ON_ERROR);
        } catch (\JsonException|HttpException) { self::invalid('Invalid JSON filter.'); }
    }
    private static function strings(string $raw,string $key,int $max,int $limit): array
    {
        $items=self::json($raw);
        if (!is_array($items)||count($items)<1||count($items)>$limit) self::invalid('Invalid '.$key.' list.');
        foreach ($items as $item) self::value([$key=>$item],$key,$max);
        return $items;
    }
    public static function boundedRange(array $filters): bool
    {
        return isset($filters['from'],$filters['to']) && (new DateTimeImmutable($filters['to'])) <= (new DateTimeImmutable($filters['from']))->modify('+31 days');
    }
    public function textFunction(): string
    {
        return match($this->filters['q_mode']??'web') {'web'=>'websearch_to_tsquery','plain'=>'plainto_tsquery','phrase'=>'phraseto_tsquery'};
    }
    private static function checkJson(mixed $v): void
    {
        if ((is_string($v)&&str_contains($v,"\0")) || (is_float($v)&&!is_finite($v))) { self::invalid('Unsupported context value.'); }
        if (is_array($v)||$v instanceof stdClass) { foreach ($v as $k=>$child) { if (str_contains((string)$k,"\0")) { self::invalid('Invalid context key.'); } self::checkJson($child); } }
    }
    public static function uuid(string $v): string
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD',$v)) { self::invalid('Invalid UUID.'); }
        return strtolower($v);
    }
    public static function timestamp(string $v): string
    {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D',$v,$m)||str_starts_with($v,'0000')) { self::invalid('Expected RFC3339 timestamp with timezone.'); }
        $t=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP',$m[1].'.'.str_pad($m[2]??'',6,'0').($m[3]==='Z'?'+00:00':$m[3]));
        $e=DateTimeImmutable::getLastErrors();
        if ($t===false||($e!==false&&($e['warning_count']||$e['error_count']))) { self::invalid('Invalid timestamp.'); }
        $utc=$t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        if (strlen($utc)!==27||str_starts_with($utc,'0000')) { self::invalid('Timestamp outside supported range.'); }
        return $utc;
    }
    /** Token source is enforced even when no source filter was supplied. */
    public function scoped(?string $source): self
    {
        $f=$this->filters;
        if ($source!==null) {
            $source=self::uuid($source);
            if (isset($f['source_id']) && $f['source_id']!==$source) { throw new HttpException(403,'forbidden','Access denied.'); }
            $f['source_id']=$source;
        }
        return new self($f,$this->limit,$this->cursor,$this->interval,$this->format,$this->consistency);
    }
    private function fingerprint(): string
    {
        $f=$this->filters; ksort($f);
        return hash('sha256',json_encode($f,JSON_THROW_ON_ERROR));
    }
    public function position(): ?array
    {
        if ($this->cursor===null) { return null; }
        if (!preg_match('/^[A-Za-z0-9_-]+$/D',$this->cursor)) { self::invalid('Invalid cursor.'); }
        $raw=base64_decode(strtr($this->cursor,'-_','+/'),true);
        try { $p=$raw===false?null:json_decode($raw,true,8,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { self::invalid('Invalid cursor.'); }
        if (!is_array($p)||array_keys($p)!==['v','f','t','id']||$p['v']!==1||$p['f']!==$this->fingerprint()||!is_string($p['t'])||!is_string($p['id'])) { self::invalid('Invalid cursor or changed filters.'); }
        return ['t'=>self::timestamp($p['t']),'id'=>self::uuid($p['id'])];
    }
    public function next(string $timestamp,string $id): string
    {
        return rtrim(strtr(base64_encode(json_encode(['v'=>1,'f'=>$this->fingerprint(),'t'=>self::timestamp($timestamp),'id'=>self::uuid($id)],JSON_THROW_ON_ERROR)),'+/','-_'),'=');
    }
    private static function invalid(string $message): never { throw new HttpException(422,'invalid_query',$message); }
}
