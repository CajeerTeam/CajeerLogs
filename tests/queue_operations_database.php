<?php
declare(strict_types=1);
use Cajeer\Logs\Audit\AuditLogger;
use Cajeer\Logs\Database\{MigrationRunner,TransactionManager,ConnectionFactory};
use Cajeer\Logs\Database\Repository\{ProjectRepository,SourceRepository,TokenRepository};
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Log\IngestEvent;
use Cajeer\Logs\Observability\OperationsMetrics;
use Cajeer\Logs\Queue\{PostgresQueueDriver,QueueOperations,QueueAccounting,QueueFairness,BackpressurePolicy,RetryPolicy,WorkerPolicy};
if(getenv('CAJEER_TEST_DATABASE')!=='1'){fwrite(STDERR,"Disposable PostgreSQL required.\n");exit(1);}
$root=dirname(__DIR__);require $root.'/core/bootstrap.php';
$baseApp=new Application($root);$baseApp->boot();$base=$baseApp->container()->get(PDO::class);
$schema='cl_queueops_'.bin2hex(random_bytes(8));$base->exec('CREATE SCHEMA "'.$schema.'"');$checks=0;
function proof(bool $ok,string $label): void { global $checks; if(!$ok) throw new RuntimeException('FAIL '.$label); ++$checks;echo "PASS $label\n"; }
try {
    $app=new Application($root);$app->boot();$app->config()->set('database.schema',$schema);$app->config()->set('app.env','test');
    $pdo=$app->container()->get(PDO::class);
    (new MigrationRunner($pdo,$root.'/migrations/pgsql',$schema))->migrate();
    $projects=new ProjectRepository($pdo);$sources=new SourceRepository($pdo);$tokens=new TokenRepository($pdo);
    $project=$projects->create('Queue ops','queue-ops');$source=$sources->create($project,'API','app');
    $token=$tokens->create('Writer',['logs:write'],$project,$source);
    $queue=new PostgresQueueDriver($pdo,1000,new WorkerPolicy(['batch_jobs'=>1]),new RetryPolicy(),new BackpressurePolicy(1000,100000,0,100,0));
    $ops=new QueueOperations($pdo);$account=new QueueAccounting($pdo);
    $status=$ops->status();proof(!$status['paused'] && $status['generation']===0,'dispatch starts enabled');
    $event=new IngestEvent('app','info','queue observability event',(object)[],gmdate('Y-m-d\TH:i:s\Z'));
    $send=static fn(string $key)=>(new TransactionManager($pdo))->run(fn()=>$queue->enqueue($token['id'],$project,hash('sha256',$key),hash('sha256','body:'.$key),[$event],['app'=>$source]));
    $initial=$send('ops-0');proof(isset($initial['job_id']) && $initial['replayed']===false,'first durable enqueue succeeds');
    proof($send('ops-0')['job_id']===$initial['job_id'],'replay never generates second job');
    $c=(new QueueFairness($pdo))->snapshot($project)[0];proof($c['accepted_jobs_total']===1 && $c['accepted_events_total']===1,'accepted counters increment once');
    $paused=$ops->setPaused(true,'maintenance');proof($paused['paused'] && $paused['generation']===1,'operator pause persists');
    proof($ops->setPaused(true,'ignored')['generation']===1,'idempotent pause does not increment generation');
    proof($queue->claim()===null,'paused queue cannot claim ready job');
    $second=$send('ops-1');proof(isset($second['job_id']),'paused dispatch does not block durable admission');
    proof($ops->drain(0)['drained']===true,'idle queue drains without issuing new claim');
    $other=$app->container()->get(ConnectionFactory::class)->make('pgsql');
    $otherOps=new QueueOperations($other);
    (new TransactionManager($other))->run(function()use($other):void {
        proof(!QueueOperations::canClaim($other),'independent worker also observes paused state');
    });
    $resumed=$ops->setPaused(false);proof(!$resumed['paused'] && $resumed['generation']===2,'resume advances durable generation');
    $other->beginTransaction();proof(QueueOperations::canClaim($other),'new claim holds shared control lock');
    $pdo->beginTransaction();$pdo->exec("SET LOCAL lock_timeout='100ms'");
    try { $pdo->exec("UPDATE queue_dispatch_control SET paused=true WHERE queue='ingest-v1'");proof(false,'pause cannot race shared claim lock'); }
    catch(PDOException $e){proof(($e->errorInfo[0]??$e->getCode())==='55P03','pause waits for existing worker claim transaction');}
    $pdo->rollBack();$other->commit();
    $claim=$queue->claim();proof($claim!==null && isset($claim['id']),'claim resumes after control transition');
    proof($queue->process($claim)==='completed','first claimed job commits');
    $remaining=$queue->claim();proof($remaining!==null && $queue->process($remaining)==='completed','second claimed job commits');
    $c=(new QueueFairness($pdo))->snapshot($project)[0];
    proof($c['completed_jobs_total']===2 && $c['completed_events_total']===2,'durable completion counters exactly two');
    proof($c['accepted_jobs_total']===2 && $c['accepted_events_total']===2,'accepted counters remain exact');
    proof($c['delivery_le_300']<=2 && $c['delivery_le_1']<=$c['delivery_le_5'] && $c['delivery_le_5']<=$c['delivery_le_30'],'latency histogram cumulative buckets ordered');
    proof($account->verify()['valid'] && (new QueueFairness($pdo))->verify()['valid'],'capacity and project counters remain consistent');
    proof((new AuditLogger($pdo))->verify()['valid'],'pause/resume recorded in immutable audit chain');
    $metrics=(new OperationsMetrics($app))->render($pdo);
    proof(str_contains($metrics,'cajeerlogs_queue_accepted_events_total 2') && str_contains($metrics,'cajeerlogs_queue_completed_jobs_total 2'),'prometheus counters reflect committed PostgreSQL state');
    proof(str_contains($metrics,'cajeerlogs_queue_delivery_latency_seconds_bucket{le="+Inf"} 2') && str_contains($metrics,'cajeerlogs_queue_dispatch_paused 0'),'histogram and dispatch gauge exported');
    proof((new MigrationRunner($pdo,$root.'/migrations/pgsql',$schema))->status()['integrity']==='verified','new migration fingerprint verified');
    echo "Queue Operations Database: $checks checks passed\n";
} finally { $base->exec('DROP SCHEMA "'.$schema.'" CASCADE'); }
