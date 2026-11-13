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
    private const FILTERS=['project_id','source_id','source','level','service','host','trace_id','request_id','from','to','q','tag','context'];
    private function __construct(public readonly array $filters, public readonly int $limit, public readonly ?string $cursor, public readonly string $interval, public readonly string $format) {}

    public static function parse(array $input,string $mode='list'): self
    {
        $allowed=match($mode) {
            'list'=>[...self::FILTERS,'limit','cursor'],
            'export'=>[...self::FILTERS,'limit','cursor','format'],
            'stats'=>[...self::FILTERS,'interval'],
            'detail'=>['project_id','source_id'],
            default=>throw new \LogicException('Unknown query mode.'),
        };
        if (array_diff(array_keys($input),$allowed)!==[]) { self::invalid('Unknown query parameter.'); }
        $f=['project_id'=>self::uuid(self::value($input,'project_id',36))];
        if (array_key_exists('source_id',$input)) { $f['source_id']=self::uuid(self::value($input,'source_id',36)); }
        foreach (['source'=>100,'level'=>32,'service'=>255,'host'=>255,'trace_id'=>128,'request_id'=>128,'q'=>512,'tag'=>128] as $key=>$max) {
            if (array_key_exists($key,$input)) { $f[$key]=self::value($input,$key,$max); }
        }
        if (isset($f['source']) && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$f['source'])) { self::invalid('Invalid source slug.'); }
        if (isset($f['level']) && !in_array($f['level'],self::LEVELS,true)) { self::invalid('Invalid PSR-3 level.'); }
        foreach (['from','to'] as $key) { if (array_key_exists($key,$input)) { $f[$key]=self::timestamp(self::value($input,$key,40)); } }
        if (isset($f['from'],$f['to']) && strcmp($f['from'],$f['to'])>=0) { self::invalid('from must precede to.'); }
        if (array_key_exists('context',$input)) {
            $raw=self::value($input,'context',4096);
            try { $object=json_decode($raw,false,16,JSON_THROW_ON_ERROR); }
            catch (\JsonException) { self::invalid('context must be a JSON object.'); }
            if (!$object instanceof stdClass) { self::invalid('context must be a JSON object.'); }
            self::checkJson($object);
            // Preserve numeric precision by binding the original JSON to PostgreSQL.
            $f['context']=$raw;
        }
        $limit=50;
        if (array_key_exists('limit',$input)) {
            $v=self::value($input,'limit',3);
            if (!preg_match('/^[1-9][0-9]{0,2}$/D',$v) || (int)$v>100) { self::invalid('limit must be 1..100.'); }
            $limit=(int)$v;
        }
        $cursor=array_key_exists('cursor',$input)?self::value($input,'cursor',1024):null;
        $interval=array_key_exists('interval',$input)?self::value($input,'interval',4):'hour';
        if (!in_array($interval,['hour','day'],true)) { self::invalid('interval must be hour or day.'); }
        if ($mode==='stats' && (!isset($f['from'],$f['to']) || (new DateTimeImmutable($f['to']))>(new DateTimeImmutable($f['from']))->modify('+31 days'))) { self::invalid('Statistics require from and to spanning at most 31 days.'); }
        $format=array_key_exists('format',$input)?self::value($input,'format',4):'json';
        if (!in_array($format,['json','csv'],true)) { self::invalid('format must be json or csv.'); }
        return new self($f,$limit,$cursor,$interval,$format);
    }

    private static function value(array $input,string $key,int $max): string
    {
        $v=$input[$key]??null;
        if (!is_string($v) || trim($v)==='' || strlen($v)>$max || str_contains($v,"\0") || !preg_match('//u',$v)) { self::invalid('Invalid '.$key.'.'); }
        return $v;
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
        if ($source!==null) { $f['source_id']=$source; }
        return new self($f,$this->limit,$this->cursor,$this->interval,$this->format);
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
