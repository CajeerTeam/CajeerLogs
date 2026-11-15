<?php
declare(strict_types=1);
use Cajeer\Logs\Database\{IntegrityInspector,PerformanceInspector,MigrationRunner,ConnectionFactory,TransactionManager};
use Cajeer\Logs\Database\Repository\{ProjectRepository,SourceRepository,TokenRepository,UserRepository};
use Cajeer\Logs\Console\Command\DatabaseMaintenanceCommand;
use Cajeer\Logs\Lifecycle\Retention;
use Cajeer\Logs\Log\{IngestEvent,LogRepository,LogQuery};
use Cajeer\Logs\Queue\PostgresQueueDriver;
use Cajeer\Logs\Kernel\Application;
if(getenv('CAJEER_TEST_DATABASE')!=='1'){fwrite(STDERR,"Use CAJEER_TEST_DATABASE=1 with a disposable PostgreSQL database.\n");exit(1);}
$root=dirname(__DIR__);$base=require $root.'/core/bootstrap.php';$base->boot();$db=$base->container()->get(PDO::class);
$schema='cl_integrity_'.bin2hex(random_bytes(6));$dir=sys_get_temp_dir().'/'.$schema;mkdir($dir,0700);$db->exec('CREATE SCHEMA "'.$schema.'"');
$n=0;function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
function rejected(callable $f,string $state):bool{try{$f();}catch(PDOException $e){return $e->getCode()===$state;}return false;}
try {
    $app=new Application($root);$app->boot();$app->config()->set('database.schema',$schema);$pdo=$app->container()->get(PDO::class);
    foreach(glob($root.'/migrations/pgsql/*.sql') as $f) if((int)substr(basename($f),0,4)<12)copy($f,$dir.'/'.basename($f));
    (new MigrationRunner($pdo,$dir,$schema))->migrate();
    $pr=new ProjectRepository($pdo);$p=$pr->create('A','a');$other=$pr->create('B','b');$sr=new SourceRepository($pdo);$s=$sr->create($p,'App','app');$s2=$sr->create($other,'App','app');
    $tr=new TokenRepository($pdo);$t=$tr->create('A',['logs:write'],$p,$s);$t2=$tr->create('B',['logs:write'],$other,$s2);
    $log=new LogRepository($pdo);$tx=new TransactionManager($pdo);$e=new IngestEvent('app','info','integrity test',(object)[],gmdate('Y-m-d\TH:i:s\Z'),null,null,'test-trace',null,[]);
    $ids=$tx->run(fn()=>$log->storeBatch([$e,$e],$p,['app'=>$s],$t['id']));$id=$ids[0];
    $foreignId=$tx->run(fn()=>$log->storeBatch([$e],$other,['app'=>$s2],$t2['id']))[0];
    $q=new PostgresQueueDriver($pdo);$key=hash('sha256','test');$hash=hash('sha256','body');$job=$tx->run(fn()=>$q->enqueue($t['id'],$p,$key,$hash,[$e],['app'=>$s]))['job_id'];
    $runner=$app->container()->get(MigrationRunner::class);$guard=$runner->guard(true);check((new IntegrityInspector($pdo))->inspect()['status']==='ok','read-only 0.1.8 preflight');$guard->release();
    $pdo->exec('UPDATE log_events SET ingest_token_id='.$pdo->quote($t2['id']).' WHERE id='.$pdo->quote($id));
    check((new IntegrityInspector($pdo))->inspect()['checks']['log_token_scope']['issues_at_least']===1,'foreign token diagnosed');
    try{$runner->migrate();$failed=false;}catch(Throwable){$failed=true;}
    check($failed&&$runner->status()['pending']===['0012_database_integrity.sql','0013_ingest_signing.sql','0014_delivery_idempotency.sql','0015_authentication_sessions.sql','0016_saved_log_views.sql'],'migration rejects corrupt history without partial DDL');
    $pdo->exec('UPDATE log_events SET ingest_token_id='.$pdo->quote($t['id']).' WHERE id='.$pdo->quote($id));
    check($runner->migrate()===['0012_database_integrity.sql','0013_ingest_signing.sql','0014_delivery_idempotency.sql','0015_authentication_sessions.sql','0016_saved_log_views.sql']&&$runner->status()['integrity']==='verified','upgrade after explicit repair');
    check(rejected(fn()=>$pdo->exec('UPDATE log_events SET ingest_token_id='.$pdo->quote($t2['id']).' WHERE id='.$pdo->quote($id)),'23503'),'FK rejects cross-project log token');
    check(rejected(fn()=>$pdo->exec("UPDATE log_events SET source='wrong' WHERE id=".$pdo->quote($id)),'23503'),'FK rejects source slug mismatch');
    check(rejected(fn()=>$pdo->exec('UPDATE jobs SET token_id='.$pdo->quote($t2['id']).' WHERE id='.$job),'23503'),'FK rejects cross-project queue token');
    check(rejected(fn()=>$pdo->exec("UPDATE jobs SET status='running' WHERE id=".$job),'23514'),'invalid lease rejected');
    check($q->process($q->claim())==='completed','existing pending job completes after upgrade');
    $tx->run(fn()=>$log->saveReceipt($t['id'],hash('sha256','receipt'),$hash,$ids));
    check((new IntegrityInspector($pdo))->inspect()['status']==='ok','receipts, queue result and attempts consistent');
    (new UserRepository($pdo))->create('core@example.test','core-test-password-123','administrator');
    $verify=new DatabaseMaintenanceCommand($app);
    ob_start();$code=$verify->handle('db:verify',['1']);$report=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
    check($code===0&&$report['status']==='ok'&&$report['active_administrators']===1,'unified installed database verification');
    $pdo->exec("UPDATE ingest_receipts SET event_ids='[\"bad\"]'::jsonb");
    check((new IntegrityInspector($pdo))->inspect()['checks']['receipt_shape']['issues_at_least']===1,'malformed receipt reported without cast failure');
    ob_start();$code=$verify->handle('db:verify',['1']);$report=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
    check($code===2&&$report['status']==='issues','installed verification refuses logical corruption');
    $pdo->exec('DELETE FROM ingest_receipts');
    // Index-backed retention: overlap policies, bounded delete, independently locked old row, other project safe.
    $pdo->exec('UPDATE projects SET retention_days=1 WHERE id='.$pdo->quote($p));$pdo->exec('UPDATE sources SET retention_days=1 WHERE id='.$pdo->quote($s));
    $pdo->exec("UPDATE log_events SET received_at=now()-interval '2 days' WHERE project_id=".$pdo->quote($p));
    $cfg=clone $app->config();$second=(new ConnectionFactory($cfg))->make('pgsql');$second->beginTransaction();$second->query('SELECT id FROM log_events WHERE id='.$pdo->quote($id).' FOR UPDATE')->closeCursor();
    $fresh=(new LogRepository($second))->storeBatch([$e],$p,['app'=>$s],$t['id'])[0];
    $removed=(new Retention($pdo))->run(3);check($removed===2,'retention skips locked candidate, deduplicates overlap');$second->commit();
    check((new Retention($pdo))->run(3)===1,'retention resumes after lock release');
    check((int)$pdo->query('SELECT count(*) FROM log_events WHERE id IN ('.$pdo->quote($fresh).','.$pdo->quote($foreignId).')')->fetchColumn()===2,'concurrent fresh write and other project preserved');
    check((new IntegrityInspector($pdo))->inspect()['status']==='ok','expired retained-event references not corruption');
    $perf=new PerformanceInspector($pdo);$report=$perf->report();check(count($report['tables'])>0&&in_array('idx_log_events_project_trace_time',array_column($report['indexes'],'index'),true),'real catalog size and index statistics');
    $plans=$perf->explain($p,'test-trace',true);check(isset($plans['plans']['trace'][0]['Plan'],$plans['plans']['latest'][0]['Execution Time']),'EXPLAIN ANALYZE actual PostgreSQL plan');
    echo "Integrity PostgreSQL: $n checks passed\n";
} finally {if(isset($second)&&$second->inTransaction())$second->rollBack();$db->exec('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');foreach(glob($dir.'/*.sql') as $f)unlink($f);rmdir($dir);}
