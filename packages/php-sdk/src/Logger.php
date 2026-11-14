<?php
declare(strict_types=1);
namespace Cajeer\Logs\Sdk;

/** PSR-3 adapter; synchronous delivery, exceptions are visible to caller. */
final class Logger extends \Psr\Log\AbstractLogger
{
    public function __construct(private readonly CajeerLogsClient $client, private readonly string $source, private readonly ?string $service = null) {}
    public static function normalizeContext(array $context): \stdClass
    {
        return (object)self::normalize($context,0);
    }
    private static function normalize(mixed $value,int $depth): mixed
    {
        if ($depth>12) { return '[depth limit]'; }
        if ($value instanceof \Throwable) { return ['type'=>$value::class,'message'=>$value->getMessage(),'code'=>$value->getCode()]; }
        if (is_object($value)) { return $value::class; }
        if (is_resource($value)) { return '[resource]'; }
        if (is_array($value)) { return array_map(static fn($v)=>self::normalize($v,$depth+1),$value); }
        if (is_float($value) && !is_finite($value)) { return '[non-finite number]'; }
        return $value;
    }
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (!in_array($level,['debug','info','notice','warning','error','critical','alert','emergency'],true)) { throw new \Psr\Log\InvalidArgumentException('Invalid PSR-3 level.'); }
        $event=['timestamp'=>(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),'source'=>$this->source,'level'=>$level,'message'=>(string)$message,'context'=>self::normalizeContext($context)];
        if ($this->service!==null) { $event['service']=$this->service; }
        $this->client->ingest($event);
    }
}
