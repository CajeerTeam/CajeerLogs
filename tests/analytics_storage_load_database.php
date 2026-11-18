<?php
declare(strict_types=1);
/** Bounded, measured real database + analytical replica + archival workload. */
use Cajeer\Logs\Analytics\{AnalyticsReplicator,ClickHouseAnalyticsStore,ClickHouseHttpClient,ClickHouseMigrator,PostgresRollupStore};
use Cajeer\Logs\Database\{MigrationRunner,TransactionManager};
use Cajeer\Logs\Database\Repository\{ProjectRepository,SourceRepository,TokenRepository};
use Cajeer\Logs\Http\Request;
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Log\{IngestValidator,LogRepository};
use Cajeer\Logs\Storage\{ArchiveLifecycle,S3CompatibleDisk};
if (getenv('CAJEER_TEST_DATABASE')!=='1'||getenv('CAJEER_TEST_CLICKHOUSE')!=='1'||!getenv('S3_TEST_PORT')) {
    fwrite(STDERR,"Real disposable PostgreSQL, ClickHouse and test S3 are required.\n");exit(78);
}
$root=dirname(__DIR__);$app=new Application($root);$app->boot();
$admin=$app->container()->get(PDO::class);$schema='cl_load_'.bin2hex(random_bytes(6));
$admin->exec('CREATE SCHEMA "'.$schema.'"');
$n=0;$check=static function(bool $ok,string $name)use(&$n):void {if(!$ok)throw new RuntimeException('FAIL '.$name);$n++;echo "PASS $name\n";};
$elapsed=static fn(int $start):float=>max(0.000001,(hrtime(true)-$start)/1e9);
$number=static function(string $name,float $default):float {
    $raw=getenv($name);
    if($raw===false)return $default;
    if(!is_numeric($raw)||!is_finite((float)$raw)||(float)$raw<=0||(float)$raw>100000)throw new InvalidArgumentException('Invalid performance threshold '.$name);
    return (float)$raw;
};
try {
    $app->config()->set('database.schema',$schema);$pdo=$app->container()->get(PDO::class);
    $app->container()->get(MigrationRunner::class)->migrate();
    $ch=$app->container()->get(ClickHouseHttpClient::class);
    (new ClickHouseMigrator($ch,$root.'/migrations/clickhouse'))->migrate();
    $rep=new AnalyticsReplicator($pdo,$ch);$store=new ClickHouseAnalyticsStore($ch,true);
    $project=(new ProjectRepository($pdo))->create('Load test','load-test');
    $source=(new SourceRepository($pdo))->create($project,'app','app');
    $token=(new TokenRepository($pdo))->create('writer',['logs:write'],$project)['id'];
    $logs=new LogRepository($pdo);$validator=new IngestValidator();
    $at=gmdate('Y-m-d\TH:i:s\Z',time()-120);$count=300;
    $start=hrtime(true);
    for($batch=0;$batch<12;$batch++) {
        $events=[];
        for($i=0;$i<25;$i++) {
            $j=$batch*25+$i;
            $events[]=['source'=>'app','timestamp'=>$at,'level'=>($j%5===0?'error':'info'),
                'service'=>($j%2===0?'frontend':'backend'),'host'=>'load-node','message'=>'analytics-load-'.$j];
        }
        $body=json_encode(['events'=>$events],JSON_THROW_ON_ERROR);
        $parsed=$validator->parse(new Request('POST','/api/v1/ingest',['Content-Type'=>'application/json'],[],$body));
        (new TransactionManager($pdo))->run(fn()=>$logs->storeBatch($parsed,$project,['app'=>$source],$token));
    }
    $ingestSeconds=$elapsed($start);$ingestRps=$count/$ingestSeconds;
    $check((int)$pdo->query("SELECT count(*) FROM log_events WHERE project_id='$project'")->fetchColumn()===$count,
        'all measured events are durably committed in PostgreSQL');
    $roll=new PostgresRollupStore($pdo);$roll->backfill($project,2000);
    $from=gmdate('Y-m-d\TH:i:00\Z',time()-3600);$to=gmdate('Y-m-d\TH:i:00\Z',time()+3600);
    $check($roll->verify($project,$from,$to)['match'],'high-cardinality minute rollups reconcile after 12 committed ingestion batches');
    $start=hrtime(true);$delivered=0;
    for($i=0;$i<20;$i++) {
        $step=$rep->work(100);$delivered+=$step['delivered'];
        if($step['claimed']===0)break;
        if($step['retry']||$step['dead']||$step['stale'])throw new RuntimeException('Replicator failed during measured workload.');
    }
    $repSeconds=$elapsed($start);$repRps=$count/$repSeconds;
    $check($delivered===$count,'all 300 events replicate to ClickHouse under bounded batched throughput');
    $range=['project_id'=>$project,'from'=>$from,'to'=>$to];$latencies=[];
    for($i=0;$i<12;$i++) {
        $started=hrtime(true);$stats=$store->aggregate($range);
        $latencies[]=($elapsed($started))*1000;
        if($stats['total']!==$count || $stats['by_level']['error']!==60)
            throw new RuntimeException('Measured aggregate differs from PostgreSQL snapshot.');
    }
    sort($latencies,SORT_NUMERIC);$p95=$latencies[(int)ceil(count($latencies)*0.95)-1];
    $check(count($latencies)===12,'12 real ClickHouse aggregation queries matched source data');
    $s3=new S3CompatibleDisk(['endpoint'=>'http://127.0.0.1:'.getenv('S3_TEST_PORT'),'bucket'=>'cajeer-test',
        'access_key'=>'TESTACCESS','secret_key'=>'secret-test-signing-key','region'=>'us-east-1',
        'path_style'=>true,'allow_insecure'=>true]);
    $archive=new ArchiveLifecycle($pdo,$s3);$month=gmdate('Y-m');
    $started=hrtime(true);$staged=$archive->stage($project,$month,500);
    $stageSeconds=$elapsed($started);
    $check($staged['staged']===$count,'monthly PostgreSQL partition stages 300 original snapshots');
    $started=hrtime(true);$offloaded=$archive->offload($project,$month,500);
    $coldSeconds=$elapsed($started);
    $check($offloaded['offloaded']===$count && $archive->status($project)['warm_rows']===0,
        '300 snapshots are SHA-256 verified on S3 before warm deletion');
    $object=$archive->coldObjects($project)[0]??null;
    if(!$object)throw new RuntimeException('Missing cold segment metadata.');
    $started=hrtime(true);$restored=$archive->restoreCold($project,$object['id']);
    $restoreSeconds=$elapsed($started);
    $check($restored['restored']===$count && $archive->restoreHot($project,$month,500)['restored']===0,
        'verified S3 restore is lossless and leaves already-hot events unchanged');
    $check($roll->verify($project,$from,$to)['match'],'archival round trip does not change searchable rollup statistics');
    $check($archive->purgeCold($project,$object['id'])['deleted']===1 &&
           $archive->purgeWarm($project,$month,500)['purged']===$count,
           'temporary benchmark copies removed from S3 and warm partition');
    $minIngest=$number('CAJEER_CERT_MIN_INGEST_EPS',10.0);
    $minRep=$number('CAJEER_CERT_MIN_REPLICATION_EPS',10.0);
    $maxP95=$number('CAJEER_CERT_MAX_ANALYTICS_P95_MS',5000.0);
    $check($ingestRps >= $minIngest,'actual PostgreSQL ingestion throughput meets configured minimum');
    $check($repRps >= $minRep,'actual ClickHouse replication throughput meets configured minimum');
    $check($p95 <= $maxP95,'actual ClickHouse aggregate p95 meets configured maximum');
    $metrics=['events'=>$count,'ingest_events_per_second'=>round($ingestRps,2),
        'replication_events_per_second'=>round($repRps,2),'aggregate_p95_ms'=>round($p95,2),
        'queries'=>count($latencies),'stage_events_per_second'=>round($count/$stageSeconds,2),
        'cold_events_per_second'=>round($count/$coldSeconds,2),
        'restore_events_per_second'=>round($count/$restoreSeconds,2),
        'min_ingest_events_per_second'=>$minIngest,'min_replication_events_per_second'=>$minRep,
        'max_aggregate_p95_ms'=>$maxP95];
    echo 'PERFORMANCE_JSON:'.json_encode($metrics,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    echo "Analytics/storage measured load: $n real checks passed\n";
} finally {
    $admin->exec('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
}
