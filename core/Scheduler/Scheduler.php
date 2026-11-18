<?php
declare(strict_types=1);
namespace Cajeer\Logs\Scheduler;

use Cajeer\Logs\Database\TransactionManager;
use PDO;
use Cajeer\Logs\Lifecycle\{Retention,Maintenance};
use Cajeer\Logs\Queue\PostgresQueueDriver;
use Cajeer\Logs\Integration\WebhookOutbox;

/** Fixed maintenance tasks, no shell commands or untrusted task definitions. */
final class Scheduler
{
    public const TASKS=['retention'=>60,'auth_cleanup'=>300,'queue_cleanup'=>3600,'webhook_cleanup'=>3600,'source_metrics_cleanup'=>300];
    public function __construct(private readonly PDO $pdo, private readonly int $idle=0, private readonly int $batch=1000, private readonly int $timeout=5000, private readonly int $queueFloor=0, private readonly int $webhookDays=30, private readonly bool $retentionEnabled=true, private readonly string $retentionWindow='') {}
    public function due(): array {
        $rows=$this->pdo->query('SELECT name FROM scheduler_runs WHERE next_run_at>clock_timestamp()')->fetchAll(PDO::FETCH_COLUMN);
        $tasks=array_values(array_diff(array_keys(self::TASKS),$rows));
        if (!$this->retentionEnabled || !Retention::windowOpen($this->retentionWindow)) $tasks=array_values(array_diff($tasks,['retention']));
        return $tasks;
    }
    public function run(): array {
        $results=[];
        foreach ($this->due() as $task) {
            try {
                $result=(new TransactionManager($this->pdo))->run(function () use ($task) {
                    $lock=$this->pdo->prepare("SELECT pg_try_advisory_xact_lock(hashtextextended('cajeerlogs:scheduler:' || current_schema() || ':' || :task,0))");
                    $lock->execute(['task'=>$task]);
                    if (!$lock->fetchColumn() || !in_array($task,$this->due(),true)) { return null; }
                    $result=match($task) {
                        'retention'=>['removed'=>(new Retention($this->pdo,$this->timeout))->prune($this->batch)],
                        'auth_cleanup'=>(new Maintenance($this->pdo,$this->idle))->authCleanup(),
                        'queue_cleanup'=>['removed'=>(new PostgresQueueDriver($this->pdo))->cleanup($this->queueFloor)],
                        'webhook_cleanup'=>['removed'=>(new WebhookOutbox($this->pdo))->cleanup($this->webhookDays)],
                        'source_metrics_cleanup'=>['removed'=>(new \Cajeer\Logs\Database\Repository\SourceMonitoring($this->pdo))->prune()],
                    };
                    $s=$this->pdo->prepare("INSERT INTO scheduler_runs(name,next_run_at,last_success_at,last_result) VALUES (:task,clock_timestamp()+CAST(:seconds AS integer)*interval '1 second',clock_timestamp(),CAST(:result AS jsonb)) ON CONFLICT(name) DO UPDATE SET next_run_at=EXCLUDED.next_run_at,last_success_at=EXCLUDED.last_success_at,last_result=EXCLUDED.last_result");
                    $s->execute(['task'=>$task,'seconds'=>self::TASKS[$task],'result'=>json_encode($result,JSON_THROW_ON_ERROR)]);
                    return $result;
                }, maxAttempts: 3);
                if ($result!==null) $results[$task]=$result;
            } catch (\Throwable $e) {
                if (!TransactionManager::usable($this->pdo)) throw $e;
                if ($task==='retention') (new Retention($this->pdo,$this->timeout))->failure($this->batch);
                error_log('CajeerLogs scheduler task='.$task.' exception='.$e::class);
                $results[$task]=['error'=>'task_failed'];
            }
        }
        return $results;
    }
}
