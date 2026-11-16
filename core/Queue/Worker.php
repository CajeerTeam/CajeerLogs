<?php
declare(strict_types=1);
namespace Cajeer\Logs\Queue;

use Cajeer\Logs\Database\{ConnectionFactory,ConnectionManager,DatabaseFailure,MigrationRunner};

final class Worker
{
    private bool $stop=false;
    public function __construct(private readonly PostgresQueueDriver|ConnectionManager $connection, private readonly int $maxJobs=1000, private readonly ?string $migrationDirectory=null, private readonly ?string $schema=null, private readonly ?\Closure $heartbeat=null, private readonly ?WorkerPolicy $policy=null, private readonly ?WorkerRegistry $registry=null) {}
    public function stop(): void { $this->stop=true; }
    private function sleep(int $ms): void {
        $end=hrtime(true)+$ms*1_000_000;
        while (!$this->stop && ($remaining=$end-hrtime(true))>0) usleep((int)min(100000,max(1,intdiv($remaining,1000))));
    }
    /** Limit 0 means continuous, subject to configured recycling limits. */
    public function run(int $limit=0): array {
        if ($limit<0 || $limit>10000) throw new \InvalidArgumentException('Invalid worker limit.');
        $signals=function_exists('pcntl_async_signals');
        if ($signals) {
            $oldAsync=pcntl_async_signals(true);$oldTerm=pcntl_signal_get_handler(SIGTERM);$oldInt=pcntl_signal_get_handler(SIGINT);
            pcntl_signal(SIGTERM,fn()=> $this->stop());pcntl_signal(SIGINT,fn()=> $this->stop());
        } elseif ($limit===0) throw new \RuntimeException('Continuous worker requires ext-pcntl; use a finite batch.');
        $parent=getenv('CAJEER_QUEUE_POOL_PARENT');
        $policy=$this->policy??new WorkerPolicy();$counts=array_fill_keys(['completed','retry','dead','stale','skipped'],0);$processed=0;$failures=0;$idle=0;$start=hrtime(true);
        if ($limit===0) $this->registry?->start($policy->staleAfter());
        try {
            while (!$this->stop && ($limit===0 || $processed<$limit)) {
                if ($limit===0 && is_string($parent) && ctype_digit($parent) && function_exists('posix_getppid') && posix_getppid()!==(int)$parent) break;
                // A time limit must also recycle a completely idle worker.
                if ($limit===0 && $policy->exhausted($processed,(int)((hrtime(true)-$start)/1e9),memory_get_usage(true))) break;
                $guard=null;$delay=0;
                try {
                    if ($this->connection instanceof ConnectionManager) {
                        $pdo=$this->connection->connection();
                        if ($this->migrationDirectory!==null && $this->schema!==null) $guard=(new MigrationRunner($pdo,$this->migrationDirectory,$this->schema))->guard();
                        $queue=new PostgresQueueDriver($pdo,$this->maxJobs,$policy);
                    } else $queue=$this->connection;
                    if ($this->stop) break;
                    $claim=$queue->claim();
                    if ($claim===null) {
                        $failures=0;if ($this->heartbeat!==null) ($this->heartbeat)();$this->registry?->progress($processed);
                        if ($limit!==0) break;
                        $idle=$policy->idleDelay($idle);$delay=random_int(max($policy->get('poll_min_ms'),(int)ceil($idle/2)),$idle);
                    } else {
                        // If a signal arrived during claim, finish that already-owned job before exit.
                        $outcome=isset($claim['skipped'])?'skipped':$queue->process($claim);
                        $counts[$outcome]++;$processed++;$failures=0;$idle=0;
                        if ($this->heartbeat!==null) ($this->heartbeat)();$this->registry?->progress($processed);
                        if ($limit===0 && $outcome!=='completed') fwrite(STDERR,'Queue job outcome: '.$outcome.PHP_EOL);
                    }
                } catch (\Throwable $error) {
                    if (!$this->connection instanceof ConnectionManager || !DatabaseFailure::reconnectable($error)) throw $error;
                    // Durable job state is authoritative. Never replay uncertain claim/process/COMMIT.
                    unset($queue,$claim,$guard,$pdo);$this->connection->invalidate();
                    if ($limit!==0 || ++$failures>=10) throw $error;
                    fwrite(STDERR,'Queue database unavailable: '.DatabaseFailure::category($error).'; reconnecting at next operation boundary.'.PHP_EOL);
                    $delay=ConnectionFactory::retryDelay(250,$failures);
                } finally {if (isset($guard)) $guard->release();}
                if ($delay>0) $this->sleep($delay);
            }
            return $counts;
        } finally {
            $this->registry?->close();
            if ($signals) {pcntl_signal(SIGTERM,$oldTerm);pcntl_signal(SIGINT,$oldInt);pcntl_async_signals($oldAsync);}
        }
    }
}
