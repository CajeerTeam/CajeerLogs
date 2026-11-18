<?php
declare(strict_types=1);
namespace Cajeer\Logs\Queue;
use Cajeer\Logs\Config\ConfigRepository;

/** Limits apply to continuous workers; finite queue:work N retains its existing semantics. */
final class WorkerPolicy
{
    public const DEFAULTS=['processes'=>2,'batch_jobs'=>4,'max_jobs'=>1000,'max_seconds'=>3600,'memory_mb'=>256,'poll_min_ms'=>50,'poll_max_ms'=>1000,'lease_seconds'=>120,'claim_timeout_ms'=>5000,'process_timeout_seconds'=>90,'shutdown_grace_seconds'=>120];
    private const RANGES=['processes'=>[1,32],'batch_jobs'=>[1,16],'max_jobs'=>[0,1000000],'max_seconds'=>[0,86400],'memory_mb'=>[0,4096],'poll_min_ms'=>[10,5000],'poll_max_ms'=>[10,10000],'lease_seconds'=>[10,3600],'claim_timeout_ms'=>[100,60000],'process_timeout_seconds'=>[1,3500],'shutdown_grace_seconds'=>[1,3600]];
    public readonly array $values;
    public function __construct(array $values=[])
    {
        $validated=[];
        foreach (self::RANGES as $key=>[$min,$max]) {
            $raw=$values[$key]??self::DEFAULTS[$key];
            $value=is_bool($raw)?false:filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>$min,'max_range'=>$max]]);
            if ($value===false) throw new \InvalidArgumentException('Invalid QUEUE_WORKER configuration: '.$key);
            $validated[$key]=$value;
        }
        if ($validated['poll_min_ms']>$validated['poll_max_ms'] || $validated['process_timeout_seconds']+5>$validated['lease_seconds'] || $validated['shutdown_grace_seconds']<$validated['process_timeout_seconds'] || $validated['claim_timeout_ms']>=($validated['lease_seconds']*1000)) throw new \InvalidArgumentException('Inconsistent queue polling, lease, timeout or drain limits.');
        if ($validated['memory_mb']!==0 && $validated['memory_mb']<32) throw new \InvalidArgumentException('Worker memory threshold must be zero or at least 32 MiB.');
        $this->values=$validated;
    }
    public static function fromConfig(ConfigRepository $config): self { return new self($config->get('queue.worker',[])); }
    public function get(string $key): int { return $this->values[$key]; }
    public function exhausted(int $processed,int $elapsedSeconds,int $memoryBytes): bool
    {
        return ($this->get('max_jobs')>0 && $processed >= $this->get('max_jobs')) || ($this->get('max_seconds')>0 && $elapsedSeconds >= $this->get('max_seconds')) || ($this->get('memory_mb')>0 && $memoryBytes >= $this->get('memory_mb')*1048576);
    }
    public function idleDelay(int $previous): int { return min($this->get('poll_max_ms'),max($this->get('poll_min_ms'),$previous===0?$this->get('poll_min_ms'):$previous*2)); }
    public function staleAfter(): int { return max(120,$this->get('lease_seconds')+30); }
}
