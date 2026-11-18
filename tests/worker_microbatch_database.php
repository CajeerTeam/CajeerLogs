<?php
declare(strict_types=1);
use Cajeer\Logs\Database\{ConnectionFactory,MigrationRunner};
use Cajeer\Logs\Database\Repository\{ProjectRepository,SourceRepository,TokenRepository};
use Cajeer\Logs\Http\Request;
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Queue\{PostgresQueueDriver,QueueAccounting,WorkerPolicy};
use Cajeer\Logs\Integration\WebhookOutbox;
if (getenv('CAJEER_TEST_DATABASE')!=='1') {fwrite(STDERR,"Set CAJEER_TEST_DATABASE=1 on a disposable PostgreSQL database.\n");exit(1);}
$root=dirname(__DIR__);$bootstrap=require $root.'/core/bootstrap.php';$bootstrap->boot();$base=$bootstrap->container()->get(PDO::class);
$schema='cl_micro_'.bin2hex(random_bytes(8));$base->exec('CREATE SCHEMA "'.$schema.'"');$checks=0;
function verify(bool $valid,string $name): void {global $checks;if (!$valid) throw new RuntimeException('FAIL '.$name);++$checks;echo "PASS $name\n";}
try {
    $app=new Application($root);$app->boot();$app->config()->set('database.schema',$schema);$app->config()->set('app.env','test');
    $app->config()->set('api.rate_limit',100000);$app->config()->set('queue.max_jobs',100);$app->config()->set('ingest.require_hmac',false);
    $pdo=$app->container()->get(PDO::class);(new MigrationRunner($pdo,$root.'/migrations/pgsql',$schema))->migrate();
    $projects=new ProjectRepository($pdo);$p=$projects->create('Bulk','bulk');$other=$projects->create('Other','other');
    $sources=new SourceRepository($pdo);$sources->create($p,'App','app');$sources->create($p,'Secondary','secondary');$sources->create($other,'Else','app');
    $tokens=new TokenRepository($pdo);$writer=$tokens->create('Writer',['logs:write'],$p);$foreign=$tokens->create('Foreign',['logs:write'],$other);
    putenv('CAJEER_WEBHOOK_MICRO_SECRET='.str_repeat('s',32));(new WebhookOutbox($pdo))->add($p,'https://hooks.example.test/micro','CAJEER_WEBHOOK_MICRO_SECRET');
    $queue=new PostgresQueueDriver($pdo,100,new WorkerPolicy(['batch_jobs'=>4]));$account=new QueueAccounting($pdo);
    $send=function(string $key,array $events,string $secret)use($app): string {
        $body=json_encode(['events'=>$events],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $response=$app->handle(new Request('POST','/api/v1/ingest/async',['Authorization'=>'Bearer '.$secret,'Content-Type'=>'application/json','Idempotency-Key'=>$key],[],$body,'127.0.0.1'));
        if ($response->status!==202) throw new RuntimeException('Unexpected enqueue response '.$response->status.' '. $response->body);
        return json_decode($response->body,true,32,JSON_THROW_ON_ERROR)['job_id'];
    };
    $mk=function(string $prefix,int $count,string $slug='app'): array {
        return array_map(fn(int $i)=>['timestamp'=>'2026-01-01T00:00:00Z','source'=>$slug,'level'=>'info','message'=>$prefix.'-'.$i],range(1,$count));
    };
    $a=$send('micro-a',$mk('a',2),$writer['token']);$b=$send('micro-b',$mk('b',3,'secondary'),$writer['token']);$c=$send('micro-c',$mk('c',1),$writer['token']);
    $x=$send('micro-other',$mk('foreign',1),$foreign['token']);
    $claims=$queue->claimBatch(4);
    verify(count($claims)===3&&array_map(fn($row)=>(string)$row['id'],$claims)===[$a,$b,$c],'bounded claim batch does not capture another project');
    $otherConn=$app->container()->get(ConnectionFactory::class)->make('pgsql');
    verify((string)(new PostgresQueueDriver($otherConn,100))->claim()['id']===$x,'parallel worker can claim foreign project');
    verify($queue->processBatch($claims)===['completed','completed','completed'],'real multi-job transaction succeeds');
    verify((int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn()===6&&(int)$pdo->query('SELECT count(*) FROM webhook_deliveries')->fetchColumn()===3,'six events and three independent webhook publications committed');
    foreach ([$a=>['a-1','a-2'],$b=>['b-1','b-2','b-3'],$c=>['c-1']] as $id=>$messages) {
        $stmt=$pdo->prepare('SELECT result FROM jobs WHERE id=:id');$stmt->execute(['id'=>$id]);$result=json_decode($stmt->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
        $events=[];foreach($result['event_ids'] as $eventId){$lookup=$pdo->prepare('SELECT message FROM log_events WHERE id=CAST(:id AS uuid)');$lookup->execute(['id'=>$eventId]);$events[]=$lookup->fetchColumn();}
        verify($result['stored']===count($messages)&&$events===$messages,'receipt has original job order and exact data for '.$id);
    }
    verify($account->verify()['valid']&&$account->snapshot()['active_jobs']===1,'group completion frees exactly three jobs');
    verify($queue->process($claims[0])==='stale','original claim cannot be completed twice');
    verify((new PostgresQueueDriver($otherConn,100))->process($otherConn->query("SELECT id,lease_id,attempts FROM jobs WHERE id=$x")->fetch())==='completed','foreign job completes independently');
    // The whole group rolls back on a corrupt durable job. Safe fallback isolates it
    // without poisoning the two healthy jobs or losing their original IDs.
    $d=$send('micro-d',$mk('d',1),$writer['token']);$bad=$send('micro-corrupt',$mk('bad',1),$writer['token']);$f=$send('micro-f',$mk('f',1),$writer['token']);
    $pdo->exec("UPDATE jobs SET payload='{\"events\":\"corrupt\"}'::jsonb WHERE id=$bad");
    $before=(int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn();
    verify($queue->processBatch($queue->claimBatch(3))===['completed','dead','completed'],'invalid job isolated; healthy jobs complete');
    verify((int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn()===$before+2 &&$account->verify()['valid'],'fallback leaves exact event set and accounting');
    verify($pdo->query("SELECT status FROM jobs WHERE id=$bad")->fetchColumn()==='dead' &&$pdo->query("SELECT status FROM jobs WHERE id=$d")->fetchColumn()==='completed','corrupt job retained in dead-letter');
    // A failure in webhook publication must roll back the group; fallback writes
    // neither event until independent per-job transactions succeed.
    $g=$send('micro-g',$mk('g',2),$writer['token']);$h=$send('micro-h',$mk('h',2),$writer['token']);
    $pdo->exec('ALTER TABLE webhook_deliveries ADD CONSTRAINT micro_reject CHECK(false) NOT VALID');
    $before=(int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn();
    verify($queue->processBatch($queue->claimBatch(2))===['retry','retry'],'failed outbox rolls back group and retries both jobs');
    verify((int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn()===$before&&$account->verify()['valid'],'no partial event rows or queue-capacity release on failure');
    $pdo->exec('ALTER TABLE webhook_deliveries DROP CONSTRAINT micro_reject');
    $pdo->exec("UPDATE jobs SET available_at=clock_timestamp()-interval '1 second' WHERE id IN ($g,$h)");
    verify($queue->processBatch($queue->claimBatch(2))===['completed','completed'],'two failed jobs can complete after recovery');
    // A 60-event job, another 60-event job and a 1-event job are all valid.
    // An adaptive claim must SKIP the second (too large together), take the
    // third, and leave the second pending for a later fair dispatch.
    $i=$send('micro-i',$mk('i',60),$writer['token']);$j=$send('micro-j',$mk('j',60),$writer['token']);$k=$send('micro-k',$mk('k',1),$writer['token']);
    $before=(int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn();
    $oversizeFirst=$queue->claimBatch(3);
    verify(count($oversizeFirst)===2 && array_map(static fn($c)=>(string)$c['id'],$oversizeFirst)===[$i,$k],
        'adaptive claim skips too-large middle job and leases compatible later job');
    verify($queue->processBatch($oversizeFirst)===['completed','completed'],'61 events committed in one atomic transaction');
    $oversizeSecond=$queue->claimBatch(2);
    verify(count($oversizeSecond)===1 && (string)$oversizeSecond[0]['id']===$j && $queue->processBatch($oversizeSecond)===['completed'],
        '60-event job left pending then completed without group rollback');
    verify((int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn()===$before+121&&$account->verify()['valid'],
        '121 events stored once and queue accounting remains exact');
    // Measure actual SQL batch attempts for identical four-job workloads.
    // Wall-clock timings are evidence, NOT a flaky pass/fail throughput claim.
    foreach(range(1,4) as $n) $send('micro-single-bench-'.$n,$mk('single-'.$n,1),$writer['token']);
    $beforeAttempts=\Cajeer\Logs\Log\LogRepository::metrics()['batch_attempts'];$singleStart=hrtime(true);
    $single=(new \Cajeer\Logs\Queue\Worker($queue,100,policy:new WorkerPolicy(['batch_jobs'=>1])))->run(4);
    $singleTime=round((hrtime(true)-$singleStart)/1e6,3);
    $singleInserts=\Cajeer\Logs\Log\LogRepository::metrics()['batch_attempts']-$beforeAttempts;
    foreach(range(1,4) as $n) $send('micro-group-bench-'.$n,$mk('group-'.$n,1),$writer['token']);
    $beforeAttempts=\Cajeer\Logs\Log\LogRepository::metrics()['batch_attempts'];$groupStart=hrtime(true);
    $group=(new \Cajeer\Logs\Queue\Worker($queue,100,policy:new WorkerPolicy(['batch_jobs'=>4])))->run(4);
    $groupTime=round((hrtime(true)-$groupStart)/1e6,3);
    $groupInserts=\Cajeer\Logs\Log\LogRepository::metrics()['batch_attempts']-$beforeAttempts;
    verify($single['completed']===4&&$group['completed']===4,'actual Worker::run handles both batch modes');
    verify($singleInserts===4&&$groupInserts===1,'four jobs use four versus one real repository INSERT');
    verify($account->verify()['valid'],'queue accounting reconciles after worker benchmark');
    echo json_encode(['individual_ms'=>$singleTime,'microbatch_ms'=>$groupTime,'individual_inserts'=>$singleInserts,'microbatch_inserts'=>$groupInserts],JSON_THROW_ON_ERROR)."\n";
    echo "Worker microbatch PostgreSQL: $checks checks passed\n";
} finally {
    unset($pdo,$app,$queue,$account,$otherConn);$base->exec('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
}
