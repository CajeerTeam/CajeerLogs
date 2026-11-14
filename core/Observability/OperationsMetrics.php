<?php
declare(strict_types=1);
namespace Cajeer\Logs\Observability;
use Cajeer\Logs\Kernel\Application;
use PDO;

final class OperationsMetrics
{
    public function __construct(private readonly Application $app) {}
    /** Bounded labels; no log contents, project IDs, secrets or URLs. */
    public function snapshot(): array
    {
        $pdo=$this->app->container()->get(PDO::class);
        $row=$pdo->query("SELECT pg_total_relation_size('log_events'::regclass) AS log_storage_bytes, COALESCE((SELECT GREATEST(0,extract(epoch from now()-min(created_at))) FROM jobs WHERE queue='ingest-v1' AND status='pending'),0) AS queue_oldest_pending_seconds, (SELECT count(*) FROM jobs WHERE queue='ingest-v1' AND status='running' AND lease_until<=now()) AS expired_leases, (SELECT count(*) FROM webhook_deliveries WHERE status='failed') AS webhook_failed, (SELECT count(*) FROM webhook_deliveries WHERE status IN ('pending','sending')) AS webhook_pending")->fetch();
        $values=[]; foreach ($row as $k=>$v) { $values[$k]=(float)$v; }
        $tasks=[]; foreach ($pdo->query('SELECT name,extract(epoch from last_success_at) AS last_success, GREATEST(0,extract(epoch from now()-next_run_at)) AS overdue_seconds FROM scheduler_runs')->fetchAll() as $task) { if (isset(\Cajeer\Logs\Scheduler\Scheduler::TASKS[$task['name']])) { $tasks[$task['name']]=['last_success'=>(float)$task['last_success'],'overdue_seconds'=>(float)$task['overdue_seconds']]; } }
        return ['database'=>$values,'scheduler'=>$tasks];
    }
    public function render(): string
    {
        try { $snapshot=$this->snapshot(); } catch (\Throwable) { return "# TYPE cajeerlogs_operations_available gauge\ncajeerlogs_operations_available 0\n"; }
        $out="# TYPE cajeerlogs_operations_available gauge\ncajeerlogs_operations_available 1\n";
        foreach ($snapshot['database'] as $name=>$value) { $out.="# TYPE cajeerlogs_$name gauge\ncajeerlogs_$name $value\n"; }
        $out.="# TYPE cajeerlogs_scheduler_last_success_timestamp_seconds gauge\n# TYPE cajeerlogs_scheduler_overdue_seconds gauge\n";
        foreach ($snapshot['scheduler'] as $name=>$task) { $out.='cajeerlogs_scheduler_last_success_timestamp_seconds{task="'.$name.'"} '.$task['last_success']."\n".'cajeerlogs_scheduler_overdue_seconds{task="'.$name.'"} '.$task['overdue_seconds']."\n"; }
        return $out;
    }
}
