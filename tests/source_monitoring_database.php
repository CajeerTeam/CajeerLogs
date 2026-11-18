<?php
declare(strict_types=1);
use Cajeer\Logs\Database\{MigrationRunner,TransactionManager};
use Cajeer\Logs\Database\Repository\{ProjectRepository,SourceRepository,SourceMonitoring,TokenRepository};
use Cajeer\Logs\Http\Request;
use Cajeer\Logs\Kernel\Application;
if(getenv('CAJEER_TEST_DATABASE')!=='1'){fwrite(STDERR,"Set CAJEER_TEST_DATABASE=1 on a disposable PostgreSQL instance.\n");exit(78);}
$root=dirname(__DIR__);$base=require $root.'/core/bootstrap.php';$base->boot();
$admin=$base->container()->get(PDO::class);$schema='cl_srcmon_'.bin2hex(random_bytes(6));$admin->exec('CREATE SCHEMA "'.$schema.'"');$n=0;
function proof(bool $ok,string $message):void {global $n;if(!$ok)throw new RuntimeException('FAIL '.$message);++$n;echo "PASS $message\n";}
try {
    $app=new Application($root);$app->boot();$app->config()->set('database.schema',$schema);$app->config()->set('app.env','test');
    $pdo=$app->container()->get(PDO::class);$app->container()->get(MigrationRunner::class)->migrate();
    $projects=new ProjectRepository($pdo);$p=$projects->create('Monitoring A','mon-a');$other=$projects->create('Monitoring B','mon-b');
    $sources=new SourceRepository($pdo);$s=$sources->create($p,'Web','web');$s2=$sources->create($other,'Other','other');
    $tokens=new TokenRepository($pdo);$key=$tokens->create('HTTP',['logs:write','logs:read'],$p,$s)['token'];
    $event=['timestamp'=>gmdate('Y-m-d\TH:i:s\Z'),'source'=>'web','level'=>'error','message'=>'monitor real HTTP'];
    $request=static fn(string $id):Request=>new Request('POST','/api/v1/ingest',['Content-Type'=>'application/json','Authorization'=>'Bearer '.$key,'Idempotency-Key'=>$id],[],json_encode($event,JSON_THROW_ON_ERROR),'127.0.0.1');
    $first=$app->handle($request('source-monitoring-1'));proof($first->status===201,'HTTP ingestion commits source metrics');
    $second=$app->handle($request('source-monitoring-1'));proof($second->status===200,'idempotency replay acknowledged');
    $sRow=$sources->find($p,$s);proof($sRow['accepted_events_total']==='1','replay does not change accepted counter');
    $st=$sources->statistics($p,$s);$m=$st['ingestion'];
    proof($m['accepted_60m']===1 && $m['accepted_24h']===1 && $m['rejected_24h']===0,'committed admission counted only once');
    proof($st['events']===1 && $m['recent_stored_sample']['size']===1 && $m['recent_stored_sample']['errors']===1,'log counts and sampled error metrics from stored events');
    proof(count($m['hourly'])===24 && array_sum(array_column($m['hourly'],'accepted'))===1,'24 hourly series zero-filled');
    proof($m['health']['state']==='receiving' && !$m['health']['network_availability_checked'],'admission health is not network availability');
    $tx=new TransactionManager($pdo);
    $tx->run(fn()=> $sources->recordPolicyRejection($p,[$s=>3]));
    $m=$sources->statistics($p,$s)['ingestion'];proof($m['rejected_60m']===3 && abs($m['rejection_percent_24h']-75)<0.01,'policy rejections and ratio use committed counters');
    $pdo->beginTransaction();$sources->admit($p,[$s=>7],500);$pdo->rollBack();
    proof($sources->statistics($p,$s)['ingestion']['accepted_60m']===1,'rolled back admissions do not change metrics');
    proof($sources->statistics($other,$s2)['ingestion']['accepted_24h']===0,'metrics isolated by project and source');
    $wrong=$app->handle(new Request('GET','/api/v1/projects/'.$other.'/sources/'.$s2.'/stats',['Authorization'=>'Bearer '.$key],['idle_minutes'=>60],'','127.0.0.1'));
    proof($wrong->status===403,'source-bound token cannot read foreign project telemetry');
    $bad=$app->handle(new Request('GET','/api/v1/projects/'.$p.'/sources/'.$s.'/stats',['Authorization'=>'Bearer '.$key],['idle_minutes'=>'0'],'','127.0.0.1'));
    proof($bad->status===422,'invalid health threshold rejected');
    $pdo->prepare("INSERT INTO source_ingest_minutes(project_id,source_id,minute,accepted) VALUES (:project,:source,clock_timestamp()-interval '33 days',17)")->execute(['project'=>$p,'source'=>$s]);
    $tx->run(function()use($pdo){proof((new SourceMonitoring($pdo))->prune(1)===1,'bounded cleanup removes expired bucket');});
    proof($sources->statistics($p,$s)['ingestion']['accepted_24h']===1,'cleanup preserves active window');
    echo "Source Monitoring PostgreSQL: $n checks passed\n";
} finally {$admin->exec('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');}
