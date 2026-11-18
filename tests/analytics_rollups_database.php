<?php
declare(strict_types=1);

use Cajeer\Logs\Analytics\PostgresRollupStore;
use Cajeer\Logs\Database\{MigrationRunner,TransactionManager};
use Cajeer\Logs\Database\Repository\{ProjectRepository,SourceRepository,TokenRepository};
use Cajeer\Logs\Http\Request;
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Log\{IngestValidator,LogQuery,LogRepository};
if (getenv('CAJEER_TEST_DATABASE')!=='1') { fwrite(STDERR,"Requires disposable PostgreSQL; set CAJEER_TEST_DATABASE=1.\n");exit(78); }
$root=dirname(__DIR__);$base=require $root.'/core/bootstrap.php';$base->boot();
$admin=$base->container()->get(PDO::class);$schema='cl_roll_'.bin2hex(random_bytes(7));
$admin->exec('CREATE SCHEMA "'.$schema.'"');$n=0;
function prove(bool $truth,string $name):void {global $n;if(!$truth)throw new RuntimeException('FAIL '.$name);++$n;echo "PASS $name\n";}
try {
    $app=new Application($root);$app->boot();$app->config()->set('database.schema',$schema);
    $pdo=$app->container()->get(PDO::class);$app->container()->get(MigrationRunner::class)->migrate();
    $projects=new ProjectRepository($pdo);$p=$projects->create('Rollup One','rollup-one');$other=$projects->create('Rollup Two','rollup-two');
    $sources=new SourceRepository($pdo);$s=$sources->create($p,'App','app');$s2=$sources->create($other,'App','app');
    $tokens=new TokenRepository($pdo);$token=$tokens->create('Writer',['logs:write'],$p)['id'];$token2=$tokens->create('Writer',['logs:write'],$other)['id'];
    $logs=new LogRepository($pdo);$roll=new PostgresRollupStore($pdo);
    $t=gmdate('Y-m-d\TH:i:00\Z',time()-180);$minute=new DateTimeImmutable($t);
    $ts=static fn(int $s):string=>$minute->modify('+'.$s.' seconds')->format('Y-m-d\TH:i:s\Z');
    $post=static function(string $project,string $source,string $token,string $level,string $message,string $time)use($pdo,$logs):string {
        $body=json_encode(['timestamp'=>$time,'source'=>'app','service'=>'web','host'=>'edge01','level'=>$level,'message'=>$message],JSON_THROW_ON_ERROR);
        $parsed=(new IngestValidator())->parse(new Request('POST','/api/v1/ingest',['Content-Type'=>'application/json'],[],$body));
        return (new TransactionManager($pdo))->run(fn()=>$logs->storeBatch($parsed,$project,['app'=>$source],$token))[0];
    };
    $a=$post($p,$s,$token,'info','one',$ts(15));$b=$post($p,$s,$token,'error','two',$ts(35));
    $o=$post($other,$s2,$token2,'critical','foreign',$ts(15));
    $range=['from'=>$ts(0),'to'=>$ts(60)];
    $q=LogQuery::parse(['project_id'=>$p]+$range,'stats');
    prove($logs->statistics($q)['total']===2,'before backfill statistics still read the complete raw dataset');
    $res=$roll->backfill($p,1000);prove($res['scanned']===2 && $res['applied']===0 && $res['complete'],'triggered events are not double-counted by historical backfill');
    $again=$roll->backfill($p,1000);prove($again['scanned']===0&&$again['complete'],'completed backfill is repeatable');
    $stats=$logs->statistics($q);
    prove($stats['total']===2&&$stats['by_level']['info']===1&&$stats['by_level']['error']===1&&$stats['source_count']===1,'rollup API returns exact scoped dimensional statistics');
    prove($stats['buckets'][0]['count']===2&&$stats['buckets'][0]['errors']===1,'rollups preserve chronological error counts');
    prove(count($stats['level_buckets'])===2&&array_sum(array_column($stats['level_buckets'],'count'))===2,'level time series reconciles to exact rollup counts');
    prove($logs->statistics(LogQuery::parse(['project_id'=>$p,'from'=>$ts(25),'to'=>$ts(50)],'stats'))['total']===1,'partial-minute boundary reads exact raw edge');
    prove($logs->statistics(LogQuery::parse(['project_id'=>$p,'from'=>$ts(10),'to'=>$ts(20)],'stats'))['total']===1,'shorter-than-one-minute window does not overcount');
    prove($logs->statistics(LogQuery::parse(['project_id'=>$p,'from'=>$ts(0),'to'=>$ts(60),'level'=>'error'],'stats'))['total']===1,'level filter is applied to counters');
    prove($logs->statistics(LogQuery::parse(['project_id'=>$other]+$range,'stats'))['total']===1,'different project cannot use first project rollups');
    $pdo->beginTransaction();$pdo->exec("UPDATE log_events SET level='emergency' WHERE id='$a'");$pdo->rollBack();
    prove($logs->statistics($q)['by_level']['info']===1,'rolled-back event update cannot change counters');
    $pdo->exec("UPDATE log_events SET level='critical', occurred_at=occurred_at+interval '1 minute',service='backend' WHERE id='$a'");
    $stats=$logs->statistics($q);prove($stats['total']===1&&$stats['by_level']['info']===0&&$stats['by_level']['error']===1,'update retracts old bucket and dimensions');
    $q2=LogQuery::parse(['project_id'=>$p,'from'=>$ts(0),'to'=>$ts(120),'interval'=>'minute'],'stats');
    $stats=$logs->statistics($q2);prove($stats['total']===2&&$stats['by_level']['critical']===1&&count($stats['buckets'])===2,'moved event appears in the correct minute');
    prove(array_sum(array_column($stats['level_buckets'],'count'))===$stats['total']&&count($stats['level_buckets'])===2,'level chart tracks changed timestamp and level');
    $pdo->exec("DELETE FROM log_events WHERE id='$b'");
    prove($logs->statistics($q2)['total']===1,'delete retracts counted event');
    prove(array_sum(array_column($logs->statistics($q2)['level_buckets'],'count'))===1,'delete retracts level chart contribution');
    prove((int)$pdo->query("SELECT count(*) FROM analytics_rollup_minutes WHERE project_id='$p' AND events=0")->fetchColumn()===0,'zero-count dimension groups are removed after update/delete');
    prove($roll->verify($p,$ts(0),$ts(120))['match'],'full dimensions reconcile to raw events');
    // Deliberate same-total dimensional corruption must be caught by verify.
    $pdo->exec("UPDATE analytics_rollup_minutes SET level='warning' WHERE project_id='$p' AND level='critical'");
    prove($roll->verify($p,$ts(0),$ts(120))['mismatched_groups']===2,'verifier detects dimensional drift even if total is equal');
    echo "Analytics rollups PostgreSQL: $n checks passed\n";
} finally {
    $admin->exec('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
}
