<?php
declare(strict_types=1);
use Cajeer\Logs\Database\{MigrationRunner,TransactionManager,ConnectionFactory};
use Cajeer\Logs\Database\Repository\{ProjectRepository,SourceRepository,TokenRepository};
use Cajeer\Logs\Http\HttpException;
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Log\IngestEvent;
use Cajeer\Logs\Queue\{PostgresQueueDriver,BackpressurePolicy,QueueFairness,QueueAccounting,RetryPolicy,WorkerPolicy};
if (getenv('CAJEER_TEST_DATABASE')!=='1') {fwrite(STDERR,"Disposable PostgreSQL required.\n");exit(1);}
$root=dirname(__DIR__);require $root.'/core/bootstrap.php';$baseApp=new Application($root);$baseApp->boot();$base=$baseApp->container()->get(PDO::class);
$schema='cl_fair_'.bin2hex(random_bytes(8));$base->exec('CREATE SCHEMA "'.$schema.'"');$checks=0;
function proof(bool $good,string $label): void {global $checks;if (!$good) throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";$checks++;}
try {
    $app=new Application($root);$app->boot();$app->config()->set('database.schema',$schema);$app->config()->set('app.env','test');
    $pdo=$app->container()->get(PDO::class);(new MigrationRunner($pdo,$root.'/migrations/pgsql',$schema))->migrate();
    $projectRepo=new ProjectRepository($pdo);$sourceRepo=new SourceRepository($pdo);$tokenRepo=new TokenRepository($pdo);
    $a=$projectRepo->create('Fair A','fair-a');$b=$projectRepo->create('Fair B','fair-b');
    $sourceA=$sourceRepo->create($a,'Service','app');$sourceB=$sourceRepo->create($b,'Service','app');
    $tokenA=$tokenRepo->create('Producer A',['logs:write'],$a,$sourceA);$tokenB=$tokenRepo->create('Producer B',['logs:write'],$b,$sourceB);
    $fair=new QueueFairness($pdo);$account=new QueueAccounting($pdo);
    $global=new BackpressurePolicy(1000,100000,0,100,0);
    $queue=new PostgresQueueDriver($pdo,1000,new WorkerPolicy(['batch_jobs'=>4]),new RetryPolicy(),$global);
    $event=new IngestEvent('app','info','fairness event',(object)[],gmdate('Y-m-d\TH:i:s\Z'));
    $send=static function(string $project,string $source,array $token,string $key)use($pdo,$queue,$event) {
        return (new TransactionManager($pdo))->run(fn()=>$queue->enqueue($token['id'],$project,hash('sha256',$key),hash('sha256','body:'.$key),[$event],['app'=>$source]));
    };
    $fair->configure($a,['weight'=>1,'max_jobs'=>2,'max_events'=>2,'max_bytes'=>0,'max_running_jobs'=>1]);
    $first=$send($a,$sourceA,$tokenA,'a-0');$second=$send($a,$sourceA,$tokenA,'a-1');
    proof(is_array($first)&&is_array($second),'project A admits two durable jobs');
    $blocked=$send($a,$sourceA,$tokenA,'a-2');
    proof($blocked instanceof HttpException && $blocked->status===503 && $blocked->error==='queue_project_full','project-specific limit blocks A without global pause');
    proof($send($a,$sourceA,$tokenA,'a-0')['job_id']===$first['job_id'],'idempotent replay remains available at project cap');
    $third=$send($b,$sourceB,$tokenB,'b-0');
    proof(is_array($third) && $account->status()['admission_paused']===0,'other project continues accepting');
    $aState=$fair->snapshot($a)[0];$bState=$fair->snapshot($b)[0];
    proof($aState['active_jobs']===2 && $aState['active_events']===2 && $aState['rejected']===1 && $bState['active_jobs']===1,'exact isolated counters');
    $claims=[];for($i=0;$i<3;$i++){$claim=$queue->claim();if($claim && isset($claim['id']))$claims[]=$claim;}
    $running=$fair->snapshot($a)[0];
    proof($running['running_jobs']<=1,'per-project inflight ceiling under sequential claims');
    // Ensure all active jobs complete and accounting returns to zero.
    foreach($claims as $claim) proof($queue->process($claim)==='completed','claimed job committed');
    while(($next=$queue->claim())!==null) {if(!isset($next['id']))continue;proof($queue->process($next)==='completed','remaining queued job committed');}
    proof($fair->snapshot($a)[0]['active_jobs']===0 && $fair->snapshot($b)[0]['active_jobs']===0 && $account->verify()['valid'] && $fair->verify()['valid'],'global and project accounting agree after completion');
    // Test weighted scheduling on a fresh queue with two fully available projects.
    $fair->configure($a,['weight'=>1,'max_jobs'=>0,'max_events'=>0,'max_bytes'=>0,'max_running_jobs'=>0]);
    $fair->configure($b,['weight'=>4,'max_jobs'=>0,'max_events'=>0,'max_bytes'=>0,'max_running_jobs'=>0]);
    for($i=0;$i<20;$i++) {$send($a,$sourceA,$tokenA,'weighted-a-'.$i);$send($b,$sourceB,$tokenB,'weighted-b-'.$i);}
    $distribution=[$a=>0,$b=>0];$claims=[];
    for($i=0;$i<20;$i++) {
        $claim=$queue->claim();if (!$claim || !isset($claim['id']))throw new RuntimeException('Claim unexpectedly empty');
        $distribution[$claim['project_id']]++;$claims[]=$claim;
    }
    proof($distribution[$b]>=13 && $distribution[$a]<=7,'weighted dispatch favors weight 4 project without starvation');
    proof($distribution[$a]>0,'low-weight project is not starved');
    $other=$app->container()->get(ConnectionFactory::class)->make('pgsql');
    $secondWorker=new PostgresQueueDriver($other,1000,null,new RetryPolicy(),$global);
    $claim=$secondWorker->claim();proof($claim!==null && isset($claim['id']),'independent worker can claim separate durable job');
    $claims[]=$claim;
    foreach ($claims as $c) $queue->process($c);
    while(($c=$queue->claim())!==null) {if(isset($c['id']))$queue->process($c);}
    proof($fair->verify()['valid'] && $account->verify()['valid'],'parallel claims reconcile');
    // Simulate accidental manual counter damage and repair through explicit maintenance.
    $pdo->exec('UPDATE queue_project_state SET active_events=active_events+1 WHERE project_id='.$pdo->quote($a).'::uuid');
    proof(!$fair->verify()['valid'],'read-only verification detects project drift');
    proof($fair->reconcile()['valid'] && $fair->verify()['valid'],'reconciliation repairs only project counters');
    $missing=$pdo->prepare("SELECT 1 FROM pg_catalog.pg_indexes WHERE schemaname=current_schema() AND indexname='jobs_ingest_project_ready'");$missing->execute();proof($missing->fetchColumn()!==false,'ready-project index installed');
    proof((new MigrationRunner($pdo,$root.'/migrations/pgsql',$schema))->status()['integrity']==='verified','migration schema fingerprint verified');
    echo "Queue fairness database: $checks checks passed\n";
} finally { $base->exec('DROP SCHEMA "'.$schema.'" CASCADE'); }
