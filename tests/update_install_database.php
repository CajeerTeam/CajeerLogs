<?php
declare(strict_types=1);
use Cajeer\Logs\Database\{ConnectionFactory, DatabaseException, MigrationRunner};
use Cajeer\Logs\Database\Repository\{ProjectRepository, SourceRepository, TokenRepository};
use Cajeer\Logs\Operations\BackupManager;
if (getenv('CAJEER_TEST_DATABASE')!=='1') {fwrite(STDERR,"Disposable PostgreSQL test requires CAJEER_TEST_DATABASE=1.\n");exit(1);}
$source=getenv('CAJEER_TEST_UPDATE_SOURCE_DB');$target=getenv('CAJEER_TEST_UPDATE_TARGET_DB');
if ($source!=='cajeer_update_source_ci'||$target!=='cajeer_update_target_ci') throw new RuntimeException('Provision the two dedicated empty update test databases.');
putenv('DB_DATABASE='.$source);putenv('DB_SCHEMA=public');
$app=require dirname(__DIR__).'/core/bootstrap.php';$app->boot();
$config=$app->config();$tmp=sys_get_temp_dir().'/cl-update-db-'.bin2hex(random_bytes(8));mkdir($tmp,0700);
$config->set('operations.backup_dir',$tmp);$config->set('operations.backup_min_free_bytes',0);
function checkUpdate(bool $ok,string $label):void {if (!$ok) throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
try {
    $pdo=$app->container()->get(PDO::class);
    $runner=$app->container()->get(MigrationRunner::class);$runner->migrate();$runner->assertCurrent();
    $project=(new ProjectRepository($pdo))->create('Update clone project','update-clone');
    $sourceId=(new SourceRepository($pdo))->create($project,'Application','app');
    $token=(new TokenRepository($pdo))->create('Update writer',['logs:write'],$project,$sourceId);
    $manager=new BackupManager($app);
    $second=(new ConnectionFactory($config))->make('pgsql');
    try {$manager->assertUpdateQuiescent();throw new RuntimeException('Concurrent source connection was accepted.');}
    catch (DatabaseException $e) {checkUpdate(str_contains($e->getMessage(),'exclusive source database'),'reject active external database writer');}
    $second=null;usleep(300000);
    $manager->assertUpdateQuiescent();
    $backup=$manager->create();checkUpdate($manager->verify($backup['id'])===$backup,'real pg_dump verified by checksum and pg_restore list');
    $clone=$manager->cloneForUpdate($backup['id'],$target);
    checkUpdate($clone['status']==='cloned'&&$clone['integrity_verified']&&!$clone['api_tokens_revoked'],'real isolated exact clone without credential revocation');
    $copyConfig=clone $config;$copyConfig->set('database.database',$target);
    $copy=(new ConnectionFactory($copyConfig))->make('pgsql');
    (new MigrationRunner($copy,dirname(__DIR__).'/migrations/pgsql','public'))->assertCurrent();
    checkUpdate((new TokenRepository($copy))->findActive($token['token'],false)!==null,'cloned credential remains usable');
    checkUpdate((new TokenRepository($pdo))->findActive($token['token'],false)!==null,'original credential remains usable');
    $copy->prepare('UPDATE projects SET name=:name WHERE id=:id')->execute(['name'=>'Clone only','id'=>$project]);
    $read=$pdo->prepare('SELECT name FROM projects WHERE id=:id');$read->execute(['id'=>$project]);
    checkUpdate($read->fetchColumn()==='Update clone project','clone changes do not mutate original database');
    $copy=null;
    try {$manager->cloneForUpdate($backup['id'],$target);throw new RuntimeException('Nonempty clone target accepted.');}
    catch (DatabaseException $e) {checkUpdate(str_contains($e->getMessage(),'not empty')||str_contains($e->getMessage(),'exclusive target'),'reject reuse of populated target');}
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry) {$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());}
    rmdir($tmp);
}
