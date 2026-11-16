<?php
declare(strict_types=1);
use Cajeer\Logs\Database\{ConnectionFactory,MigrationRunner,MigrationSql,DatabaseException};
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Http\Request;
if(getenv('CAJEER_TEST_DATABASE')!=='1'){fwrite(STDERR,"Use a disposable PostgreSQL 17/18 database (CAJEER_TEST_DATABASE=1).\n");exit(1);}
$root=dirname(__DIR__);$app=require $root.'/core/bootstrap.php';$app->boot();$base=$app->container()->get(PDO::class);
$schema='cl_migrate_'.bin2hex(random_bytes(6));$legacy=$schema.'_legacy';$tmp=sys_get_temp_dir().'/'.$schema;mkdir($tmp,0700);
$n=0;
function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
function fails(callable $call):bool{try{$call();}catch(DatabaseException){return true;}return false;}
function connection(Application $app,string $schema):PDO{$cfg=clone $app->config();$cfg->set('database.schema',$schema);return (new ConnectionFactory($cfg))->make('pgsql');}
try{
    foreach([$schema,$legacy] as $s)$base->exec('CREATE SCHEMA "'.$s.'"');
    foreach(glob($root.'/migrations/pgsql/*.sql') as $f)copy($f,$tmp.'/'.basename($f));
    $pdo=connection($app,$schema);$runner=new MigrationRunner($pdo,$tmp,$schema);
    $plan=$runner->plan();check(count($plan['plan'])===21&&$plan['integrity']==='uninitialized'&&$pdo->query("SELECT to_regclass('schema_migrations') IS NULL")->fetchColumn()===true,'fresh plan is read-only');
    check(count($runner->migrate())===21&&$runner->migrate()===[],'fresh per-file migrations and idempotent replay');
    $s=$runner->status();check($s['integrity']==='verified'&&count($s['attempts'])===21&&count($s['runs'])===21,'real catalog baseline and attempt journal');
    $pdo->exec('ALTER TABLE users ALTER COLUMN email DROP NOT NULL');
    $s=$runner->status();check($s['integrity']==='drift'&&in_array(['object'=>'column:users.email','change'=>'changed'],$s['drift'],true)&&fails(fn()=>$runner->migrate()),'changed nullability detected and migration refused');
    $api=new Application($root);$api->boot();$api->config()->set('database.schema',$schema);$api->config()->set('app.env','test');
    check($api->handle(new Request('GET','/ready'))->status===503&&$api->handle(new Request('POST','/api/v1/auth/login',['Content-Type'=>'application/json'],[],'{"email":"test@example.test","password":"test-password"}'))->status===503,'drift blocks readiness and DB-backed HTTP runtime');
    $pdo->exec('ALTER TABLE users ALTER COLUMN email SET NOT NULL');
    $pdo->exec('DROP INDEX idx_log_events_trace_id');check(in_array(['object'=>'index:idx_log_events_trace_id','change'=>'missing'],$runner->status()['drift'],true),'missing index detected');
    $pdo->exec('CREATE INDEX idx_log_events_trace_id ON log_events(trace_id)');
    $pdo->exec('ALTER TABLE log_events VALIDATE CONSTRAINT log_events_ingest_scope');check(in_array(['object'=>'constraint:log_events.log_events_ingest_scope','change'=>'changed'],$runner->status()['drift'],true),'constraint validation change detected');
    $pdo->exec('ALTER TABLE log_events DROP CONSTRAINT log_events_ingest_scope; ALTER TABLE log_events ADD CONSTRAINT log_events_ingest_scope CHECK (project_id IS NOT NULL AND source_id IS NOT NULL) NOT VALID');
    check($runner->status()['integrity']==='verified','repair restores expected structure without changing history');
    // Simulate an actual 0.1.1 database, not a new-engine snapshot.
    $old=connection($app,$legacy);$old->beginTransaction();$old->exec('CREATE TABLE schema_migrations (version text PRIMARY KEY, checksum text NOT NULL CHECK (length(checksum)=64), applied_at timestamptz NOT NULL DEFAULT now())');
    $insert=$old->prepare('INSERT INTO schema_migrations(version,checksum) VALUES (:version,:checksum)');
    foreach(glob($root.'/migrations/pgsql/*.sql') as $f){$sql=file_get_contents($f);$old->exec($sql);$insert->execute(['version'=>basename($f),'checksum'=>hash('sha256',$sql)]);}
    $old->exec("INSERT INTO projects(name,slug) VALUES('Legacy','legacy')");$old->commit();
    $oldRunner=new MigrationRunner($old,$tmp,$legacy);check($oldRunner->status()['integrity']==='unverified','legacy history is not blindly trusted');
    $old->exec('ALTER TABLE users ADD COLUMN drift_probe text');check(fails(fn()=>$oldRunner->migrate())&&$old->query("SELECT to_regclass('schema_migration_state') IS NULL")->fetchColumn()===true,'legacy drift rejects adoption and rolls back metadata');
    $old->exec('ALTER TABLE users DROP COLUMN drift_probe');check($oldRunner->migrate()===[]&&$oldRunner->status()['integrity']==='verified'&&(int)$old->query('SELECT count(*) FROM projects')->fetchColumn()===1,'legacy verified against rollback-only replay, data preserved');
    check((int)$base->query("SELECT count(*) FROM pg_namespace WHERE nspname LIKE 'cl_migration_ref_%'")->fetchColumn()===0,'shadow schema is not persisted');
    // Shared runtime lock and independent migration connection use the same namespace.
    $guard=$runner->guard();$second=connection($app,$schema);$second->exec('SET lock_timeout=100');$other=new MigrationRunner($second,$tmp,$schema);
    check(fails(fn()=>$other->migrate()),'runtime shared lock blocks concurrent migration');$guard->release();check($other->migrate()===[],'release permits next migration');
    file_put_contents($tmp.'/0021_probe.sql',"CREATE TABLE migration_probe(id integer PRIMARY KEY, value text); INSERT INTO migration_probe(id) VALUES(1),(2),(3),(4),(5);");
    file_put_contents($tmp.'/0022_backfill.sql',"-- cajeer:backfill\nWITH batch AS (SELECT id FROM migration_probe WHERE value IS NULL ORDER BY id LIMIT 2) UPDATE migration_probe SET value='filled' FROM batch WHERE migration_probe.id=batch.id AND migration_probe.value IS NULL RETURNING migration_probe.id;");
    $limited=new MigrationRunner($pdo,$tmp,$schema,600000,1);check(fails(fn()=>$limited->migrate()),'bounded backfill pauses safely after committed batch');
    check((int)$pdo->query("SELECT count(*) FROM migration_probe WHERE value='filled'")->fetchColumn()===2&&$limited->status()['pending']===['0022_backfill.sql'],'earlier migration and first data batch durable');
    $backfillFile=$tmp.'/0022_backfill.sql'; $backfillBody=file_get_contents($backfillFile); unlink($backfillFile);
    check(fails(fn()=>$runner->status())&&fails(fn()=>$runner->migrate()),'removed unfinished backfill fails closed'); file_put_contents($backfillFile,$backfillBody);
    check($runner->migrate()===['0022_backfill.sql']&&(int)$pdo->query("SELECT count(*) FROM migration_probe WHERE value='filled'")->fetchColumn()===5,'backfill resumes idempotently');
    $indexSql="-- cajeer:concurrent-index\nCREATE INDEX CONCURRENTLY migration_probe_value_idx ON migration_probe(value);";
    file_put_contents($tmp.'/0023_online.sql',$indexSql);
    // Reproduce the durable state of a crash after CREATE INDEX committed, before history commit.
    $j=$pdo->prepare("INSERT INTO schema_migration_runs(version,checksum,mode,status) VALUES('0023_online.sql',:checksum,'concurrent-index','running')");$j->execute(['checksum'=>hash('sha256',$indexSql)]);
    $pdo->exec("INSERT INTO schema_migration_attempts(version,attempt,status) VALUES('0023_online.sql',1,'running')");
    $pdo->exec('CREATE INDEX CONCURRENTLY migration_probe_value_idx ON migration_probe(value)');
    check($runner->status()['integrity']==='drift','incomplete concurrent index visible before recovery');
    check($runner->migrate()===['0023_online.sql']&&$pdo->query("SELECT indisvalid FROM pg_index WHERE indexrelid='migration_probe_value_idx'::regclass")->fetchColumn()===true,'journaled interrupted concurrent index rebuilt and validated');
    check($pdo->query("SELECT status FROM schema_migration_attempts WHERE version='0023_online.sql' AND attempt=1")->fetchColumn()==='interrupted','interrupted attempt retained in journal');
    file_put_contents($tmp.'/0024_committed.sql','CREATE TABLE migration_committed(id integer PRIMARY KEY);');
    file_put_contents($tmp.'/0025_failed.sql','CREATE TABLE migration_rollback(id integer); SELECT * FROM missing_migration_test_table;');
    check(fails(fn()=>$runner->migrate()),'SQL failure stops pending sequence');
    check($pdo->query("SELECT to_regclass('migration_committed') IS NOT NULL AND to_regclass('migration_rollback') IS NULL")->fetchColumn()===true,'earlier migration committed, failed file fully rolled back');
    $s=$runner->status();check($s['integrity']==='verified'&&$s['pending']===['0025_failed.sql']&&$pdo->query("SELECT status FROM schema_migration_runs WHERE version='0025_failed.sql'")->fetchColumn()==='failed','failure journal, checksum and pending state preserved');
    check(fails(fn()=>(new MigrationRunner($pdo,$root.'/migrations/pgsql',$schema))->status()),'older runtime rejects future migration history');
    echo "Migration engine PostgreSQL: $n checks passed\n";
}finally{
    foreach(['pdo','old','second'] as $var)if(isset($$var)&&$$var->inTransaction())$$var->rollBack();
    foreach([$schema,$legacy] as $s)$base->exec('DROP SCHEMA IF EXISTS "'.$s.'" CASCADE');
    foreach(glob($tmp.'/*.sql') as $f)unlink($f);rmdir($tmp);
}
