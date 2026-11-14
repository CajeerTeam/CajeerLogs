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
    public const TASKS=['retention'=>60,'auth_cleanup'=>300,'queue_cleanup'=>3600,'webhook_cleanup'=>3600];
    public function __construct(private readonly PDO $pdo) {}
    public function due(): array {
        $rows=$this->pdo->query('SELECT name FROM scheduler_runs WHERE next_run_at>clock_timestamp()')->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_diff(array_keys(self::TASKS),$rows));
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
                        'retention'=>['removed'=>(new Retention($this->pdo))->prune()],
                        'auth_cleanup'=>(new Maintenance($this->pdo))->authCleanup(),
                        'queue_cleanup'=>['removed'=>(new PostgresQueueDriver($this->pdo))->cleanup()],
                        'webhook_cleanup'=>['removed'=>(new WebhookOutbox($this->pdo))->cleanup()],
                    };
                    $s=$this->pdo->prepare("INSERT INTO scheduler_runs(name,next_run_at,last_success_at,last_result) VALUES (:task,clock_timestamp()+CAST(:seconds AS integer)*interval '1 second',clock_timestamp(),CAST(:result AS jsonb)) ON CONFLICT(name) DO UPDATE SET next_run_at=EXCLUDED.next_run_at,last_success_at=EXCLUDED.last_success_at,last_result=EXCLUDED.last_result");
                    $s->execute(['task'=>$task,'seconds'=>self::TASKS[$task],'result'=>json_encode($result,JSON_THROW_ON_ERROR)]);
                    return $result;
                }, maxAttempts: 3);
                if ($result!==null) $results[$task]=$result;
            } catch (\Throwable $e) {
                if (!TransactionManager::usable($this->pdo)) throw $e;
                error_log('CajeerLogs scheduler task='.$task.' exception='.$e::class);
                $results[$task]=['error'=>'task_failed'];
            }
        }
        return $results;
    }
}
