<?php
declare(strict_types=1);
namespace Cajeer\Logs\Log;

use Cajeer\Logs\Http\HttpException;
use stdClass;

/** Bounded JSON grammar reader. Object keys are compared after Unicode escape decoding. */
final class StrictEventJson
{
    private int $offset=0;
    private int $nodes=0;
    private readonly int $length;
    private function __construct(private readonly string $body) { $this->length=strlen($body); }

    public static function decode(string $body): mixed
    {
        if (strlen($body)>IngestValidator::MAX_BODY_BYTES) throw new HttpException(413,'payload_too_large','Ingest body exceeds 1 MiB.');
        $parser=new self($body);
        $value=$parser->value(0,[],false);
        $parser->space();
        if ($parser->offset!==$parser->length) self::malformed();
        return $value;
    }

    private function space(): void { $this->offset+=strspn($this->body," \t\r\n",$this->offset); }
    private function take(string $char): bool
    {
        $this->space();
        if (($this->body[$this->offset]??'')!==$char) return false;
        ++$this->offset; return true;
    }
    private static function malformed(): never { throw new HttpException(400,'invalid_json','Malformed JSON request body.',details:['reason'=>'malformed_json']); }
    private static function limit(): never { throw new HttpException(422,'invalid_payload','JSON structure exceeds validation limits.',details:['reason'=>'structure_limit']); }

    private function string(): string
    {
        $this->space(); $start=$this->offset;
        if (($this->body[$this->offset++]??'')!=='"') self::malformed();
        while ($this->offset<$this->length) {
            $this->offset+=strcspn($this->body,"\"\\",$this->offset);
            if ($this->offset >= $this->length) self::malformed();
            if ($this->body[$this->offset++]==='"') {
                try { return json_decode(substr($this->body,$start,$this->offset-$start),false,2,JSON_THROW_ON_ERROR); }
                catch (\JsonException) { self::malformed(); }
            }
            // Skip the escaped byte; json_decode validates the entire escape sequence.
            ++$this->offset;
        }
        self::malformed();
    }

    private function finishObject(stdClass $object,array $path,int $start): stdClass
    {
        $index=count($path)===2 && $path[0]==='events' && is_int($path[1]) ? $path[1] : null;
        if (($index!==null || ($path===[] && !property_exists($object,'events'))) && $this->offset-$start>IngestValidator::MAX_EVENT_BYTES) {
            throw new HttpException(413,'event_too_large','Event exceeds size limit.',details:['event_index'=>$index??0,'reason'=>'event_size']);
        }
        return $object;
    }

    private function value(int $depth,array $path,bool $context): mixed
    {
        try { return $this->readValue($depth,$path,$context); }
        catch (HttpException $e) {
            // Only expose schema field names, never arbitrary JSON keys or values.
            $index=null; $field=null;
            if (count($path)>=2 && $path[0]==='events' && is_int($path[1])) { $index=$path[1]; $field=$path[2]??null; }
            elseif ($path!==[] && $path[0]!=='events') { $index=0; $field=$path[0]; }
            if ($index===null || isset($e->details['event_index'])) throw $e;
            $details=['event_index'=>$index]+$e->details;
            if (in_array($field,['timestamp','source','level','message','context','service','host','trace_id','request_id','tags'],true)) $details['field']=$field;
            throw new HttpException($e->status,$e->error,$e->getMessage(),$e->headers,$details);
        }
    }

    private function readValue(int $depth,array $path,bool $context): mixed
    {
        if ($depth>27 || ++$this->nodes>200000) self::limit();
        $this->space(); $c=$this->body[$this->offset]??'';
        if ($c==='"') return $this->string();
        if ($c==='{') {
            $start=$this->offset++; $out=new stdClass(); $seen=[];
            if ($this->take('}')) return $this->finishObject($out,$path,$start);
            do {
                $key=$this->string();
                if (isset($seen[$key])) throw new HttpException(400,'invalid_json','Duplicate JSON object key.',details:['reason'=>'duplicate_key']);
                $seen[$key]=true;
                if (count($seen)>IngestValidator::MAX_CONTAINER_ITEMS) self::limit();
                if (str_contains($key,"\0")) throw new HttpException(422,'invalid_payload','Invalid JSON key.',details:['reason'=>'invalid_key']);
                if (!$this->take(':')) self::malformed();
                $isContext=$context || ($key==='context' && ($path===[] || $path===['event'] || (count($path)===2 && $path[0]==='events' && is_int($path[1]))));
                $out->{$key}=$this->value($depth+1,[...$path,$key],$isContext);
                if ($this->take('}')) return $this->finishObject($out,$path,$start);
            } while ($this->take(','));
            self::malformed();
        }
        if ($c==='[') {
            ++$this->offset; $out=[];
            if ($this->take(']')) return $out;
            do {
                if ($path===['events'] && count($out)>=IngestValidator::MAX_BATCH) throw new HttpException(422,'invalid_payload','events must contain 1..100 objects.',details:['reason'=>'batch_size']);
                if (count($out)>=IngestValidator::MAX_CONTAINER_ITEMS) self::limit();
                $out[]=$this->value($depth+1,[...$path,count($out)],$context);
                if ($this->take(']')) return $out;
            } while ($this->take(','));
            self::malformed();
        }
        foreach (['true'=>true,'false'=>false,'null'=>null] as $literal=>$value) {
            if (substr_compare($this->body,$literal,$this->offset,strlen($literal))===0) { $this->offset+=strlen($literal); return $value; }
        }
        if (!preg_match('/\G-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/',$this->body,$m,0,$this->offset)) self::malformed();
        $number=$m[0]; $this->offset+=strlen($number);
        if (strlen($number)>1024) self::limit();
        $value=json_decode($number,false,2,JSON_THROW_ON_ERROR | ($context?JSON_BIGINT_AS_STRING:0));
        if (is_float($value)) {
            if (!is_finite($value)) throw new HttpException(422,'invalid_payload','Event contains an unsupported JSON number.',details:['reason'=>'invalid_number']);
            $mantissa=preg_split('/[eE]/',$number,2)[0];
            $digits=trim(str_replace(['-','.'],'',$mantissa),'0');
            // Preserve decimal precision in context rather than silently rounding to binary64.
            if ($context && (strlen($digits)>15 || ($value==0.0 && $digits!==''))) return $number;
        }
        return $value;
    }
}
