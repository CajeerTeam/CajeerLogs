<?php
declare(strict_types=1);

use Cajeer\Logs\Operations\IncidentTriage;
use Cajeer\Logs\Operations\IncidentSnapshot;

require dirname(__DIR__) . '/core/bootstrap.php';

$count = 0;
function check(bool $value, string $message): void {
    global $count;
    if (!$value) throw new RuntimeException('FAIL '.$message);
    $count++; echo 'PASS '.$message.PHP_EOL;
}
function rejected(callable $callback): bool {
    try { $callback(); } catch (Throwable) { return true; }
    return false;
}
$time = '2026-11-19T10:00:00Z';
$source = ['version'=>'0.9.1','checks'=>[
    'runtime'=>true, 'database_readiness'=>false, 'queue_accounting'=>false,
    'worker_queue'=>false, 'restore_rehearsal_fresh'=>false, 'storage_headroom'=>true],
    'skipped'=>['retention_progress','backup_archive_verified','retention_progress']];
$report = IncidentTriage::classify($source, $time);
check($report['status']==='critical' && IncidentTriage::exitCode($report)===2, 'critical status and nonzero CLI code');
check(count($report['critical'])===2 && count($report['warnings'])===2, 'critical failures and worker/stale restore warnings');
check($report['critical'][0]['runbook']==='docs/runbooks/database-outage.md', 'database failure maps to real database runbook');
check($report['warnings'][0]['runbook']==='docs/runbooks/queue-backlog.md', 'stale worker has queue runbook');
check(count($report['skipped'])===2 && $report['skipped'][1]==='retention_progress', 'skipped checks are deduplicated and not passed');
check(!str_contains(json_encode($report, JSON_THROW_ON_ERROR), 'backup_id'), 'raw input and backup identities not exposed');
$degraded = IncidentTriage::classify(['version'=>'0.9.1', 'checks'=>['runtime'=>true, 'worker_queue'=>false]], $time);
check($degraded['status']==='degraded' && IncidentTriage::exitCode($degraded)===1, 'worker failure is degraded');
$ok = IncidentTriage::classify(['version'=>'0.9.1', 'checks'=>['runtime'=>true], 'skipped'=>['backup_archive_verified']], $time);
check($ok['status']==='ok' && IncidentTriage::exitCode($ok)===0 && $ok['skipped']===['backup_archive_verified'], 'unchecked backup stays explicitly skipped');
$unknown = IncidentTriage::classify(['version'=>'0.9.1','checks'=>['unknown_future_guard'=>false]], $time);
check($unknown['status']==='critical' && $unknown['critical'][0]['check']==='unknown_future_guard', 'unknown future failures fail closed');
check(rejected(fn()=>IncidentTriage::classify(['version'=>'0.9.1','checks'=>['runtime'=>'false']],$time)), 'non-boolean check cannot spoof success');
check(rejected(fn()=>IncidentTriage::classify(['version'=>'0.9.1','checks'=>['runtime'=>true]],'invalid')), 'invalid evidence clock rejected');
$base = sys_get_temp_dir() . '/cajeer-incident-' . bin2hex(random_bytes(8));
mkdir($base, 0700);
try {
    $path = IncidentSnapshot::save($base, $report);
    check(is_file($path) && (fileperms($path) & 0777)===0600, 'evidence file is private');
    check((fileperms(dirname($path)) & 0777)===0700, 'evidence directory is private');
    check(json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR)===$report, 'complete machine-readable evidence persisted');
    $path2 = IncidentSnapshot::save($base, $report);
    check($path !== $path2 && is_file($path), 'exclusive creation never overwrites existing evidence');
    check(rejected(fn()=>IncidentSnapshot::save($base, ['scope'=>'untrusted'])), 'invalid report cannot be persisted');
    $link = $base.'/link'; symlink($base.'/incident-reports', $link);
    check(rejected(fn()=>IncidentSnapshot::save($link,$report)), 'symlinked storage is rejected');
    unlink($link);
    chmod($base.'/incident-reports', 0777);
    check(rejected(fn()=>IncidentSnapshot::save($base, $report)), 'group-writable evidence directory rejected');
    chmod($base.'/incident-reports', 0700);
} finally {
    foreach (glob($base.'/incident-reports/*') ?: [] as $file) unlink($file);
    if (is_dir($base.'/incident-reports')) rmdir($base.'/incident-reports');
    rmdir($base);
}
echo "Operations readiness: {$count} checks passed\n";
