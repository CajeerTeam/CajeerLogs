<?php
declare(strict_types=1);
/** Real PostgreSQL + ClickHouse + S3 data lifecycle, including transient outage recovery. */
use Cajeer\Logs\Analytics\{AnalyticsReplicator,ClickHouseAnalyticsStore,ClickHouseHttpClient,ClickHouseMigrator,PostgresRollupStore};
use Cajeer\Logs\Database\{MigrationRunner,TransactionManager};
use Cajeer\Logs\Database\Repository\{ProjectRepository,SourceRepository,TokenRepository};
use Cajeer\Logs\Http\Request;
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Log\{IngestValidator,LogRepository};
use Cajeer\Logs\Storage\{ArchiveLifecycle,S3CompatibleDisk};

if (getenv('CAJEER_TEST_DATABASE') !== '1' || getenv('CAJEER_TEST_CLICKHOUSE') !== '1' || !getenv('S3_TEST_PORT')) {
    fwrite(STDERR, "Disposable PostgreSQL, ClickHouse and a signed S3 test server are required.\n");
    exit(78);
}
$root=dirname(__DIR__);
$app=new Application($root);$app->boot();
$admin=$app->container()->get(PDO::class);
$schema='cl_cert_'.bin2hex(random_bytes(6));
$admin->exec('CREATE SCHEMA "'.$schema.'"');
$checks=0;
$check=static function(bool $ok,string $description)use(&$checks):void {
    if (!$ok) throw new RuntimeException('FAIL '.$description);
    $checks++;echo "PASS $description\n";
};
try {
    $app->config()->set('database.schema',$schema);
    $pdo=$app->container()->get(PDO::class);
    $app->container()->get(MigrationRunner::class)->migrate();
    $client=$app->container()->get(ClickHouseHttpClient::class);
    (new ClickHouseMigrator($client,$root.'/migrations/clickhouse'))->migrate();
    $store=new ClickHouseAnalyticsStore($client,true);
    $replicator=new AnalyticsReplicator($pdo,$client);
    $rollups=new PostgresRollupStore($pdo);
    $projects=new ProjectRepository($pdo);
    $p=$projects->create('Storage cert main','storage-cert-main');
    $other=$projects->create('Storage cert other','storage-cert-other');
    $sources=new SourceRepository($pdo);
    $s=$sources->create($p,'app','app');$sOther=$sources->create($other,'app','app');
    $tokens=new TokenRepository($pdo);
    $t=$tokens->create('writer',['logs:write'],$p)['id'];
    $tOther=$tokens->create('other-writer',['logs:write'],$other)['id'];
    $logs=new LogRepository($pdo);
    $occurred=gmdate('Y-m-d\TH:i:s\Z',time()-120);
    $send=static function(string $project,string $source,string $token,string $message)use($pdo,$logs,$occurred):string {
        $raw=json_encode(['timestamp'=>$occurred,'source'=>'app','level'=>'error','service'=>'cert','host'=>'node',
            'message'=>$message,'tags'=>['storage-cert']],JSON_THROW_ON_ERROR);
        $batch=(new IngestValidator())->parse(new Request('POST','/api/v1/ingest',['Content-Type'=>'application/json'],[],$raw));
        return (new TransactionManager($pdo))->run(fn()=>$logs->storeBatch($batch,$project,['app'=>$source],$token))[0];
    };
    $a=$send($p,$s,$t,'archive-A');$b=$send($p,$s,$t,'archive-B');$send($other,$sOther,$tOther,'other-project');
    $from=gmdate('Y-m-d\TH:i:00\Z',time()-3600);
    $to=gmdate('Y-m-d\TH:i:00\Z',time()+3600);
    $window=['from'=>$from,'to'=>$to];
    $count=static fn(string $project):int=>(int)$pdo->query("SELECT count(*) FROM log_events WHERE project_id='".$project."'")->fetchColumn();
    $check($count($p)===2&&$count($other)===1,'writes committed to isolated PostgreSQL projects');
    $backfill=$rollups->backfill($p,100);
    $check($backfill['complete'] && $rollups->verify($p,$from,$to)['match'],
        'rollup backfill and every dimension match original PostgreSQL events');

    // A closed local port models a genuine ClickHouse connection failure, not a mocked worker.
    $unavailable=new ClickHouseHttpClient(['dsn'=>'http://127.0.0.1:1','database'=>$client->database(),
        'username'=>'nonprod','password'=>'nonprod','timeout_seconds'=>1,'allow_insecure'=>true]);
    $failure=(new AnalyticsReplicator($pdo,$unavailable))->work(20);
    $check($failure['retry']===3 && $failure['delivered']===0,
        'real ClickHouse connection failure leaves durable outbox for retry');
    $check($count($p)===2 && $count($other)===1,'ClickHouse outage does not roll back primary events');
    $pdo->exec("UPDATE analytics_replication_outbox SET available_at=clock_timestamp() WHERE status='pending'");
    $result=$replicator->work(20);
    $check($result['delivered']===3,'queued events deliver after restored ClickHouse connectivity');
    $totals=static function(string $project)use($store,$window):int {
        return $store->aggregate(['project_id'=>$project]+$window)['total'];
    };
    $check($totals($p)===2&&$totals($other)===1,'ClickHouse FINAL exactly reconciles multi-project source totals');
    $check($replicator->replay($p,20,'completed')===2,'replay is available for completed deliveries');
    $check($replicator->work(20)['delivered']===2 && $totals($p)===2,'replay does not double count analytics');

    $s3=new S3CompatibleDisk(['endpoint'=>'http://127.0.0.1:'.getenv('S3_TEST_PORT'),
        'bucket'=>'cajeer-test','access_key'=>'TESTACCESS','secret_key'=>'secret-test-signing-key',
        'region'=>'us-east-1','path_style'=>true,'allow_insecure'=>true]);
    $archive=new ArchiveLifecycle($pdo,$s3);
    $month=gmdate('Y-m');
    $check($archive->stage($p,$month,100)['staged']===2,'data migration stages two actual hot events in monthly partition');
    $app->container()->get(MigrationRunner::class)->assertCurrent();
    $check(true,'dynamic partition creation does not break migration integrity');
    $check($archive->evict($p,$month,100)['evicted']===2 && $count($p)===0,
        'verified warm snapshots allow controlled hot eviction');
    $check($replicator->work(20)['delivered']===2&&$totals($p)===0&&$totals($other)===1,
        'hot deletion triggers versioned ClickHouse tombstones without cross-project impact');
    $check($archive->offload($p,$month,100)['offloaded']===2,
        'S3 offload verifies signed upload and readback before deleting warm rows');
    $objects=$archive->coldObjects($p);
    $check(count($objects)===1 && $archive->status($p)['warm_rows']===0,
        'cold object retains an immutable per-project manifest');
    $check($archive->restoreCold($p,$objects[0]['id'])['restored']===2,
        'verified signed S3 download restores real PostgreSQL warm partition');
    $check($archive->restoreHot($p,$month,100)['restored']===2 && $count($p)===2,
        'cold-to-hot migration rehydrates original event identities');
    $check($rollups->verify($p,$from,$to)['match'] && $rollups->status($p)['rollup_events']===2,
        'rollup aggregates reconcile after eviction and rehydration');
    $check($replicator->work(20)['delivered']===2 && $totals($p)===2 && $totals($other)===1,
        'replicated ClickHouse data reconciles after hot/cold restore');
    $check($archive->purgeCold($p,$objects[0]['id'])['deleted']===1,
        'remote cold object and manifest are explicitly erased');
    $check($archive->purgeWarm($p,$month,100)['purged']===2,
        'temporary warm copies are purged after verified restoration');
    echo "Integrated analytics/storage certification: $checks real checks passed\n";
} finally {
    $admin->exec('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
}
