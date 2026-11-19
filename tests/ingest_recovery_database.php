<?php
declare(strict_types=1);
/** Live PostgreSQL 17/18 fault exercise. Do not run against production. */
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Database\{MigrationRunner,TransactionManager};
use Cajeer\Logs\Database\Repository\{ProjectRepository,SourceRepository,TokenRepository};
use Cajeer\Logs\Queue\{PostgresQueueDriver,QueueAccounting,Worker,DeadLetterManager};
use Cajeer\Logs\Http\Request;
if (getenv('CAJEER_TEST_DATABASE')!=='1' || getenv('APP_ENV')!=='test' || !function_exists('posix_kill')) {
    throw new RuntimeException('Disposable test PostgreSQL and POSIX process signals required.');
}
$root=dirname(__DIR__); require $root.'/core/bootstrap.php';
$seed=new Application($root);$seed->boot();$base=$seed->container()->get(PDO::class);
$schema='cl_ingest_recovery_'.bin2hex(random_bytes(6));$base->exec('CREATE SCHEMA "'.$schema.'"');
$count=0;$child=null;
function assertRecovery(bool $ok,string $why): void {global $count;if (!$ok) throw new RuntimeException('FAIL: '.$why);$count++;echo 'PASS '.$why.PHP_EOL;}
try {
    $app=new Application($root);$app->boot();$cfg=$app->config();
    $cfg->set('database.schema',$schema);$cfg->set('app.env','test');
    $cfg->set('app.url','http://localhost:8080');$cfg->set('ingest.require_hmac',false);$cfg->set('api.rate_limit',600);
    $pdo=$app->container()->get(PDO::class);
    $app->container()->get(MigrationRunner::class)->migrate();
    $project=(new ProjectRepository($pdo))->create('Recovery','recovery');
    $source=(new SourceRepository($pdo))->create($project,'App','app');
    $token=(new TokenRepository($pdo))->create('Recovery writer',['logs:write'],$project,$source);
    $queue=$app->container()->get(PostgresQueueDriver::class);$account=new QueueAccounting($pdo);
    $body=json_encode(['timestamp'=>gmdate('Y-m-d\TH:i:s\Z'),'source'=>'app','level'=>'info','message'=>'unique-crash-recovery'],JSON_THROW_ON_ERROR);
    $send=function(string $key)use($app,$token,$body): array {
        $r=$app->handle(new Request('POST','/api/v1/ingest/async',[
            'Content-Type'=>'application/json','Authorization'=>'Bearer '.$token['token'],'Idempotency-Key'=>$key],[],$body));
        return [$r->status,json_decode($r->body,true,32,JSON_THROW_ON_ERROR)];
    };
    [$status,$receipt]=$send('crash-worker');
    assertRecovery($status===202 && isset($receipt['job_id']),'HTTP 202 commits durable job before worker starts');
    $id=$receipt['job_id'];
    // The child claims an actual database lease and stays alive. SIGKILL models a
    // power/process crash with zero cleanup or acknowledgement from the worker.
    $code='require '.var_export($root.'/core/bootstrap.php',true).';'
        .'$a=new \\Cajeer\\Logs\\Kernel\\Application('.var_export($root,true).');$a->boot();'
        .'$a->config()->set("database.schema",'.var_export($schema,true).');'
        .'$q=$a->container()->get(\\Cajeer\\Logs\\Queue\\PostgresQueueDriver::class);'
        .'$claim=$q->claim();if(!is_array($claim) || !isset($claim["lease_id"]))exit(5);'
        .'echo json_encode($claim,JSON_THROW_ON_ERROR)."\\n";flush();sleep(120);';
    $child=proc_open([PHP_BINARY,'-r',$code],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
    if (!is_resource($child)) throw new RuntimeException('Unable to start worker crash fixture.');
    stream_set_timeout($pipes[1],15);$line=fgets($pipes[1]);
    if (!is_string($line)) throw new RuntimeException('Worker did not claim a lease: '.stream_get_contents($pipes[2]));
    $claimed=json_decode($line,true,32,JSON_THROW_ON_ERROR);
    assertRecovery((string)$claimed['id']===(string)$id && !empty($claimed['lease_id']),'independent worker acquires fenced durable lease');
    $pid=proc_get_status($child)['pid'];posix_kill($pid,SIGKILL);
    foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
    proc_close($child);$child=null;
    assertRecovery($queue->status($id,$token['id'],$project)['status']==='running' && $account->verify()['valid'],
        'hard-killed worker leaves durable running lease and consistent capacity');
    $pdo->exec("UPDATE jobs SET lease_until=clock_timestamp()-interval '10 minutes' WHERE id=".(int)$id);
    $recovery=$queue->recoverExpired(10);
    assertRecovery($recovery===['requeued'=>1,'dead'=>0,'scanned'=>1], 'operator recovery transitions only the expired lease');
    assertRecovery($queue->process($claimed)==='stale', 'pre-crash lease cannot commit an event after recovery');
    $history=$queue->history($id);
    assertRecovery(count($history)===1 && $history[0]['outcome']==='expired' && $history[0]['error']==='lease_expired',
        'lost attempt finalized with safe bounded reason');
    $pdo->exec("UPDATE jobs SET available_at=clock_timestamp()-interval '1 second' WHERE id=".(int)$id);
    $worker=new Worker($queue);
    $outcomes=$worker->run(1);
    assertRecovery($outcomes['completed']===1 && $queue->status($id,$token['id'],$project)['status']==='completed',
        'restarted real worker completes original job');
    [$replayStatus,$replay]=$send('crash-worker');
    assertRecovery($replayStatus===200 && $replay['job_id']===$id && $replay['replayed']===true,
        'same idempotency key returns original delivery receipt');
    assertRecovery((int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn()===1 && $account->verify()['valid'],
        'recovery persists one event, no duplicate and no accounting drift');
    [$status,$poison]=$send('poison-max-attempts');
    assertRecovery($status===202,'second delivery accepted for DLQ exercise');
    $poisonId=$poison['job_id'];$claim=$queue->claim();
    assertRecovery((string)$claim['id']===$poisonId,'poison attempt leased');
    $pdo->exec('UPDATE jobs SET max_attempts=attempts, lease_until=clock_timestamp()-interval \'10 minutes\' WHERE id='.(int)$poisonId);
    assertRecovery($queue->recoverExpired(10)===['requeued'=>0,'dead'=>1,'scanned'=>1],
        'exhausted crashed lease enters DLQ without replay');
    assertRecovery($queue->status($poisonId,$token['id'],$project)['status']==='dead' && $account->verify()['valid'],
        'DLQ payload retained and capacity accounted');
    assertRecovery((new DeadLetterManager($pdo))->cliChange($poisonId,'retry'),'operator retries dead job with audit');
    $claim=$queue->claim();assertRecovery((string)$claim['id']===$poisonId && $queue->process($claim)==='completed',
        'audited DLQ retry completes with new lease');
    assertRecovery((int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn()===2 && $account->snapshot()['active_jobs']===0 && $account->verify()['valid'],
        'all jobs completed once; full queue capacity released');
    echo 'Ingest recovery PostgreSQL: '.$count.' checks passed'.PHP_EOL;
} finally {
    if(is_resource($child)) {proc_terminate($child,SIGKILL);proc_close($child);}
    $base->exec('DROP SCHEMA "'.$schema.'" CASCADE');
}
