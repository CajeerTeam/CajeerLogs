<?php
declare(strict_types=1);
namespace Cajeer\Logs\Observability;
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Operations\BackupOperations;

final class WorkerMetrics
{
    public function __construct(private readonly Application $app) {}
    public function render(): string
    {
        $workers=(new WorkerHeartbeat($this->app->basePath('storage/cache')))->snapshot();
        $out="";
        foreach (['worker_last_success_timestamp_seconds','worker_stale_after_seconds','worker_healthy','worker_expected'] as $metric) {
            $out.="# TYPE cajeerlogs_$metric gauge\n";
            foreach ($workers as $role=>$w) {
                $value=match($metric) {
                    'worker_last_success_timestamp_seconds'=>$w['last_success_timestamp'],
                    'worker_stale_after_seconds'=>$w['stale_after_seconds'],
                    'worker_healthy'=>$w['status']==='ok'?1:0,
                    'worker_expected'=>$role!=='backup'||$this->app->config()->get('observability.monitor_backup')?1:0,
                };
                $out.='cajeerlogs_'.$metric.'{role="'.$role.'"} '.$value."\n";
            }
        }
        // Local storage remains observable even during a PostgreSQL outage.
        $free=is_dir($this->app->basePath('storage'))?@disk_free_space($this->app->basePath('storage')):false;
        if ($free!==false) {
            $threshold=$this->app->config()->get('operations.storage_warn_bytes');
            $out.="# TYPE cajeerlogs_app_storage_free_bytes gauge\ncajeerlogs_app_storage_free_bytes ".(float)$free."\n# TYPE cajeerlogs_app_storage_low_space gauge\ncajeerlogs_app_storage_low_space ".($threshold>0&&$free<$threshold?1:0)."\n";
        }
        $available=0;
        try {
            $b=(new BackupOperations($this->app))->status(); $available=1;
            $values=['backup_last_success_timestamp_seconds'=>$b['last_success_at']?strtotime($b['last_success_at']):0,'backup_interval_seconds'=>$b['interval_seconds'],'backup_offsite_configured'=>$b['offsite_configured']?1:0,'backup_offsite_verified'=>$b['last_offsite_verified']?1:0,'backup_replication_pending'=>$b['replication_pending']?1:0,'restore_last_success_timestamp_seconds'=>$b['last_restore_at']?strtotime($b['last_restore_at']):0];
            if ($b['last_success_seconds']!==null) $values['backup_duration_seconds']=$b['last_success_seconds'];
            if ($b['last_restore_seconds']!==null) $values['restore_duration_seconds']=$b['last_restore_seconds'];
            foreach ($values as $name=>$value) $out.="# TYPE cajeerlogs_$name gauge\ncajeerlogs_$name ".(float)$value."\n";
        } catch (\Throwable) {}
        return $out."# TYPE cajeerlogs_backup_status_available gauge\ncajeerlogs_backup_status_available $available\n";
    }
}
