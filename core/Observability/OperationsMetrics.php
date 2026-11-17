<?php
declare(strict_types=1);
namespace Cajeer\Logs\Observability;
use Cajeer\Logs\Kernel\Application;
use PDO;

final class OperationsMetrics
{
    public function __construct(private readonly Application $app) {}
    /** Bounded labels; no log contents, project IDs, secrets or URLs. */
    public function snapshot(?PDO $probe=null): array
    {
        $pdo=$probe??$this->app->container()->get(PDO::class);
        $row=$pdo->query("SELECT pg_total_relation_size('log_events'::regclass) AS log_storage_bytes, COALESCE((SELECT GREATEST(0,extract(epoch from now()-min(created_at))) FROM jobs WHERE queue='ingest-v1' AND status='pending'),0) AS queue_oldest_pending_seconds, (SELECT count(*) FROM jobs WHERE queue='ingest-v1' AND status='running' AND lease_until<=now()) AS expired_leases, (SELECT count(*) FROM webhook_deliveries WHERE status='failed') AS webhook_failed, (SELECT count(*) FROM webhook_deliveries WHERE status IN ('pending','sending')) AS webhook_pending")->fetch();
        $values=[]; foreach ($row as $k=>$v) { $values[$k]=(float)$v; }
        $dead=$pdo->query("SELECT count(*) AS queue_dead_jobs,COALESCE(sum(payload_events),0) AS queue_dead_events,COALESCE(sum(payload_bytes),0) AS queue_dead_bytes,COALESCE(GREATEST(0,extract(epoch from now()-min(completed_at))),0) AS queue_oldest_dead_seconds FROM jobs WHERE queue='ingest-v1' AND status='dead'")->fetch();
        foreach ($dead as $name=>$value) $values[$name]=(float)$value;
        $capacity=(new \Cajeer\Logs\Queue\QueueAccounting($pdo))->status();
        foreach ($capacity as $name=>$value) $values['queue_'.$name]=(float)$value;
        $policy=\Cajeer\Logs\Queue\BackpressurePolicy::fromConfig($this->app->config());
        foreach ($policy->limits() as $name=>$value) $values['queue_'.$name]=(float)$value;
        $ratios=[];
        foreach (['jobs'=>'maxJobs','events'=>'maxEvents','bytes'=>'maxBytes'] as $resource=>$field) {
            $limit=$policy->$field;
            if ($limit>0) $ratios[]=$capacity['active_'.$resource]/$limit;
        }
        $values['queue_capacity_ratio']=max($ratios);
        $tasks=array_fill_keys(array_keys(\Cajeer\Logs\Scheduler\Scheduler::TASKS),['last_success'=>0,'overdue_seconds'=>0]); foreach ($pdo->query('SELECT name,extract(epoch from last_success_at) AS last_success, GREATEST(0,extract(epoch from now()-next_run_at)) AS overdue_seconds FROM scheduler_runs')->fetchAll() as $task) { if (isset(\Cajeer\Logs\Scheduler\Scheduler::TASKS[$task['name']])) { $tasks[$task['name']]=['last_success'=>(float)$task['last_success'],'overdue_seconds'=>(float)$task['overdue_seconds']]; } }
        $retention=$pdo->query('SELECT status,total_removed,backlog_lower_bound,duration_ms,extract(epoch from finished_at) AS finished_at FROM retention_runs ORDER BY id DESC LIMIT 1')->fetch();
        $storage=$pdo->query("SELECT n_live_tup AS estimated_live_rows,n_dead_tup AS estimated_dead_rows,last_autovacuum,last_autoanalyze FROM pg_stat_user_tables WHERE schemaname=current_schema() AND relname='log_events'")->fetch();
        if ($storage) { $values['log_estimated_live_rows']=(float)$storage['estimated_live_rows']; $values['log_estimated_dead_rows']=(float)$storage['estimated_dead_rows']; }
        $free=is_dir($this->app->basePath('storage'))?@disk_free_space($this->app->basePath('storage')):false;
        if ($free!==false) { $values['app_storage_free_bytes']=(float)$free; $values['app_storage_low_space']=$this->app->config()->get('operations.storage_warn_bytes')>0&&$free<$this->app->config()->get('operations.storage_warn_bytes')?1:0; }
        $values['retention_scheduler_enabled']=$this->app->config()->get('operations.retention_scheduler_enabled')?1:0;
        $values['retention_window_open']=\Cajeer\Logs\Lifecycle\Retention::windowOpen($this->app->config()->get('operations.retention_window_utc'))?1:0;
        return ['database'=>$values,'scheduler'=>$tasks,'retention'=>$retention?:null,'log_storage'=>$storage?:null];
    }
    public function render(?PDO $probe=null): string
    {
        try { $snapshot=$this->snapshot($probe); } catch (\Throwable) { return "# TYPE cajeerlogs_operations_available gauge\ncajeerlogs_operations_available 0\n"; }
        $out="# TYPE cajeerlogs_operations_available gauge\ncajeerlogs_operations_available 1\n";
        foreach ($snapshot['database'] as $name=>$value) { if ($probe!==null && str_starts_with($name,'app_storage_')) continue; $out.="# TYPE cajeerlogs_$name gauge\ncajeerlogs_$name $value\n"; }
        $out.="# TYPE cajeerlogs_queue_admission_rejections_total counter\n";
        foreach (['jobs','events','bytes','paused'] as $cause) {
            $total=(int)($snapshot['database']['queue_rejected_'.$cause]??0);
            $out.='cajeerlogs_queue_admission_rejections_total{reason="'.$cause.'"} '.$total."\n";
        }
        foreach (['scheduler_last_success_timestamp_seconds'=>'last_success','scheduler_overdue_seconds'=>'overdue_seconds'] as $metric=>$field) {
            $out.="# TYPE cajeerlogs_$metric gauge\n";
            foreach ($snapshot['scheduler'] as $name=>$task) $out.='cajeerlogs_'.$metric.'{task="'.$name.'"} '.$task[$field]."\n";
        }
        $r=$snapshot['retention'];
        $out.="# TYPE cajeerlogs_retention_deleted_events_total counter\ncajeerlogs_retention_deleted_events_total ".(int)($r['total_removed']??0)."\n# TYPE cajeerlogs_retention_last_run_timestamp_seconds gauge\ncajeerlogs_retention_last_run_timestamp_seconds ".(float)($r['finished_at']??0)."\n# TYPE cajeerlogs_retention_last_run_success gauge\ncajeerlogs_retention_last_run_success ".(($r['status']??'')==='success'?1:0)."\n";
        if ($r && $r['duration_ms']!==null) $out.="# TYPE cajeerlogs_retention_last_run_duration_seconds gauge\ncajeerlogs_retention_last_run_duration_seconds ".((float)$r['duration_ms']/1000)."\n";
        if ($r && $r['backlog_lower_bound']!==null) $out.="# TYPE cajeerlogs_retention_backlog_lower_bound gauge\ncajeerlogs_retention_backlog_lower_bound ".(int)$r['backlog_lower_bound']."\n";
        return $out;
    }
}
