<?php
declare(strict_types=1);
use Cajeer\Logs\Operations\{BackupManager,BackupOperations,BackupVault};
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Database\DatabaseException;
require dirname(__DIR__).'/core/bootstrap.php';
$n=0;
function check(bool $ok,string $label): void { global $n; if (!$ok) throw new RuntimeException('FAIL '.$label); ++$n; echo "PASS $label\n"; }
function rejects(callable $fn): bool { try { $fn(); } catch (Throwable) { return true; } return false; }
function clean(string $p): void { if (is_dir($p)&&!is_link($p)) { foreach(scandir($p) as $f) if ($f!=='.'&&$f!=='..') clean($p.'/'.$f); rmdir($p); } else unlink($p); }
$dir=sys_get_temp_dir().'/cl-backup-'.bin2hex(random_bytes(8)); mkdir($dir,0700); $old=umask(0077);
try {
    $key=random_bytes(32); $vault=new BackupVault($key);
    $payload=random_bytes(2*1048576+137); $source=$dir.'/source'; file_put_contents($source,$payload);
    $id='backup-20260101T000000Z-0123456789abcdef';
    $m=['format'=>1,'id'=>$id,'version'=>'0.4.7','schema'=>'public','created_at'=>'2026-01-01T00:00:00+00:00','bytes'=>strlen($payload),'sha256'=>hash('sha256',$payload)];
    $package=$dir.'/package'; $vault->encrypt($source,$m,$package);
    check($vault->decrypt($package,$dir.'/restored',$id)===$m && file_get_contents($dir.'/restored')===$payload,'real multi-frame encryption and authenticated manifest round-trip');
    check(!str_contains(file_get_contents($package),substr($payload,0,128)) && !str_contains(file_get_contents($package),$id),'archive and manifest encrypted');
    check(rejects(fn()=>(new BackupVault(random_bytes(32)))->decrypt($package,$dir.'/wrong'))&&!file_exists($dir.'/wrong'),'wrong key fails and leaves no plaintext');
    check(rejects(fn()=>$vault->decrypt($package,null,'backup-20260102T000000Z-0123456789abcdef')),'wrong backup identity rejected');
    $bytes=file_get_contents($package); $bad=$dir.'/bad';
    $corrupt=$bytes; $corrupt[1000000]=chr(ord($corrupt[1000000])^1); file_put_contents($bad,$corrupt);
    check(rejects(fn()=>$vault->decrypt($bad,$dir.'/bad-out'))&&!file_exists($dir.'/bad-out'),'tampered data fails authentication and removes partial extraction');
    file_put_contents($bad,substr($bytes,0,-1)); check(rejects(fn()=>$vault->decrypt($bad)),'truncated final frame rejected');
    file_put_contents($bad,$bytes.'x'); check(rejects(fn()=>$vault->decrypt($bad)),'trailing data rejected');
    file_put_contents($bad,substr($bytes,0,strlen("CAJEERBACKUP\x01")+24).pack('N',0x7fffffff)); check(rejects(fn()=>$vault->decrypt($bad)),'oversized frame rejected without allocation');
    check(rejects(fn()=>$vault->encrypt($source,$m+[],$package))&&file_get_contents($package)===$bytes,'existing encrypted copy not overwritten');
    check(rejects(fn()=>$vault->decrypt($package,$source))&&file_get_contents($source)===$payload,'existing plaintext not overwritten');
    $wrong=$m; $wrong['sha256']=str_repeat('0',64); check(rejects(fn()=>$vault->encrypt($source,$wrong,$dir.'/wrong-hash'))&&!file_exists($dir.'/wrong-hash'),'source checksum verified while streaming');
    chmod($package,0644); check(rejects(fn()=>$vault->decrypt($package)),'unsafe package permissions rejected'); chmod($package,0600);
    // File orchestration test: use a local executable fixture for pg_restore --list only.
    // The actual PostgreSQL TOC and restore round-trip are covered by upgrade_release.py.
    $tool=$dir.'/pg_restore'; file_put_contents($tool,"#!/bin/sh\n[ \"\$1\" = --list ] && [ -s \"\$2\" ]\n"); chmod($tool,0700);
    $local=$dir.'/local'; $remote=$dir.'/remote'; mkdir($local,0700); mkdir($remote,0700);
    putenv('APP_ENV=test'); putenv('APP_URL=http://localhost:8080'); putenv('CAJEER_ENV_FILE='.$dir.'/.env');
    $app=new Application(dirname(__DIR__)); $app->boot(); $c=$app->config();
    foreach (['backup_dir'=>$local,'backup_offsite_dir'=>$remote,'backup_encryption_key'=>bin2hex($key),'pg_restore'=>$tool,'backup_min_free_bytes'=>0,'backup_keep_local'=>1,'backup_offsite_distinct_device'=>false] as $k=>$v) $c->set('operations.'.$k,$v);
    $manager=new BackupManager($app); $ops=new BackupOperations($app);
    check(rejects(fn()=>$ops->certify($id,'cajeer_dr_target',0,60))&&rejects(fn()=>$ops->certify($id,'cajeer_dr_target',60,0)),'DR certification refuses missing RPO/RTO objectives');
    $make=function(array $meta) use($local,$payload):void { $p=$local.'/'.$meta['id']; mkdir($p,0700); file_put_contents($p.'/database.dump',$payload); file_put_contents($p.'/manifest.json',json_encode($meta)); };
    $make($m); $second=$m; $second['id']='backup-20260102T000000Z-0123456789abcdef'; $make($second);
    check($ops->locked(fn()=>$ops->replicate($id))['offsite_verified'] && $ops->offsiteVerify($id)['sha256']===$m['sha256'],'offsite publication and full readback');
    check($ops->replicate($id)['offsite_verified'],'idempotent retry verifies existing copy');
    check($ops->offsiteList()===[$id],'offsite inventory discovers backup independently');
    $c->set('operations.backup_offsite_distinct_device',true); check(rejects(fn()=>$ops->offsiteList()),'same device fails production offsite policy'); $c->set('operations.backup_offsite_distinct_device',false);
    $c->set('operations.backup_keep_daily',2); check($ops->prune(false)['eligible']===[],'daily retention protects newest backup per day'); $c->set('operations.backup_keep_daily',0);
    check(rejects(fn()=>$ops->fetch($id)),'fetch refuses overwriting local backup');
    $plan=$ops->prune(false); check($plan['eligible']===[$id] && is_dir($local.'/'.$id),'prune dry run preserves data and protects newest');
    check($ops->prune(true)['removed']===[$id] && !file_exists($local.'/'.$id),'prune removes only verified replicated older backup');
    check($ops->fetch($id)['imported'] && $manager->verify($id)===$m,'fetch verifies archive and publishes atomically');
    $c->set('operations.backup_offsite_dir',$local); check(rejects(fn()=>$ops->replicate($id)),'offsite must not equal local root'); $c->set('operations.backup_offsite_dir',$remote);
    check($ops->locked(fn()=>rejects(fn()=>$ops->locked(fn()=>true))),'concurrent operation lock rejects second holder');
    check($ops->record('test_success',fn()=>['status'=>'done'])===['status'=>'done'],'history records successful operation');
    check(rejects(fn()=>$ops->record('test_failure',fn()=>throw new DatabaseException('secret should not enter history'))),'failed operation propagated');
    $history=$ops->history(); check(count($history['runs'])===2 && !str_contains(json_encode($history),'secret should not enter history') && in_array('failed',array_column($history['runs'],'status'),true),'history stores safe failure and success');
    check($ops->status()['last_success_at']===null,'status does not claim backup success from fixture');
    // Simulate an interrupted replication using durable pending state; no database dump is needed on retry.
    file_put_contents($local.'/.schedule.json',json_encode(['pending_id'=>$id,'pending_requires_offsite'=>true]));
    $c->set('operations.backup_offsite_dir',''); check(rejects(fn()=>$ops->run()) && $ops->status()['replication_pending'],'pending remote copy cannot silently become local-only');
    $c->set('operations.backup_offsite_dir',$dir.'/absent'); check(rejects(fn()=>$ops->run()) && $ops->status()['replication_pending'],'failed replica remains pending');
    $c->set('operations.backup_offsite_dir',$remote); check($ops->run()['status']==='completed' && $ops->run()['status']==='not_due' && $ops->status()['last_offsite_verified'],'scheduled retry completes existing backup without another dump');
    check(is_numeric($ops->status()['last_success_seconds'])&&$ops->status()['last_success_seconds']>=0,'successful backup duration measured and persisted');
    $slow=$dir.'/slow'; file_put_contents($slow,"#!/bin/sh\nexec sleep 5\n"); chmod($slow,0700); $c->set('operations.pg_restore',$slow); $c->set('operations.backup_timeout_seconds',1);
    $start=microtime(true); check(rejects(fn()=>$manager->verify($id)) && microtime(true)-$start<4,'PostgreSQL client timeout terminates child');
    $c->set('operations.pg_restore',$tool); $c->set('operations.backup_min_free_bytes',PHP_INT_MAX); check(rejects(fn()=>$manager->create()),'disk reserve rejects before dump');
} finally { umask($old); clean($dir); }
echo "Backup operations: $n checks passed\n";
