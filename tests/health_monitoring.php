<?php
declare(strict_types=1);
use Cajeer\Logs\Observability\{WorkerHeartbeat,WorkerMetrics,Readiness,AuditFailureMetrics};
$app=require __DIR__.'/../core/bootstrap.php'; $app->boot();
$n=0;
function check(bool $condition,string $message): void { global $n; if (!$condition) throw new RuntimeException($message); ++$n; echo "PASS $message\n"; }
$dir=sys_get_temp_dir().'/cl-heartbeat-'.bin2hex(random_bytes(8)); mkdir($dir,0700);
try {
    $h=new WorkerHeartbeat($dir); $now=time();
    $snapshot=$h->snapshot($now); check(count($snapshot)===4&&$snapshot['queue']['status']==='never'&&$snapshot['backup']['last_success_timestamp']===0,'all roles present before first pass');
    check(scandir($dir)===['.','..'],'read-only snapshot creates no files');
    $h->record('queue'); $snapshot=$h->snapshot();
    check($snapshot['queue']['status']==='ok'&&abs($snapshot['queue']['last_success_timestamp']-$now)<=2,'real successful queue progress published');
    check((fileperms($dir.'/heartbeat-queue')&0777)===0600,'private heartbeat file');
    check((new WorkerHeartbeat($dir))->snapshot()['queue']['status']==='ok','shared by independent readers');
    check($h->snapshot($now+121+2)['queue']['status']==='stale','stopped worker becomes stale');
    $h->record('scheduler'); check($h->snapshot()['scheduler']['status']==='ok'&&$h->snapshot()['webhook']['status']==='never','role isolation');
    $lock=fopen($dir.'/heartbeat-webhook.lock','c+b'); chmod($dir.'/heartbeat-webhook.lock',0600); flock($lock,LOCK_EX); $h->record('webhook');
    check($h->snapshot()['webhook']['status']==='never','concurrent writer does not block or fabricate progress'); fclose($lock);
    $h->record('webhook'); check($h->snapshot()['webhook']['status']==='ok','retry after concurrent lock');
    file_put_contents($dir.'/heartbeat-backup','corrupt'); chmod($dir.'/heartbeat-backup',0600);
    check($h->snapshot()['backup']['status']==='unavailable','malformed marker fails closed');
    file_put_contents($dir.'/heartbeat-backup',(string)($now+1000)."\n"); check($h->snapshot()['backup']['status']==='unavailable','future clock marker not accepted');
    unlink($dir.'/heartbeat-backup'); symlink($dir.'/heartbeat-queue',$dir.'/heartbeat-backup');
    check($h->snapshot()['backup']['status']==='unavailable','linked marker rejected'); $h->record('backup'); check(is_link($dir.'/heartbeat-backup'),'unsafe marker not overwritten');
    $invalid=false; try {$h->record('unknown');} catch (InvalidArgumentException) {$invalid=true;} check($invalid,'unknown roles rejected');
    $r=new Readiness($app); $cfg=$app->config(); $before=$cfg->get('database.connect_attempts'); $r->factory(); check($cfg->get('database.connect_attempts')===$before,'probe factory preserves normal runtime configuration');
    // Inspect real exposition without modifying runtime state or relying on PostgreSQL.
    $metrics=(new WorkerMetrics($app))->render(); check(str_contains($metrics,'cajeerlogs_worker_healthy{role="queue"}')&&str_contains($metrics,'cajeerlogs_worker_healthy{role="backup"}'),'worker metric series always present');
    check(str_contains($metrics,'cajeerlogs_worker_expected{role="backup"} '.($cfg->get('observability.monitor_backup')?1:0)),'backup expectation follows configuration');
    check(str_contains($metrics,'cajeerlogs_backup_status_available '),'backup status availability emitted');
    $cfg->set('app.env','production'); $cfg->set('app.url','https://logs.example.test'); $cfg->set('operations.metrics_token','');
    $proof=(new \Cajeer\Logs\Operations\OperationsCheck($app))->run('invalid-id');
    check($proof['status']==='failed' && !$proof['checks']['production_metrics_protected'] && !$proof['checks']['database_readiness'] && !$proof['checks']['audit_integrity'] && !$proof['checks']['backup_archive_verified'],'operator check reports unavailable dependencies and invalid backup without success');
    check(!str_contains(json_encode($proof),'invalid-id'),'operator check does not echo unverified backup input');
    $audit=new AuditFailureMetrics($dir); check(str_contains($audit->render(),'cajeerlogs_audit_write_failures_total 0'),'initial audit failure counter');
    $audit->increment(); (new AuditFailureMetrics($dir))->increment(); check(str_contains($audit->render(),'cajeerlogs_audit_write_failures_total 2'),'audit failure counter shared and cumulative');
    file_put_contents($dir.'/audit-failures','invalid'); check(str_contains($audit->render(),'cajeerlogs_audit_metrics_available 0'),'invalid counter state is unavailable, not fabricated zero');

} finally { foreach (glob($dir.'/*')?:[] as $f) unlink($f); rmdir($dir); }
echo "Health monitoring: $n checks passed\n";
