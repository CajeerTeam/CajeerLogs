<?php
declare(strict_types=1);
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Database\{ConnectionFactory,MigrationRunner};
use Cajeer\Logs\Database\Repository\{ProjectRepository,SourceRepository,TokenRepository};
use Cajeer\Logs\Http\Request;
use Cajeer\Logs\Queue\{PostgresQueueDriver,Worker};
use Cajeer\Logs\Lifecycle\Retention;
use Cajeer\Logs\Scheduler\Scheduler;
use Cajeer\Logs\Integration\WebhookOutbox;
if (getenv('CAJEER_TEST_DATABASE')!=='1') { fwrite(STDERR,"Set CAJEER_TEST_DATABASE=1 and DB_* on a disposable database.\n");exit(1); }
$root=dirname(__DIR__);$base=require $root.'/core/bootstrap.php';$base->boot();$db=$base->container()->get(PDO::class);
$schema='cl_queue_'.bin2hex(random_bytes(8));$db->exec('CREATE SCHEMA "'.$schema.'"');$tmp=sys_get_temp_dir().'/'.$schema;mkdir($tmp,0700);
$count=0;
function verify(bool $ok,string $label):void {global $count;if (!$ok) throw new RuntimeException('FAIL '.$label);$count++;echo "PASS $label\n";}
function data(\Cajeer\Logs\Http\Response $r):array {return json_decode($r->body,true,64,JSON_THROW_ON_ERROR);}
try {
    $app=new Application($root);$app->boot();$app->config()->set('database.schema',$schema);$app->config()->set('app.env','test');$app->config()->set('ingest.require_hmac',false);$app->config()->set('queue.max_jobs',2);
    $pdo=$app->container()->get(PDO::class);$factory=$app->container()->get(ConnectionFactory::class);
    foreach (glob($root.'/migrations/pgsql/000[1-6]_*.sql') as $path) {copy($path,$tmp.'/'.basename($path));}
    (new MigrationRunner($pdo,$tmp,$schema))->migrate();
    $projects=new ProjectRepository($pdo);$p=$projects->create('One','one');$other=$projects->create('Two','two');
    $sources=new SourceRepository($pdo);$source=$sources->create($p,'App','app');$otherSource=$sources->create($other,'Other','app');
    $tokens=new TokenRepository($pdo);$token=$tokens->create('Writer',['logs:write'],$p,$source);$foreign=$tokens->create('Foreign',['logs:write'],$other,$otherSource);
    $pdo->exec("INSERT INTO jobs(queue,name) VALUES ('default','legacy')");
    $runner=$app->container()->get(MigrationRunner::class);verify($runner->migrate()===['0007_queue_lifecycle.sql','0008_security_operations.sql','0009_project_management.sql','0010_source_management.sql','0011_token_management.sql','0012_database_integrity.sql','0013_ingest_signing.sql','0014_delivery_idempotency.sql'] && $runner->migrate()===[],'0.0.7 upgrade, repeat and preserved migration history');
    verify((int)$pdo->query("SELECT count(*) FROM jobs WHERE name='legacy'")->fetchColumn()===1 && $app->handle(new Request('GET','/ready'))->status===200,'legacy rows preserved and readiness');
    $queue=$app->container()->get(PostgresQueueDriver::class);
    $body=json_encode(['timestamp'=>'2026-01-01T00:00:00Z','source'=>'app','level'=>'error','message'=>'queue integration','context'=>['password'=>'do-not-store']],JSON_THROW_ON_ERROR);
    $body2=json_encode(['events'=>[json_decode($body),json_decode($body)]],JSON_THROW_ON_ERROR);
    $send=fn(string $key,string $payload,string $secret,string $path='/api/v1/ingest/async')=>$app->handle(new Request('POST',$path,['Authorization'=>'Bearer '.$secret,'Content-Type'=>'application/json','Idempotency-Key'=>$key],[],$payload,'127.0.0.1'));
    $poll=fn(string $id,string $secret)=>$app->handle(new Request('GET','/api/v1/ingest/jobs/'.$id,['Authorization'=>'Bearer '.$secret],[],'','127.0.0.1'));
    $r=$send('first',$body2,$token['token']);$d=data($r);$id=$d['job_id'];
    verify($r->status===202 && $d['status']==='pending' && $d['received']===2 && $r->header('Location')==='/api/v1/ingest/jobs/'.$id,'durable async accepted batch');
    $second=$factory->make('pgsql');$secondQueue=new PostgresQueueDriver($second,2);
    verify((int)$second->query("SELECT count(*) FROM jobs WHERE queue='ingest-v1'")->fetchColumn()===1 && (int)$second->query('SELECT count(*) FROM log_events')->fetchColumn()===0,'job visible to independent connection before log storage');
    verify(!str_contains($second->query("SELECT payload FROM jobs WHERE id=".$id)->fetchColumn(),'do-not-store'),'queued payload redacted');
    $r=$send('first',$body2,$token['token']);verify($r->status===200&&data($r)['job_id']===$id&&data($r)['replayed'],'same key exact body returns same job');
    verify($send('first',$body,$token['token'])->status===409&&$send('first',$body2,$token['token'],'/api/v1/ingest')->status===409,'body conflict and cross-mode deduplication');
    verify($poll($id,$foreign['token'])->status===404&&data($poll($id,$token['token']))['status']==='pending','job ownership enforced');
    $r=$send('second',$body,$token['token']);$id2=data($r)['job_id'];verify($r->status===202,'second accepted');
    $r=$send('third',$body,$token['token']);verify($r->status===503&&$r->header('Retry-After')==='5'&&data($r)['error']==='queue_full','hard global backpressure');
    // The first row is locked on an independent connection; the second worker skips it.
    $second->beginTransaction();$second->query('SELECT id FROM jobs WHERE id='.$id.' FOR UPDATE')->closeCursor();
    $claim2=$queue->claim();verify((string)$claim2['id']===$id2,'SKIP LOCKED claims next job');$second->commit();
    $claim=$queue->claim();verify((string)$claim['id']===$id&&$secondQueue->claim()===null,'distinct leases, no duplicate claim');
    putenv('CAJEER_WEBHOOK_QUEUE_TEST='.str_repeat('s',32));$hook=(new WebhookOutbox($pdo))->add($p,'https://hooks.example.com/receive','CAJEER_WEBHOOK_QUEUE_TEST');
    verify($queue->process($claim)==='completed'&&$secondQueue->process($claim)==='stale','fenced completion and stale worker');
    $status=data($poll($id,$token['token']));verify($status['status']==='completed'&&$status['result']['stored']===2&&count($status['result']['event_ids'])===2,'job result contains committed event IDs');
    verify((int)$second->query('SELECT count(*) FROM log_events')->fetchColumn()===2&&(int)$second->query('SELECT count(*) FROM webhook_deliveries')->fetchColumn()===1,'atomic stored batch and outbox');
    verify($second->query('SELECT payload FROM jobs WHERE id='.$id)->fetchColumn()==='{}','completed payload erased');
    // Expired lease recovery and fencing.
    $pdo->exec("UPDATE jobs SET lease_until=clock_timestamp()-interval '1 second' WHERE id=".$id2);
    $newClaim=$secondQueue->claim();verify($newClaim['lease_id']!==$claim2['lease_id']&&$queue->process($claim2)==='stale','expired lease recovered; old worker fenced');
    // Force a real SQL failure in the outbox; log rows and completion must roll back together.
    $pdo->exec('ALTER TABLE webhook_deliveries ADD CONSTRAINT test_queue_reject CHECK (false) NOT VALID');
    verify($secondQueue->process($newClaim)==='retry'&&(int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn()===2,'failed outbox rolls back inserted log and schedules retry');
    verify($queue->claim()===null&&$queue->history($id2)[0]['outcome']==='retry','backoff and attempt history');
    $pdo->exec("UPDATE jobs SET available_at=clock_timestamp()-interval '1 second',max_attempts=attempts+1 WHERE id=".$id2);
    $failed=$queue->claim();verify($queue->process($failed)==='dead'&&$pdo->query('SELECT status FROM jobs WHERE id='.$id2)->fetchColumn()==='dead','exhausted attempts enter dead-letter');
    $pdo->exec('ALTER TABLE webhook_deliveries DROP CONSTRAINT test_queue_reject');
    verify(data($poll($id2,$token['token']))['status']==='dead','status API restored after drift repair');
    verify($queue->retry($id2)&&$queue->process($queue->claim())==='completed','manual retry processes original durable payload');
    verify((int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn()===3&&(int)$pdo->query('SELECT count(*) FROM webhook_deliveries')->fetchColumn()===2,'successful retry stores exactly once');
    // A revoked token cannot read status, but jobs admitted before revocation remain durable.
    $r=$send('revoked',$body,$foreign['token']);$id3=data($r)['job_id'];verify($r->status===202,'accepted before revocation');
    $tokens->revoke($foreign['id']);verify($poll($id3,$foreign['token'])->status===401,'revoked token cannot poll');
    verify((new Worker($queue))->run(1)['completed']===1,'accepted work survives token revocation');
    $r=$send('discard',$body,$token['token']);$id4=data($r)['job_id'];$c=$queue->claim();$pdo->exec("UPDATE jobs SET lease_until=clock_timestamp()-interval '1 second',max_attempts=attempts WHERE id=".$id4);
    verify(isset($queue->claim()['skipped'])&&$queue->discard($id4)&&data($poll($id4,$token['token']))['status']==='cancelled','expired final attempt dead-letter and explicit discard');
    // Retention is opt-in and uses received_at; old occurrence alone is not deleted.
    $ret=new Retention($pdo);verify($ret->run()===0&&$ret->set($p,1)&&$ret->run()===0,'retention disabled by default and based on receipt time');
    $pdo->exec("UPDATE log_events SET received_at=clock_timestamp()-interval '2 days'");
    verify($ret->run(1)===1&&(int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn()===3,'bounded retention batch');
    $scheduler=new Scheduler($pdo);$results=$scheduler->run();verify($results['retention']['removed']===2&&count($results)===4&&$scheduler->run()===[],'scheduler persists due tasks and runs bounded maintenance');
    verify((int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn()===1&&$pdo->query('SELECT project_id FROM log_events')->fetchColumn()===$other,'other project unaffected');
    $pdo->exec("UPDATE jobs SET completed_at=clock_timestamp()-interval '8 days' WHERE status IN ('completed','cancelled') AND queue='ingest-v1'");
    verify($queue->cleanup()===4 && (int)$pdo->query('SELECT count(*) FROM job_attempts')->fetchColumn()===0,'terminal job retention and attempt cascade');
    verify($ret->set($p,null)&&$ret->run()===0,'retention disable');
    // HMAC binds the async path; identical signed nonce cannot be replayed.
    $app->config()->set('ingest.require_hmac',true);
    $ts=(string)time();$nonce=bin2hex(random_bytes(16));$key='signed-async';$headers=['Authorization'=>'Bearer '.$token['token'],'Content-Type'=>'application/json','Idempotency-Key'=>$key,'X-Log-Timestamp'=>$ts,'X-Log-Nonce'=>$nonce];
    $req=new Request('POST','/api/v1/ingest/async',$headers,[],$body,'127.0.0.1');
    $headers['X-Log-Signature']=(new \Cajeer\Logs\Security\SignedPayload())->sign(\Cajeer\Logs\Security\SignedPayload::canonical($req,$ts,$nonce,$key),$token['token']);
    $signed=new Request('POST','/api/v1/ingest/async',$headers,[],$body,'127.0.0.1');
    verify($app->handle($signed)->status===202&&$app->handle($signed)->status===409,'async HMAC and nonce replay protection');
    verify((new Worker($queue))->run(1)['completed']===1,'signed job processed');
    $app->config()->set('ingest.require_hmac',false);
    // Failure must not stop unrelated maintenance; failed task remains due.
    $pdo->exec("UPDATE scheduler_runs SET next_run_at=clock_timestamp()-interval '1 second'");
    $pdo->exec("ALTER TABLE scheduler_runs ADD CONSTRAINT test_scheduler_failure CHECK (name<>'retention') NOT VALID");
    $results=$scheduler->run();verify(isset($results['retention']['error'])&&isset($results['auth_cleanup']['sessions'])&&$scheduler->due()===['retention'],'scheduler failure isolation and retry');
    $pdo->exec('ALTER TABLE scheduler_runs DROP CONSTRAINT test_scheduler_failure');
    verify(isset($scheduler->run()['retention']['removed']),'failed scheduler task succeeds next run');
    $metrics=$app->handle(new Request('GET','/metrics'));verify(str_contains($metrics->body,'cajeerlogs_queue_available 1')&&str_contains($metrics->body,'cajeerlogs_queue_jobs{status="dead"} 0'),'real queue metrics');
    // Mixed-source single/batch paths share validation, source counters and ordering.
    $numeric=$sources->create($p,'Numeric','0');
    $wide=$tokens->create('Mixed writer',['logs:write'],$p,null);
    $mixedBody=json_encode(['events'=>[json_decode($body),array_replace((array)json_decode($body),['source'=>'0','message'=>'numeric']),json_decode($body)]],JSON_THROW_ON_ERROR);
    $sourceCounter=fn(string $sid)=>(int)$pdo->query("SELECT accepted_events_total FROM sources WHERE id=".$pdo->quote($sid))->fetchColumn();
    $beforeApp=$sourceCounter($source);$beforeNumeric=$sourceCounter($numeric);
    $r=$send('mixed-sync',$mixedBody,$wide['token'],'/api/v1/ingest');$mixedIds=data($r)['event_ids'];
    verify($r->status===201&&count($mixedIds)===3&&count(array_unique($mixedIds))===3,'mixed sync batch committed all UUIDs');
    verify($sourceCounter($source)===$beforeApp+2&&$sourceCounter($numeric)===$beforeNumeric+1,'bulk source counters match event multiplicity');
    verify(data($send('mixed-sync',$mixedBody,$wide['token'],'/api/v1/ingest'))['event_ids']===$mixedIds&&$sourceCounter($source)===$beforeApp+2,'mixed sync replay not charged');
    $r=$send('mixed-async',$mixedBody,$wide['token']);$mixedJob=data($r)['job_id'];
    verify($r->status===202&&$sourceCounter($source)===$beforeApp+4&&$sourceCounter($numeric)===$beforeNumeric+2,'mixed async admission charges once');
    verify($pdo->query("SELECT jsonb_typeof(payload->'sources') FROM jobs WHERE id=".$mixedJob)->fetchColumn()==='object','numeric source mapping persisted as JSON object');
    verify($send('mixed-async',$mixedBody,$wide['token'])->status===200&&$sourceCounter($numeric)===$beforeNumeric+2,'async replay skips admission');
    verify($queue->process($queue->claim())==='completed','mixed async batch processed');
    $result=data($poll($mixedJob,$wide['token']))['result'];
    $lookup=$pdo->prepare('SELECT source_id FROM log_events WHERE id=:id');$ordered=[];
    foreach ($result['event_ids'] as $eventId) {$lookup->execute(['id'=>$eventId]);$ordered[]=$lookup->fetchColumn();}
    verify($ordered===[$source,$numeric,$source]&&$result['stored']===3,'async result preserves source and input order');
    // PostgreSQL renders finite exponents as long decimals. The compact JSON
    // fits the HTTP/job limits but the actual JSONB text exceeds the worker cap.
    $expanded=array_replace((array)json_decode($body),['context'=>['numbers'=>array_fill(0,1500,1e300)]]);
    $expansionBody=json_encode(['events'=>array_fill(0,30,$expanded)],JSON_THROW_ON_ERROR);
    $beforeApp=$sourceCounter($source);$beforeJobs=(int)$pdo->query('SELECT count(*) FROM jobs')->fetchColumn();
    $r=$send('jsonb-expanded',$expansionBody,$token['token']);
    verify($r->status===413&&$sourceCounter($source)===$beforeApp&&(int)$pdo->query('SELECT count(*) FROM jobs')->fetchColumn()===$beforeJobs,'JSONB expansion refusal rolls back admission and job');
    // Invalid durable data goes dead on the first attempt without partial storage.
    $r=$send('malformed',$body2,$token['token']);$badId=data($r)['job_id'];verify($r->status===202,'malformed fixture initially accepted');
    $pdo->exec("UPDATE jobs SET payload=jsonb_set(payload,'{events,1,message}','123'::jsonb) WHERE id=".$badId);
    $beforeLogs=(int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn();$beforeHooks=(int)$pdo->query('SELECT count(*) FROM webhook_deliveries')->fetchColumn();
    verify($queue->process($queue->claim())==='dead','malformed job fails permanently on first attempt');
    $badStatus=data($poll($badId,$token['token']));
    verify($badStatus['status']==='dead'&&(int)$badStatus['attempts']===1&&$badStatus['last_error']==='invalid_payload'&&$queue->history($badId)[0]['outcome']==='dead','safe permanent failure and attempt history');
    verify((int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn()===$beforeLogs&&(int)$pdo->query('SELECT count(*) FROM webhook_deliveries')->fetchColumn()===$beforeHooks,'malformed batch leaves no log or outbox row');
    verify($queue->discard($badId),'explicit discard of invalid payload');
    // A stale claim cannot write even before another worker reclaims it.
    $r=$send('expired-before-start',$body,$token['token']);$expiredId=data($r)['job_id'];$expiredClaim=$queue->claim();
    $pdo->exec("UPDATE jobs SET lease_until=clock_timestamp()-interval '1 second' WHERE id=".$expiredId);
    verify($queue->process($expiredClaim)==='stale'&&(int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn()===$beforeLogs,'expired claim cannot start processing');
    verify($queue->process($secondQueue->claim())==='completed','expired job reclaimed by independent session');
    // Terminate a real backend after INSERT and before COMMIT. A trigger on the
    // outbox waits on a lock held by the independent session, so the transaction
    // has already executed LogRepository::storeBatch but cannot commit.
    $r=$send('terminate-after-insert',$body2,$token['token']);$crashId=data($r)['job_id'];$crashClaim=$queue->claim();
    verify($r->status===202&&(string)$crashClaim['id']===$crashId,'crash fixture durably claimed');
    $beforeLogs=(int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn();$beforeHooks=(int)$pdo->query('SELECT count(*) FROM webhook_deliveries')->fetchColumn();
    $lock=random_int(100000,2000000000);$name='cl_queue_crash_'.bin2hex(random_bytes(6));
    $pdo->exec('CREATE FUNCTION queue_crash_pause() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_advisory_xact_lock('.$lock.'); RETURN NEW; END; $$');
    $pdo->exec('CREATE TRIGGER queue_crash_pause BEFORE INSERT ON webhook_deliveries FOR EACH ROW EXECUTE FUNCTION queue_crash_pause()');
    $second->query('SELECT pg_advisory_lock('.$lock.')')->closeCursor();$child=null;$streams=[];
    try {
        $code='require '.var_export($root.'/core/bootstrap.php',true).'; $app=new \\Cajeer\\Logs\\Kernel\\Application('.var_export($root,true).'); $app->boot(); $app->config()->set("database.schema",'.var_export($schema,true).'); $app->config()->set("database.application_name",'.var_export($name,true).'); $pdo=$app->container()->get(PDO::class); try { (new \\Cajeer\\Logs\\Queue\\PostgresQueueDriver($pdo))->process('.var_export($crashClaim,true).'); exit(2); } catch (Throwable $e) { exit(\\Cajeer\\Logs\\Database\\DatabaseFailure::reconnectable($e)?0:3); }';
        $child=proc_open([PHP_BINARY,'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$streams,$root);
        if (!is_resource($child)) throw new RuntimeException('Cannot start fault-injection worker');fclose($streams[0]);unset($streams[0]);
        $q=$pdo->prepare("SELECT pid FROM pg_stat_activity WHERE application_name=:name AND wait_event_type='Lock' AND wait_event='advisory'");$pid=0;$until=microtime(true)+15;
        do {$q->execute(['name'=>$name]);$pid=(int)$q->fetchColumn();$q->closeCursor();if($pid)break;usleep(50000);}while(microtime(true)<$until&&proc_get_status($child)['running']);
        verify($pid>0,'worker reached outbox after log INSERT');
        $terminate=$pdo->prepare('SELECT pg_terminate_backend(CAST(:pid AS integer),5000)');$terminate->execute(['pid'=>$pid]);verify($terminate->fetchColumn()===true,'worker backend terminated before commit');
        $out=stream_get_contents($streams[1]);$err=stream_get_contents($streams[2]);fclose($streams[1]);fclose($streams[2]);$streams=[];
        verify(proc_close($child)===0,'connection loss propagated rather than unsafe retry');$child=null;
    } finally {
        if(is_resource($child)){proc_terminate($child);foreach($streams as $stream)if(is_resource($stream))fclose($stream);proc_close($child);}
        $second->query('SELECT pg_advisory_unlock('.$lock.')')->closeCursor();
        $pdo->exec('DROP TRIGGER queue_crash_pause ON webhook_deliveries');$pdo->exec('DROP FUNCTION queue_crash_pause()');
    }
    verify((int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn()===$beforeLogs&&(int)$pdo->query('SELECT count(*) FROM webhook_deliveries')->fetchColumn()===$beforeHooks,'connection loss rolled back log and outbox');
    verify($pdo->query('SELECT status FROM jobs WHERE id='.$crashId)->fetchColumn()==='running','committed claim survives lost processing session');
    $pdo->exec("UPDATE jobs SET lease_until=clock_timestamp()-interval '1 second' WHERE id=".$crashId);
    verify($secondQueue->process($secondQueue->claim())==='completed'&&(int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn()===$beforeLogs+2&&(int)$pdo->query('SELECT count(*) FROM webhook_deliveries')->fetchColumn()===$beforeHooks+1,'recovery commits exactly one batch and outbox');
    verify($queue->process($crashClaim)==='stale','pre-crash claim fenced after recovery');
    echo "Queue database: $count checks passed\n";
} finally {
    if (isset($second)&&$second->inTransaction()) $second->rollBack();
    if (isset($pdo)&&$pdo->inTransaction()) $pdo->rollBack();
    $db->exec('DROP SCHEMA "'.$schema.'" CASCADE');foreach (glob($tmp.'/*') as $file) unlink($file);rmdir($tmp);putenv('CAJEER_WEBHOOK_QUEUE_TEST');
}
