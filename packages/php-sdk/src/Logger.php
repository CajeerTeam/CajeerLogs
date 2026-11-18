<?php
declare(strict_types=1);
namespace Cajeer\Logs\Sdk;

/** PSR-3 logger: synchronous by default, opt-in bounded durable batching. */
final class Logger extends \Psr\Log\AbstractLogger
{
    public function __construct(
        private readonly CajeerLogsClient $client,
        private readonly string $source,
        private readonly ?string $service = null,
        private readonly ?DurableBatchDelivery $delivery = null,
    ) {}

    public static function normalizeContext(array $context): \stdClass
    {
        return (object)self::normalize($context,0);
    }

    private static function normalize(mixed $value,int $depth): mixed
    {
        if ($depth>12) return '[depth limit]';
        if ($value instanceof \Throwable) return ['type'=>$value::class,'message'=>$value->getMessage(),'code'=>$value->getCode()];
        if ($value instanceof \DateTimeInterface) return $value->format('Y-m-d\TH:i:s.uP');
        if ($value instanceof \BackedEnum) return $value->value;
        if (is_object($value)) return $value::class;
        if (is_resource($value)) return '[resource]';
        if (is_array($value)) return array_map(static fn($v)=>self::normalize($v,$depth+1),$value);
        if (is_float($value) && !is_finite($value)) return '[non-finite number]';
        return $value;
    }

    /** PSR-3 placeholder substitution never invokes arbitrary context serialization. */
    private static function interpolate(string $message,array $context): string
    {
        if (!str_contains($message,'{')) return $message;
        $replace=[];
        foreach ($context as $key=>$value) {
            if (!is_string($key) && !is_int($key)) continue;
            if (is_string($value) || is_int($value) || is_float($value)) $replace['{'.$key.'}']=(string)$value;
            elseif (is_bool($value)) $replace['{'.$key.'}']=$value?'true':'false';
            elseif ($value===null) $replace['{'.$key.'}']='';
            elseif ($value instanceof \DateTimeInterface) $replace['{'.$key.'}']=$value->format(\DateTimeInterface::ATOM);
            elseif ($value instanceof \Stringable) {
                try { $replace['{'.$key.'}']=(string)$value; } catch (\Throwable) { /* PSR-3 context values must not cause interpolation failures. */ }
            }
        }
        return strtr($message,$replace);
    }

    public function log($level,string|\Stringable $message,array $context=[]): void
    {
        if (!in_array($level,['debug','info','notice','warning','error','critical','alert','emergency'],true)) {
            throw new \Psr\Log\InvalidArgumentException('Invalid PSR-3 level.');
        }
        $original=(string)$message;
        $event=['timestamp'=>(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),
            'source'=>$this->source,'level'=>$level,'message'=>self::interpolate($original,$context),'context'=>self::normalizeContext($context)];
        if ($this->service!==null) $event['service']=$this->service;
        if ($this->delivery!==null) $this->delivery->enqueue($event);
        else $this->client->ingest($event);
    }

    /** Explicit flush is required for partially-filled batches in long-running processes. */
    public function flush(): int { return $this->delivery?->flush() ?? 0; }
}
