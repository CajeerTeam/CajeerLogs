<?php
declare(strict_types=1);
namespace Cajeer\Logs\Queue;

use Cajeer\Logs\Database\{ConnectionManager,DatabaseException,DatabaseFailure,MigrationRunner,TransactionManager};

final class Worker
{
    private bool $stop=false;
    public function __construct(private readonly PostgresQueueDriver|ConnectionManager $connection, private readonly int $maxJobs=1000, private readonly ?string $migrationDirectory=null, private readonly ?string $schema=null, private readonly ?\Closure $heartbeat=null, private readonly ?WorkerPolicy $policy=null, private readonly ?WorkerRegistry $registry=null, private readonly RetryPolicy $retryPolicy=new RetryPolicy(), private readonly ?\Closure $recoveryCheck=null) {}
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
                $guard=null;$delay=0;unset($pdo,$claims,$queue);
                try {
                    if ($this->connection instanceof ConnectionManager) {
                        $pdo=$this->connection->connection();
                        if ($this->migrationDirectory!==null && $this->schema!==null) $guard=(new MigrationRunner($pdo,$this->migrationDirectory,$this->schema))->guard();
                        // After an outage, do not claim another job until the database and
                        // schema pass the same runtime readiness checks used by operators.
                        if ($failures>0 && $this->recoveryCheck!==null && !($this->recoveryCheck)($pdo)) throw new DatabaseException('Queue recovery preflight failed.',0,null,'configuration');
                        $queue=new PostgresQueueDriver($pdo,$this->maxJobs,$policy,$this->retryPolicy);
                    } else $queue=$this->connection;
                    if ($this->stop) break;
                    // Do not claim past a finite CLI limit or a continuous worker's
                    // recycling boundary. Each claimed job has its own durable lease.
                    $batchSize=$policy->get('batch_jobs');
                    if ($limit!==0) $batchSize=min($batchSize,$limit-$processed);
                    elseif ($policy->get('max_jobs')>0) $batchSize=min($batchSize,$policy->get('max_jobs')-$processed);
                    $batchSize=max(1,$batchSize);
                    $claims=$batchSize>1?$queue->claimBatch($batchSize):(($one=$queue->claim())===null?[]:[$one]);
                    if ($claims===[]) {
                        $failures=0;if ($this->heartbeat!==null) ($this->heartbeat)();$this->registry?->progress($processed);
                        if ($limit!==0) break;
                        $idle=$policy->idleDelay($idle);$delay=random_int(max($policy->get('poll_min_ms'),(int)ceil($idle/2)),$idle);
                    } else {
                        // If a signal arrived during claim, complete ALL owned jobs.
                        $outcomes=count($claims)>1?$queue->processBatch($claims):[isset($claims[0]['skipped'])?'skipped':$queue->process($claims[0])];
                        if (count($outcomes)!==count($claims)) throw new \LogicException('Worker batch outcome count mismatch.');
                        foreach ($outcomes as $outcome) {
                            $counts[$outcome]++;$processed++;
                            if ($limit===0 && $outcome!=='completed') fwrite(STDERR,'Queue job outcome: '.$outcome.PHP_EOL);
                        }
                        $failures=0;$idle=0;
                        if ($this->heartbeat!==null) ($this->heartbeat)();$this->registry?->progress($processed);
                    }
                } catch (\Throwable $error) {
                    $decision=RetryPolicy::classify($error);
                    if (!$this->connection instanceof ConnectionManager || (!DatabaseFailure::unavailable($error) && $decision['action']!=='pause' && (!isset($pdo) || TransactionManager::usable($pdo)))) throw $error;
                    // Durable job state is authoritative. Never replay uncertain claim/process/COMMIT.
                    unset($queue,$claims,$guard,$pdo);$this->connection->invalidate();
                    if ($limit!==0 || ++$failures>=$this->retryPolicy->get('outage_max_failures')) throw $error;
                    fwrite(STDERR,'Queue database unavailable: '.$decision['reason'].'; reconnecting at next operation boundary.'.PHP_EOL);
                    $delay=$this->retryPolicy->outageDelay($failures);
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
