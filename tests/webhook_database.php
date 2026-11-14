<?php
declare(strict_types=1);
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Database\MigrationRunner;
use Cajeer\Logs\Database\Repository\{ProjectRepository,SourceRepository,TokenRepository};
use Cajeer\Logs\Integration\WebhookOutbox;
use Cajeer\Logs\Http\Request;
if (getenv('CAJEER_TEST_DATABASE')!=='1') { fwrite(STDERR,"Set CAJEER_TEST_DATABASE=1 and DB_* on a disposable database.\n");exit(1); }
$root=dirname(__DIR__);$base=require $root.'/core/bootstrap.php';$base->boot();$db=$base->container()->get(PDO::class);$schema='cl_webhook_'.bin2hex(random_bytes(8));$db->exec('CREATE SCHEMA "'.$schema.'"');
$count=0;
function verify(bool $ok,string $label):void {global $count;if (!$ok) throw new RuntimeException('FAIL '.$label);$count++;echo "PASS $label\n";}
try {
    $app=new Application($root);$app->boot();$app->config()->set('database.schema',$schema);$app->config()->set('app.env','test');$app->config()->set('ingest.require_hmac',false);
    $pdo=$app->container()->get(PDO::class);$runner=$app->container()->get(MigrationRunner::class);$runner->migrate();
    $projects=new ProjectRepository($pdo);$p=$projects->create('One','one');$other=$projects->create('Two','two');$source=(new SourceRepository($pdo))->create($p,'App','app');$token=(new TokenRepository($pdo))->create('Writer',['logs:write'],$p,$source)['token'];
    putenv('CAJEER_WEBHOOK_TEST='.str_repeat('s',32));$outbox=new WebhookOutbox($pdo);$hook=$outbox->add($p,'https://hooks.example.com/receive','CAJEER_WEBHOOK_TEST');$foreign=$outbox->add($other,'https://hooks.example.com/receive','CAJEER_WEBHOOK_TEST');
    $raw=json_encode(['timestamp'=>'2026-01-01T00:00:00Z','source'=>'app','level'=>'error','message'=>'private message'],JSON_THROW_ON_ERROR);
    $send=fn(string $key,string $body)=>$app->handle(new Request('POST','/api/v1/ingest',['Content-Type'=>'application/json','Authorization'=>'Bearer '.$token,'Idempotency-Key'=>$key],[],$body,'127.0.0.1'));
    $r=$send('first',$raw);verify($r->status===201,'ingest creates committed event');$ids=json_decode($r->body,true,32,JSON_THROW_ON_ERROR)['event_ids'];
    $delivery=$pdo->query('SELECT * FROM webhook_deliveries')->fetch();$payload=json_decode($delivery['payload'],true,32,JSON_THROW_ON_ERROR);
    verify($delivery['webhook_id']===$hook&&$payload['project_id']===$p&&$payload['event_ids']===$ids&&!str_contains($delivery['payload'],'private message'),'project-scoped metadata-only outbox');
    verify($send('first',$raw)->status===200&&(int)$pdo->query('SELECT count(*) FROM webhook_deliveries')->fetchColumn()===1,'idempotent ingest creates no extra delivery');
    $bad=json_encode(['timestamp'=>'bad','source'=>'app','level'=>'error','message'=>'bad'],JSON_THROW_ON_ERROR);verify($send('bad',$bad)->status===422&&(int)$pdo->query('SELECT count(*) FROM webhook_deliveries')->fetchColumn()===1,'invalid ingest leaves no delivery');
    $pdo->exec("ALTER TABLE webhook_deliveries ADD CONSTRAINT test_outbox_reject CHECK (false) NOT VALID");
    $before=(int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn();
    verify($send('rollback',$raw)->status===500&&(int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn()===$before,'outbox SQL failure rolls back log insert');
    $pdo->exec('ALTER TABLE webhook_deliveries DROP CONSTRAINT test_outbox_reject');
    $attempts=[];$fake=function(string $url,string $id,string $body,string $secret)use(&$attempts):int{$attempts[]=[$id,$body,$secret];return count($attempts)===1?503:204;};
    verify($outbox->work(1,$fake)['retry']===1,'503 schedules retry');
    verify($outbox->work(1,$fake)['delivered']===0&&count($attempts)===1,'backoff prevents early delivery');
    $pdo->exec("UPDATE webhook_deliveries SET next_attempt_at=now()-interval '1 second'");
    verify($outbox->work(1,$fake)['delivered']===1&&$attempts[0]===$attempts[1],'retry preserves delivery ID, body and signing secret');
    $history=$outbox->history($hook);verify(count($history)===2&&$history[0]['outcome']==='delivered'&&$history[1]['outcome']==='retry','attempt history persists');
    verify($send('second',$raw)->status===201,'new notification');$row=$outbox->claim();verify($row!==null&&!isset($row['skipped'])&&$outbox->claim()===null,'lease prevents simultaneous claim');
    $pdo->exec("UPDATE webhook_deliveries SET lease_until=now()-interval '1 second' WHERE status='sending'");$recovered=$outbox->claim();verify($recovered['id']===$row['id']&&$recovered['lease_id']!==$row['lease_id'],'expired lease reclaimed');
    verify($outbox->finish($row,200)==='unknown'&&$outbox->finish($recovered,401)==='failed','stale lease cannot acknowledge new attempt');
    verify($outbox->retry($row['id'])&&$outbox->work(1,fn()=>204)['delivered']===1,'manual retry keeps history and delivers');
    verify($send('third',$raw)->status===201&&$outbox->disable($hook)&&$outbox->claim()===null,'disabled hook cancels pending notifications');
    verify($send('fourth',$raw)->status===201&&(int)$pdo->query('SELECT count(*) FROM webhook_deliveries')->fetchColumn()===3,'disabled hook creates no new notifications');
    $pdo->exec("UPDATE webhook_deliveries SET completed_at=now()-interval '31 days'");verify($outbox->cleanup()===3&&(int)$pdo->query('SELECT count(*) FROM webhook_attempts')->fetchColumn()===0,'bounded retention cascades attempt history');
    echo "Webhook database: $count checks passed\n";
} finally { $db->exec('DROP SCHEMA "'.$schema.'" CASCADE');putenv('CAJEER_WEBHOOK_TEST'); }
