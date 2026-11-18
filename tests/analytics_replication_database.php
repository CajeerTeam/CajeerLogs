<?php
declare(strict_types=1);

use Cajeer\Logs\Analytics\{AnalyticsReplicator,ClickHouseAnalyticsStore,ClickHouseMigrator,ClickHouseHttpClient};
use Cajeer\Logs\Database\{MigrationRunner,TransactionManager};
use Cajeer\Logs\Database\Repository\{ProjectRepository,SourceRepository,TokenRepository};
use Cajeer\Logs\Http\Request;
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Log\{IngestValidator,LogRepository};

if (getenv('CAJEER_TEST_DATABASE')!=='1' || getenv('CAJEER_TEST_CLICKHOUSE')!=='1') {
    fwrite(STDERR,"Requires disposable PostgreSQL + ClickHouse; set CAJEER_TEST_DATABASE=1 and CAJEER_TEST_CLICKHOUSE=1.\n"); exit(78);
}
$root=dirname(__DIR__);$base=require $root.'/core/bootstrap.php';$base->boot();
$admin=$base->container()->get(PDO::class);$schema='cl_repl_'.bin2hex(random_bytes(7));
$admin->exec('CREATE SCHEMA "'.$schema.'"');
$n=0;
function prove(bool $yes,string $title):void {global $n;if(!$yes)throw new RuntimeException('FAIL '.$title);$n++;echo "PASS $title\n";}
try {
    $app=new Application($root);$app->boot();$app->config()->set('database.schema',$schema);
    $pdo=$app->container()->get(PDO::class);$app->container()->get(MigrationRunner::class)->migrate();
    $client=$app->container()->get(ClickHouseHttpClient::class);
    (new ClickHouseMigrator($client,$root.'/migrations/clickhouse'))->migrate();
    $worker=new AnalyticsReplicator($pdo,$client);
    $store=new ClickHouseAnalyticsStore($client,true);
    $projects=new ProjectRepository($pdo);$p=$projects->create('Analytics A','analytics-a');$q=$projects->create('Analytics B','analytics-b');
    $sources=new SourceRepository($pdo);$srcA=$sources->create($p,'App','app');$srcB=$sources->create($q,'App','app');
    $tokens=new TokenRepository($pdo);$tokenA=$tokens->create('Writer A',['logs:write'],$p)['id'];$tokenB=$tokens->create('Writer B',['logs:write'],$q)['id'];
    $repo=new LogRepository($pdo);$time=gmdate('Y-m-d\TH:i:s\Z',time()-30);
    $persist=static function(string $proj,string $src,string $token,string $message)use($pdo,$repo,$time):string {
        $raw=json_encode(['source'=>'app','level'=>'error','timestamp'=>$time,'message'=>$message,'context'=>['test'=>true]],JSON_THROW_ON_ERROR);
        $events=(new IngestValidator())->parse(new Request('POST','/api/v1/ingest',['Content-Type'=>'application/json'],[],$raw));
        return (new TransactionManager($pdo))->run(fn()=>$repo->storeBatch($events,$proj,['app'=>$src],$token))[0];
    };
    $eventA=$persist($p,$srcA,$tokenA,'original A');$eventB=$persist($q,$srcB,$tokenB,'original B');
    prove((int)$pdo->query('SELECT count(*) FROM analytics_replication_outbox')->fetchColumn()===2,'outbox atomically captures PostgreSQL writes');
    $pdo->beginTransaction();
    try {
        $raw=json_encode(['source'=>'app','level'=>'error','timestamp'=>$time,'message'=>'rolled back'],JSON_THROW_ON_ERROR);
        $events=(new IngestValidator())->parse(new Request('POST','/api/v1/ingest',['Content-Type'=>'application/json'],[],$raw));
        $repo->storeBatch($events,$p,['app'=>$srcA],$tokenA);
    } finally { $pdo->rollBack(); }
    prove((int)$pdo->query('SELECT count(*) FROM analytics_replication_outbox')->fetchColumn()===2,'failed transaction creates no outbox job');
    $claims=$worker->claim(10);prove(count($claims)===2&&$worker->claim(10)===[],'leased jobs cannot be claimed twice');
    // Simulate the worst case: ClickHouse accepted the insert but PostgreSQL ACK was lost.
    $store->recordBatch(array_map(AnalyticsReplicator::encode(...),$claims));
    $stale=$claims[0];$pdo->exec("UPDATE analytics_replication_outbox SET lease_until=clock_timestamp()-interval '1 second' WHERE status='running'");
    $results=$worker->work(10);prove($results['delivered']===2,'replay after lost acknowledgement uses immutable revision');
    prove($worker->finish($stale,true)==='stale','expired lease cannot acknowledge new claim');
    $range=['from'=>gmdate('Y-m-d\TH:i:s\Z',time()-3600),'to'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600)];
    $a=$store->aggregate(['project_id'=>$p]+$range);$b=$store->aggregate(['project_id'=>$q]+$range);
    prove($a['total']===1&&$b['total']===1&&$a['by_level']['error']===1,'FINAL deduplicates repeated revisions and isolates projects');
    $revision=(int)$pdo->query("SELECT revision FROM analytics_replication_outbox WHERE event_id='$eventA'")->fetchColumn();
    $pdo->exec("UPDATE log_events SET message='sanitized A' WHERE id='$eventA'");
    prove((int)$pdo->query("SELECT revision FROM analytics_replication_outbox WHERE event_id='$eventA'")->fetchColumn()>$revision,'redaction invalidates old revision');
    prove($worker->work(10)['delivered']===1,'changed event version replicates');
    $verify=$client->select('SELECT message FROM '.$client->qualified('replicated_log_events').' FINAL WHERE project_id={project_id:UUID} AND id={id:UUID}', ['project_id'=>$p,'id'=>$eventA]);
    prove(count($verify)===1&&$verify[0]['message']==='sanitized A','newest event body replaces original');
    $pdo->exec("DELETE FROM log_events WHERE id='$eventA'");
    prove($worker->work(10)['delivered']===1,'deletion tombstone replicates');
    prove($store->aggregate(['project_id'=>$p]+$range)['total']===0,'tombstone hides deleted event across all revisions');
    $eventC=$persist($p,$srcA,$tokenA,'historic C');
    $pdo->exec("DELETE FROM analytics_replication_outbox WHERE event_id='$eventC'");
    $backfill=$worker->backfill($p,100);
    prove($backfill['enqueued']===1&&$backfill['end_of_scan'],'cursor backfill restores pre-existing missing event without duplicating tracked rows');
    prove($worker->work(10)['delivered']===1&&$store->aggregate(['project_id'=>$p]+$range)['total']===1,'backfilled event delivered');
    $again=$worker->backfill($p,100);
    prove($again['scanned']===0&&$again['enqueued']===0,'restartable backfill does not restart from the beginning');
    prove($worker->replay($p,20,'completed')>=1,'operator can replay completed rows after ClickHouse restore');
    $worker->work(20);
    prove($store->aggregate(['project_id'=>$p]+$range)['total']===1,'replay does not inflate analytical totals');
    prove($worker->status()['counts']['dead']===0,'no terminal replication errors');
    echo "Analytics replication PostgreSQL + ClickHouse: $n checks passed\n";
} finally {
    $admin->exec('DROP SCHEMA "'.$schema.'" CASCADE');
}
