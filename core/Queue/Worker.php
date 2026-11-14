<?php
declare(strict_types=1);
namespace Cajeer\Logs\Queue;

use Cajeer\Logs\Database\{ConnectionFactory,ConnectionManager,DatabaseFailure};

final class Worker
{
    private bool $stop=false;
    // Direct driver remains supported for finite/test use. The production CLI owns a reconnectable session.
    public function __construct(private readonly PostgresQueueDriver|ConnectionManager $connection, private readonly int $maxJobs=1000) {}
    public function stop(): void { $this->stop=true; }
    /** Limit 0 means run until SIGTERM/SIGINT; finite batches never sleep when empty. */
    public function run(int $limit=0): array {
        if ($limit<0 || $limit>10000) { throw new \InvalidArgumentException('Invalid worker limit.'); }
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn()=> $this->stop());
            pcntl_signal(SIGINT, fn()=> $this->stop());
        } elseif ($limit===0) { throw new \RuntimeException('Continuous worker requires ext-pcntl; use a finite batch.'); }
        $counts=array_fill_keys(['completed','retry','dead','stale','skipped'],0);$processed=0;$failures=0;
        while (!$this->stop && ($limit===0 || $processed<$limit)) {
            try {
                $queue=$this->connection instanceof ConnectionManager ? new PostgresQueueDriver($this->connection->connection(),$this->maxJobs) : $this->connection;
                if ($this->stop) { break; }
                $claim=$queue->claim();
                if ($claim===null) { $failures=0; if ($limit!==0) break; usleep(500000);continue; }
                $outcome=isset($claim['skipped'])?'skipped':$queue->process($claim);
                $counts[$outcome]++;$processed++;$failures=0;
                if ($limit===0 && $outcome!=='completed') { fwrite(STDERR,'Queue job outcome: '.$outcome.PHP_EOL); }
            } catch (\Throwable $error) {
                if (!$this->connection instanceof ConnectionManager || !DatabaseFailure::reconnectable($error)) { throw $error; }
                // Drop all references to the old PDO. Never replay claim/process/COMMIT here.
                // If claim or completion committed, the durable job state is authoritative; otherwise lease recovery applies.
                unset($queue,$claim);
                $this->connection->invalidate();
                if ($limit!==0 || ++$failures>=10) { throw $error; }
                fwrite(STDERR,'Queue database unavailable: '.DatabaseFailure::category($error).'; reconnecting at next operation boundary.'.PHP_EOL);
                unset($error);
                if (!$this->stop) { usleep(ConnectionFactory::retryDelay(250,$failures)*1000); }
            }
        }
        return $counts;
    }
}
