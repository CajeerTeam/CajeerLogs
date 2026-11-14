<?php
declare(strict_types=1);
namespace Cajeer\Logs\Queue;

final class Worker
{
    private bool $stop=false;
    public function __construct(private readonly PostgresQueueDriver $queue) {}
    public function stop(): void { $this->stop=true; }
    /** Limit 0 means run until SIGTERM/SIGINT; finite batches never sleep when empty. */
    public function run(int $limit=0): array {
        if ($limit<0 || $limit>10000) { throw new \InvalidArgumentException('Invalid worker limit.'); }
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn()=> $this->stop());
            pcntl_signal(SIGINT, fn()=> $this->stop());
        } elseif ($limit===0) { throw new \RuntimeException('Continuous worker requires ext-pcntl; use a finite batch.'); }
        $counts=array_fill_keys(['completed','retry','dead','stale','skipped'],0);$processed=0;
        while (!$this->stop && ($limit===0 || $processed<$limit)) {
            $claim=$this->queue->claim();
            if ($claim===null) { if ($limit!==0) break; usleep(500000);continue; }
            $outcome=isset($claim['skipped'])?'skipped':$this->queue->process($claim);
            $counts[$outcome]++;$processed++;
            if ($limit===0 && $outcome!=='completed') { fwrite(STDERR,'Queue job outcome: '.$outcome.PHP_EOL); }
        }
        return $counts;
    }
}
