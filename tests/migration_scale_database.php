<?php
declare(strict_types=1);
/** Real PostgreSQL migration stress/recovery test. Run ONLY in a disposable database. */
use Cajeer\Logs\Database\{MigrationRunner,ConnectionFactory,DatabaseException,PostgresBoolean};
use Cajeer\Logs\Kernel\Application;

if (getenv('CAJEER_TEST_DATABASE') !== '1' || getenv('APP_ENV') !== 'test') {
    fwrite(STDERR, "Migration scale test requires CAJEER_TEST_DATABASE=1 and APP_ENV=test.\n");
    exit(78);
}
$root=dirname(__DIR__);
$app=require $root.'/core/bootstrap.php'; $app->boot();
$base=$app->container()->get(PDO::class);
// Never run against a production-shaped data set. The test owns a fresh schema.
$schema='cl_cert_'.bin2hex(random_bytes(6));
$directory=sys_get_temp_dir().'/'.$schema;
if (!mkdir($directory,0700)) throw new RuntimeException('Cannot create isolated migration directory.');
$pdo=null;$second=null;$checks=0;$timings=[];
function verifyMigration(bool $pass,string $message): void {
    global $checks;
    if (!$pass) throw new RuntimeException('Migration certification failed: '.$message);
    ++$checks;echo 'PASS '.$message.PHP_EOL;
}
function expectedFailure(callable $f):bool {
    try {$f();} catch (DatabaseException $e) {return true;}
    return false;
}
function certPdo(Application $app,string $schema):PDO {
    $config=clone $app->config();$config->set('database.schema',$schema);
    return (new ConnectionFactory($config))->make('pgsql');
}
$started=microtime(true);
try {
    $base->exec('CREATE SCHEMA "'.$schema.'"');
    $migrationPaths=glob($root.'/migrations/pgsql/*.sql');
    sort($migrationPaths,SORT_STRING);
    foreach ($migrationPaths as $file) {
        if (!copy($file,$directory.'/'.basename($file))) throw new RuntimeException('Cannot copy migration fixture.');
    }
    $count=count($migrationPaths);
    verifyMigration($count===33,'complete 0001-0033 PostgreSQL migration history');
    $pdo=certPdo($app,$schema);
    $runner=new MigrationRunner($pdo,$directory,$schema);
    $begin=microtime(true);
    verifyMigration(count($runner->migrate())===$count,'fresh schema migration');
    $timings['fresh_migration_ms']=round((microtime(true)-$begin)*1000,2);
    verifyMigration($runner->migrate()===[],'idempotent migration replay');
    $status=$runner->status();
    verifyMigration($status['integrity']==='verified' && count($status['applied'])===$count && $status['pending']===[],'catalog snapshot and journal match');

    // Bind the mandatory database-matrix evidence to a real reference catalog
    // reconstructed from *all 33* shipped SQL files, not the stored snapshot.
    $independentReplay=$runner->certify();
    verifyMigration($independentReplay['status']==='verified' && $independentReplay['migrations']===33
        && hash_equals($independentReplay['catalog_sha256'],$independentReplay['reference_sha256'])
        && $independentReplay['scratch_schema_persisted']===false,
        'all shipped PostgreSQL migrations independently replayed and rolled back');

    // A large real table created through the migration engine, not a mocked SQL fixture.
    // This test is deliberately bounded for shared CI; 100k physical rows plus an index.
    $rows=100000;
    file_put_contents($directory.'/0034_scale_data.sql',
        'CREATE TABLE migration_cert_large (id bigint PRIMARY KEY, account integer NOT NULL, done boolean NOT NULL DEFAULT false);'."\n".
        'INSERT INTO migration_cert_large(id,account) SELECT i, i%128 FROM generate_series(1,'.$rows.') AS i;');
    $begin=microtime(true);
    verifyMigration($runner->migrate()===['0034_scale_data.sql'],'100000-row transactional data migration');
    $timings['data_migration_ms']=round((microtime(true)-$begin)*1000,2);
    verifyMigration((int)$pdo->query('SELECT count(*) FROM migration_cert_large')->fetchColumn()===$rows,'large table contains all committed rows');
    file_put_contents($directory.'/0035_scale_index.sql',
        "-- cajeer:concurrent-index\nCREATE INDEX CONCURRENTLY migration_cert_large_account_idx ON migration_cert_large(account,id);");
    $begin=microtime(true);
    verifyMigration($runner->migrate()===['0035_scale_index.sql'],'live concurrent index on 100000 rows');
    $timings['index_ms']=round((microtime(true)-$begin)*1000,2);
    verifyMigration(PostgresBoolean::required($pdo->query("SELECT indisvalid AND indisready FROM pg_index WHERE indexrelid='migration_cert_large_account_idx'::regclass")->fetchColumn()),'index valid and ready');

    $sql="-- cajeer:backfill\nWITH batch AS (SELECT id FROM migration_cert_large WHERE done=false ORDER BY id LIMIT 4000) UPDATE migration_cert_large AS target SET done=true FROM batch WHERE target.id=batch.id AND target.done=false RETURNING target.id;";
    file_put_contents($directory.'/0036_scale_backfill.sql',$sql);
    $begin=microtime(true);
    $limited=new MigrationRunner($pdo,$directory,$schema,600000,2);
    verifyMigration(expectedFailure(fn()=> $limited->migrate()),'bounded two-batch pause is reported, not certified');
    verifyMigration((int)$pdo->query('SELECT count(*) FROM migration_cert_large WHERE done')->fetchColumn()===8000,'interrupted backfill retains committed work');
    verifyMigration($runner->status()['pending']===['0036_scale_backfill.sql'],'journal retains incomplete migration');
    // Re-instantiate to test a process boundary; no in-memory checkpoint is trusted.
    unset($limited);
    $restarted=new MigrationRunner(certPdo($app,$schema),$directory,$schema);
    verifyMigration($restarted->migrate()===['0036_scale_backfill.sql'],'new connection resumes backfill safely');
    $timings['backfill_ms']=round((microtime(true)-$begin)*1000,2);
    $totals=$pdo->query('SELECT count(*) AS records,coalesce(sum(id),0) AS checksum FROM migration_cert_large WHERE done')->fetch(PDO::FETCH_ASSOC);
    verifyMigration((int)$totals['records']===$rows && (int)$totals['checksum']===(int)($rows*($rows+1)/2),'large backfill exact row count and arithmetic checksum');
    verifyMigration($runner->status()['integrity']==='verified' && $runner->status()['pending']===[],'schema verified after concurrent index and backfill');

    $pdo->exec('ALTER TABLE migration_cert_large ADD COLUMN unauthorized_probe integer');
    verifyMigration($runner->status()['integrity']==='drift' && expectedFailure(fn()=> $runner->migrate()),'unexpected column blocks release and migration');
    $pdo->exec('ALTER TABLE migration_cert_large DROP COLUMN unauthorized_probe');
    verifyMigration($runner->status()['integrity']==='verified','repair restores verified schema');
    $path=$directory.'/0036_scale_backfill.sql';
    file_put_contents($path,$sql."\n-- unauthorized checksum mutation\n");
    verifyMigration(expectedFailure(fn()=> $runner->status()),'modified applied migration checksum rejected');
    file_put_contents($path,$sql);
    verifyMigration($runner->status()['integrity']==='verified','restored migration checksum accepted');

    // Cross-session advisory lock must exclude DDL while online traffic holds its shared lock.
    $guard=$runner->guard();
    $second=certPdo($app,$schema);
    $second->exec('SET lock_timeout=250');
    $conflict=new MigrationRunner($second,$directory,$schema);
    verifyMigration(expectedFailure(fn()=> $conflict->migrate()),'active runtime lock blocks concurrent migration');
    $guard->release();
    verifyMigration($conflict->migrate()===[],'migration resumes after guard release');

    // A migration with several DDL statements must leave no partial table on failure.
    file_put_contents($directory.'/0037_failure_probe.sql','CREATE TABLE migration_cert_rollback(id bigint); SELECT 1/0;');
    verifyMigration(expectedFailure(fn()=> $runner->migrate()),'SQL error causes migration failure');
    verifyMigration(PostgresBoolean::required($pdo->query("SELECT to_regclass('migration_cert_rollback') IS NULL")->fetchColumn()),'failed multi-statement DDL fully rolled back');
    $state=$runner->status();
    verifyMigration($state['integrity']==='verified' && $state['pending']===['0037_failure_probe.sql'],'failed migration journal and previous data preserved');
    $missingSql=file_get_contents($directory.'/0037_failure_probe.sql');
    unlink($directory.'/0037_failure_probe.sql');
    verifyMigration(expectedFailure(fn()=> $runner->status()),'unrecognized pending transactional journal fails closed');
    file_put_contents($directory.'/0037_failure_probe.sql',$missingSql);
    verifyMigration($runner->status()['integrity']==='verified','restored pending SQL is checked against journal');

    $timings['total_ms']=round((microtime(true)-$started)*1000,2);
    $report=['version'=>'0.9.6','archive_sha256'=>getenv('CAJEER_MIGRATION_CERT_ARCHIVE_SHA256')?:null,'status'=>'passed','postgresql_version'=>$pdo->query('SHOW server_version')->fetchColumn(),
        'checks'=>$checks,'test_rows'=>$rows,'backfill_checksum'=>(string)$totals['checksum'],'independent_replay'=>$independentReplay,'timings'=>$timings];
    if (($output=getenv('CAJEER_MIGRATION_CERT_REPORT'))!==false && $output!=='') {
        if (!is_dir(dirname($output)) || file_put_contents($output,json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n",LOCK_EX)===false) throw new RuntimeException('Cannot persist migration certificate.');
    }
    echo "Migration scale PostgreSQL: $checks checks passed\n";
} finally {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    if ($second instanceof PDO && $second->inTransaction()) $second->rollBack();
    // Drop only the random schema this test created; never touch the application schema.
    $pdo=null;$second=null;
    $base->exec('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
    foreach (glob($directory.'/*.sql') ?: [] as $file) unlink($file);
    rmdir($directory);
}
