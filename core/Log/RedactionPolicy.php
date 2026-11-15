<?php
declare(strict_types=1);
namespace Cajeer\Logs\Log;

use Cajeer\Logs\Http\HttpException;
use stdClass;

/** Additive, bounded rules. Regexes mask entire matching strings, never execute replacements. */
final class RedactionPolicy
{
    public const MAX_RULES=16;
    public const MASK='[redacted]';
    private const FIELDS=['message','context','service','host','trace_id','request_id','tags'];
    private array $paths=[];
    private array $keys=[];
    private array $patterns=[];
    private int $work=0;
    private int $deadline=0;

    public function __construct(array $rules)
    {
        if (!array_is_list($rules) || count($rules)>3*self::MAX_RULES) self::invalid();
        foreach ($rules as $rule) {
            if (!$rule instanceof stdClass || count(get_object_vars($rule))!==2 || !is_string($rule->type??null) || !is_string($rule->value??null)) self::invalid();
            $v=$rule->value;
            if ($v==='' || strlen($v)>256 || !preg_match('//u',$v) || preg_match('/[\x00-\x1f\x7f]/',$v)) self::invalid();
            switch ($rule->type) {
                case 'path':
                    if (!str_starts_with($v,'/') || preg_match('/~(?![01])/',$v)) self::invalid();
                    $segments=array_map(static fn($s)=>str_replace(['~1','~0'],['/','~'],$s),explode('/',substr($v,1)));
                    if (!in_array($segments[0],self::FIELDS,true) || count($segments)>25 || ($segments[0]==='context' && count($segments)<2) || ($segments[0]==='tags' && count($segments)<2) || (!in_array($segments[0],['context','tags'],true)&&count($segments)!==1)) self::invalid();
                    $this->paths[]=$segments; break;
                case 'key': $this->keys[strtolower($v)]=true; break;
                case 'regex':
                    // A fixed delimiter and fixed limits cannot be overridden by policy text.
                    // Disallow PCRE verbs, recursion/subroutine calls and inline option groups.
                    if (str_contains($v,'~') || str_contains($v,'(?') || str_contains($v,'(*')) self::invalid();
                    $pattern='~(*NO_JIT)(*LIMIT_MATCH=10000)(*LIMIT_DEPTH=100)'.$v.'~u';
                    if (@preg_match($pattern,'')===false) self::invalid();
                    $this->patterns[]=$pattern; break;
                default: self::invalid();
            }
        }
    }
    public static function validate(array $rules): array
    {
        if (count($rules)>self::MAX_RULES) self::invalid();
        new self($rules);
        return $rules;
    }
    private static function invalid(): never { throw new HttpException(422,'invalid_redaction_policy','Invalid redaction rules.'); }
    private static function failed(): never { throw new HttpException(503,'redaction_failed','Redaction could not be completed safely.',['Retry-After'=>'5']); }

    private function mask(mixed $value,array $path,int $depth=0): mixed
    {
        if ($depth>32 || ++$this->work>300000 || hrtime(true)>$this->deadline) self::failed();
        if (isset($this->keys[strtolower((string)end($path))]) && $path[0]==='context' && count($path)>1) return self::MASK;
        foreach ($this->paths as $rule) {
            if (count($rule)!==count($path)) continue;
            foreach ($rule as $i=>$segment) if ($segment!=='*' && $segment!==$path[$i]) continue 2;
            return self::MASK;
        }
        if ($value instanceof stdClass) {
            $out=new stdClass(); foreach ($value as $k=>$v) $out->{$k}=$this->mask($v,[...$path,(string)$k],$depth+1); return $out;
        }
        if (is_array($value)) { $out=[]; foreach ($value as $k=>$v) $out[$k]=$this->mask($v,[...$path,(string)$k],$depth+1); return $out; }
        if (is_string($value) && $value!==self::MASK) {
            foreach ($this->patterns as $pattern) {
                if (++$this->work>300000 || hrtime(true)>$this->deadline) self::failed();
                $match=@preg_match($pattern,$value);
                if ($match===false) self::failed();
                if ($match===1) return self::MASK;
            }
        }
        return $value;
    }

    public function apply(IngestEvent $e,int $version=IngestValidator::VERSION): IngestEvent
    {
        $this->work=0; $this->deadline=hrtime(true)+200_000_000;
        // Built-in masking is always applied and validation is repeated afterwards.
        $e=(new IngestValidator())->forStorage($e,$version);
        $mask=fn(mixed $v,string $field)=>$v===null?null:$this->mask($v,[$field]);
        return (new IngestValidator())->forStorage(new IngestEvent($e->source,$e->level,$mask($e->message,'message'),$mask($e->context,'context'),$e->timestamp,
            $mask($e->service,'service'),$mask($e->host,'host'),$mask($e->traceId,'trace_id'),$mask($e->requestId,'request_id'),$mask($e->tags,'tags')),$version);
    }
}
