<?php
declare(strict_types=1);
namespace Cajeer\Logs\Operations;

use Cajeer\Logs\Audit\AuditLogger;
use Cajeer\Logs\Database\MigrationRunner;
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Observability\{Diagnostics,Readiness,WorkerHeartbeat};
use Cajeer\Logs\Lifecycle\Retention;
use PDO;

/** Operator-only, read-only release/incident check. No implicit repair, backup or retention. */
final class OperationsCheck
{
    public function __construct(private readonly Application $app) {}

    public function run(?string $backupId=null): array
    {
        $cfg=$this->app->config(); $now=time(); $production=$cfg->get('app.env')==='production';
        $checks=['runtime'=>$this->app->container()->get(Diagnostics::class)->ok(),
            'database_readiness'=>(new Readiness($this->app))->check()['status']==='ok',
            'production_https'=>!$production || parse_url($cfg->get('app.url'),PHP_URL_SCHEME)==='https',
            'production_metrics_protected'=>!$production || !$cfg->get('observability.prometheus_enabled') || $cfg->get('operations.metrics_token')!=='',
            'production_admin_totp'=>!$production || $cfg->get('security.require_admin_totp'),
            'production_idle_timeout'=>!$production || $cfg->get('security.session_idle_seconds')>0,
            'production_recent_authentication'=>!$production || $cfg->get('security.reauth_seconds')>0,
            'audit_integrity'=>false,'queue_accounting'=>false];
        $skipped=[]; $audit=null; $backup=null; $verifiedBackup=null;
        $workers=(new WorkerHeartbeat($this->app->basePath('storage/cache')))->snapshot($now);
        foreach ($workers as $role=>$worker) {
            if ($role==='backup' && !$cfg->get('observability.monitor_backup')) { $skipped[]='worker_backup'; continue; }
            $checks['worker_'.$role]=$worker['status']==='ok';
        }
        $free=@disk_free_space($this->app->basePath('storage'));
        $checks['storage_headroom']=$free!==false && $free >= $cfg->get('operations.storage_warn_bytes');
        $checkRetention=$cfg->get('operations.retention_scheduler_enabled') && Retention::windowOpen($cfg->get('operations.retention_window_utc'),$now);
        if ($checkRetention) $checks['retention_progress']=false; else $skipped[]='retention_progress';
        if ($checks['database_readiness']) {
            $guard=null;
            try {
                $pdo=$this->app->container()->get(PDO::class);
                $guard=$this->app->container()->get(MigrationRunner::class)->guard();
                $a=(new AuditLogger($pdo))->verify();
                $audit=['valid'=>$a['valid'],'entries'=>$a['entries'],'head_position'=>$a['head_position'],'head_hash'=>$a['head_hash']];
                $checks['audit_integrity']=$a['valid'];
                $checks['queue_accounting']=(new \Cajeer\Logs\Queue\QueueAccounting($pdo))->verify()['valid'];
                if ($checkRetention) {
                    $r=$pdo->query('SELECT status,extract(epoch from finished_at) AS finished FROM retention_runs ORDER BY id DESC LIMIT 1')->fetch();
                    $checks['retention_progress']=$r && $r['status']==='success' && (float)$r['finished'] <= $now+5 && $now-(float)$r['finished']<=300;
                }
            } catch (\Throwable) { /* Return a failed check, not credentials or SQL error details. */ }
            finally { $guard?->release(); }
        }
        try {
            $ops=new BackupOperations($this->app); $backup=$ops->status();
            if ($cfg->get('observability.monitor_backup')) {
                $last=$backup['last_success_at']===null?false:strtotime($backup['last_success_at']);
                $restore=$backup['last_restore_at']===null?false:strtotime($backup['last_restore_at']);
                $checks['backup_fresh']=$last!==false && $last<=$now+5 && $now-$last<=$backup['interval_seconds']+1800 && !$backup['replication_pending'] && (!$backup['offsite_configured']||$backup['last_offsite_verified']);
                $checks['restore_rehearsal_fresh']=$restore!==false && $restore<=$now+5 && $now-$restore<=604800;
            } else $skipped[]='backup_freshness';
        } catch (\Throwable) { $checks['backup_status_readable']=false; }
        if ($backupId!==null) {
            $checks['backup_archive_verified']=false;
            try {
                $meta=(new BackupManager($this->app))->verify($backupId);
                $verifiedBackup=['id'=>$meta['id'],'sha256'=>$meta['sha256'],'version'=>$meta['version']];
                $checks['backup_archive_verified']=BackupManager::compatibleRelease($meta['version'],$this->app->version())
                    && $meta['schema']===$this->app->container()->get(\Cajeer\Logs\Database\ConnectionFactory::class)->schema();
                if ($cfg->get('operations.backup_offsite_dir')!=='') {
                    $checks['backup_offsite_verified']=false;
                    $remote=(new BackupOperations($this->app))->offsiteVerify($backupId);
                    $checks['backup_offsite_verified']=hash_equals($meta['sha256'],$remote['sha256']) && $meta['version']===$remote['version'] && $meta['schema']===$remote['schema'];
                }
            } catch (\Throwable) { /* A requested archive is never considered verified after an error. */ }
        } else $skipped[]='backup_archive_verified';
        return ['status'=>in_array(false,$checks,true)?'failed':'ok','version'=>$this->app->version(),'checks'=>$checks,'skipped'=>$skipped,
            'workers'=>$workers,'audit'=>$audit,'backup'=>$backup,'verified_backup'=>$verifiedBackup];
    }
}
