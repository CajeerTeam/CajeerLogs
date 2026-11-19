<?php
declare(strict_types=1);
/** Real database state is carried between independent PHP processes during service stop/start. */
use Cajeer\Logs\Analytics\{AnalyticsReplicator,ClickHouseAnalyticsStore,ClickHouseHttpClient,ClickHouseMigrator,PostgresRollupStore};
use Cajeer\Logs\Database\{MigrationRunner,TransactionManager};
use Cajeer\Logs\Database\Repository\{ProjectRepository,SourceRepository,TokenRepository};
use Cajeer\Logs\Http\Request;
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Log\{IngestValidator,LogRepository};

if (getenv('APP_ENV') !== 'test' || getenv('CAJEER_TEST_DATABASE') !== '1'
    || getenv('CAJEER_TEST_CLICKHOUSE') !== '1') {
    fwrite(STDERR, "Only disposable PostgreSQL and ClickHouse tests are permitted.\n"); exit(78);
}
$phase=$argv[1]??''; $file=$argv[2]??'';
if (!in_array($phase,['seed','outage','recover','verify','cleanup'],true) || $file==='') exit(64);
$adminApp=new Application(dirname(__DIR__));$adminApp->boot();
$admin=$adminApp->container()->get(PDO::class);
$state=[];
if ($phase!=='seed') {
    if (!is_file($file)) throw new RuntimeException('Recovery state file is missing');
    $state=json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
    if (!preg_match('/^cl_failure_[a-f0-9]{12}$/D',(string)($state['schema']??'')))
        throw new RuntimeException('Invalid recovery test schema');
}
$schema=$state['schema']??('cl_failure_'.bin2hex(random_bytes(6)));
if ($phase==='cleanup') {
    $admin->exec('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
    echo "PASS disposable recovery schema removed\n";
    exit(0);
}
if ($phase==='seed') {
    $admin->exec('CREATE SCHEMA "'.$schema.'"');
    file_put_contents($file,json_encode(['schema'=>$schema],JSON_THROW_ON_ERROR));
    chmod($file,0600);
}
$app=new Application(dirname(__DIR__));$app->boot();
$app->config()->set('database.schema',$schema);
$pdo=$app->container()->get(PDO::class);
if ((string)$pdo->query('SELECT current_schema()')->fetchColumn()!==$schema)
    throw new RuntimeException('Recovery scenario is not in its disposable schema');
$ok=static function(bool $value,string $label):void {
    if (!$value) throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label."\n";
};
$rep=new AnalyticsReplicator($pdo,$app->container()->get(ClickHouseHttpClient::class));
$store=new ClickHouseAnalyticsStore($app->container()->get(ClickHouseHttpClient::class),true);
$roll=new PostgresRollupStore($pdo);
$range=['from'=>gmdate('Y-m-d\TH:i:00\Z',time()-3600),'to'=>gmdate('Y-m-d\TH:i:00\Z',time()+3600)];
$count=static function(string $project)use($pdo):int {
    $stmt=$pdo->prepare('SELECT count(*) FROM log_events WHERE project_id=CAST(:p AS uuid)');
    $stmt->execute(['p'=>$project]);return (int)$stmt->fetchColumn();
};
if ($phase==='seed') {
    $app->container()->get(MigrationRunner::class)->migrate();
    (new ClickHouseMigrator($app->container()->get(ClickHouseHttpClient::class),dirname(__DIR__).'/migrations/clickhouse'))->migrate();
    $p=(new ProjectRepository($pdo))->create('Recovery project','recovery');
    $other=(new ProjectRepository($pdo))->create('Other recovery project','other-recovery');
    $sources=new SourceRepository($pdo);$source=$sources->create($p,'app','app');$otherSource=$sources->create($other,'app','app');
    $tokens=new TokenRepository($pdo);$token=$tokens->create('writer',['logs:write'],$p)['id'];
    $otherToken=$tokens->create('other-writer',['logs:write'],$other)['id'];
    $logs=new LogRepository($pdo);$validator=new IngestValidator();
    $persist=static function(string $project,string $source,string $token,string $message)
         use($pdo,$logs,$validator):string {
        $raw=json_encode(['timestamp'=>gmdate('Y-m-d\TH:i:s\Z',time()-120),
            'source'=>'app','level'=>'error','service'=>'recovery','host'=>'node','message'=>$message],JSON_THROW_ON_ERROR);
        $batch=$validator->parse(new Request('POST','/api/v1/ingest',['Content-Type'=>'application/json'],[],$raw));
        return (new TransactionManager($pdo))->run(fn()=>$logs->storeBatch($batch,$project,['app'=>$source],$token))[0];
    };
    $events=[];
    for($i=0;$i<3;$i++) $events[]=$persist($p,$source,$token,'recover-'.$i);
    $otherId=$persist($other,$otherSource,$otherToken,'isolated');
    $ok($count($p)===3 && $count($other)===1,'four real events committed in isolated projects');
    $roll->backfill($p,100);
    $ok($roll->verify($p,$range['from'],$range['to'])['match'],'initial rollups match committed events');
    $claims=$rep->claim(1);
    $ok(count($claims)===1,'worker lease acquired before simulated worker crash');
    $state=['schema'=>$schema,'project'=>$p,'other'=>$other,'events'=>$events,'other_event'=>$otherId];
    $temporary=$file.'.partial';
    file_put_contents($temporary,json_encode($state,JSON_THROW_ON_ERROR));
    chmod($temporary,0600);rename($temporary,$file);
} elseif ($phase==='outage') {
    // Only ClickHouse is stopped. PostgreSQL ingestion and durable outbox remain available.
    $result=$rep->work(10);
    $ok($result['delivered']===0 && $result['retry']===3,
        'real ClickHouse service stop leaves pending replicated events');
    $ok($count($state['project'])===3 && $count($state['other'])===1,
        'PostgreSQL source data survives ClickHouse downtime');
} elseif ($phase==='recover') {
    // Expire the lease left behind by a killed worker and release retry backoff.
    $pdo->exec("UPDATE analytics_replication_outbox SET lease_until=clock_timestamp()-interval '1 second' WHERE status='running'");
    $pdo->exec("UPDATE analytics_replication_outbox SET available_at=clock_timestamp()-interval '1 second' WHERE status='pending'");
    $result=$rep->work(10);
    $ok($result['delivered']===4,'restarted worker reclaims abandoned lease and retries failed events');
    $total=$store->aggregate(['project_id'=>$state['project']]+$range)['total'];
    $other=$store->aggregate(['project_id'=>$state['other']]+$range)['total'];
    $ok($total===3 && $other===1,'ClickHouse reconciles and preserves project isolation');
    $ok($rep->replay($state['project'],10,'completed')===3,'completed events can be replayed safely');
    $ok($rep->work(10)['delivered']===3 &&
       $store->aggregate(['project_id'=>$state['project']]+$range)['total']===3,
       'replay after server restart does not double count');
} elseif ($phase==='verify') {
    $ok($count($state['project'])===3 && $count($state['other'])===1,
        'PostgreSQL container restart preserves committed source records');
    $ok($roll->verify($state['project'],$range['from'],$range['to'])['match'],
        'source rollups reconcile after PostgreSQL restart');
    $p=$pdo->prepare('UPDATE log_events SET message=:message WHERE id=CAST(:id AS uuid)');
    $p->execute(['message'=>'redacted after restart','id'=>$state['events'][0]]);
    $d=$pdo->prepare('DELETE FROM log_events WHERE id=CAST(:id AS uuid)');
    $d->execute(['id'=>$state['events'][1]]);
    $ok($rep->work(10)['delivered']===2,'update and delete replicate after database restart');
    $ok($store->aggregate(['project_id'=>$state['project']]+$range)['total']===2
      && $store->aggregate(['project_id'=>$state['other']]+$range)['total']===1,
      'ClickHouse tombstone and versioned update reconcile without cross-project effects');
    $ok($roll->verify($state['project'],$range['from'],$range['to'])['match'],
      'PostgreSQL rollups match current data after update and delete');
    $ok(($rep->status()['counts']['dead']??0)===0,'no replication dead-letter rows remain');
}
