<?php
declare(strict_types=1);
use Cajeer\Logs\Database\{MigrationRunner,TransactionManager};
use Cajeer\Logs\Database\Repository\UserRepository;
use Cajeer\Logs\Http\{Request,Response};
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Log\LogRepository;
use Cajeer\Logs\Queue\PostgresQueueDriver;
if(getenv('CAJEER_TEST_DATABASE')!=='1'){fwrite(STDERR,"Set CAJEER_TEST_DATABASE=1 for an isolated PostgreSQL database.\n");exit(1);}
$root=dirname(__DIR__);$base=require $root.'/core/bootstrap.php';$base->boot();$db=$base->container()->get(PDO::class);
$schema='cl_privacy_'.bin2hex(random_bytes(8));$db->exec('CREATE SCHEMA "'.$schema.'"');$n=0;
function check(bool $ok,string $name):void{global $n;if(!$ok)throw new RuntimeException('FAIL '.$name);++$n;echo "PASS $name\n";}
function data(Response $r):array{return json_decode($r->body,true,64,JSON_THROW_ON_ERROR);}
function call(string $method,string $path,?array $body=null,array $h=[],array $query=[]):Response{global $app;return $app->handle(new Request($method,'/api/v1'.$path,$h+['Content-Type'=>'application/json'],$query,$body===null?'':json_encode($body,JSON_THROW_ON_ERROR),'127.0.0.1'));}
function ok(string $method,string $path,?array $body,array $h,int $code=200):array{$r=call($method,$path,$body,$h);check($r->status===$code,$method.' '.$path.' '.$r->status);return data($r);}
function rule(string $type,string $value):array{return compact('type','value');}
try{
    $app=new Application($root);$app->boot();$app->config()->set('database.schema',$schema);$app->config()->set('app.env','test');$app->config()->set('app.url','http://localhost:8080');$app->config()->set('security.totp_key',bin2hex(random_bytes(32)));$app->config()->set('security.require_admin_totp',false);$app->config()->set('api.rate_limit',600);$app->config()->set('ingest.require_hmac',false);
    $pdo=$app->container()->get(PDO::class);$app->container()->get(MigrationRunner::class)->migrate();
    (new UserRepository($pdo))->create('privacy@example.test','test-privacy-password-1234','administrator');
    $login=call('POST','/auth/login',['email'=>'privacy@example.test','password'=>'test-privacy-password-1234']);check($login->status===200,'administrator login');$admin=['Cookie'=>explode(';',$login->header('Set-Cookie'),2)[0],'X-CSRF-Token'=>data($login)['csrf_token']];
    $p=ok('POST','/projects',['name'=>'Privacy','slug'=>'privacy'],$admin,201)['id'];$path='/projects/'.$p;
    $a=ok('POST',$path.'/sources',['name'=>'App','slug'=>'app'],$admin,201)['id'];$b=ok('POST',$path.'/sources',['name'=>'Other','slug'=>'other'],$admin,201)['id'];
    $token=ok('POST',$path.'/tokens',['name'=>'Writer','scopes'=>['logs:read','logs:write']],$admin,201);$bearer=['Authorization'=>'Bearer '.$token['token']];
    $e=['timestamp'=>gmdate('Y-m-d\TH:i:s\Z'),'source'=>'app','level'=>'info','message'=>'customer plaintext','context'=>['customer_id'=>'private-id','email'=>'private@example.test','future'=>'future-private','safe'=>1]];
    $old=ok('POST','/ingest',$e,$bearer+['Idempotency-Key'=>'history'],201)['event_ids'][0];
    // Old data and queued jobs predate the newly installed policies.
    $queued=ok('POST','/ingest/async',$e,$bearer+['Idempotency-Key'=>'old-job'],202)['job_id'];
    check(ok('GET','/system/redaction',null,$admin)['revision']===0,'default empty global policy');
    check(ok('PUT','/system/redaction',['revision'=>0,'rules'=>[rule('key','email')]],$admin)['revision']===1,'global policy stored');
    check(ok('PUT',$path.'/redaction',['revision'=>0,'rules'=>[rule('path','/context/customer_id')]],$admin)['revision']===1,'project policy stored');
    $sp=$path.'/sources/'.$a.'/redaction';
    check(ok('PUT',$sp,['revision'=>0,'rules'=>[rule('path','/message')]],$admin)['revision']===1,'source policy stored');
    check(call('PUT',$sp,['revision'=>0,'rules'=>[]],$admin)->status===409,'stale policy revision rejected');
    check(call('PUT',$sp,['revision'=>1,'rules'=>[rule('regex','(')]],$admin)->status===422,'invalid rule rejected');
    check(call('GET',$sp,null,$bearer)->status===403,'bearer cannot inspect policy');
    $preview=ok('POST',$sp.'/preview',['rules'=>[rule('path','/context/future')],'event'=>$e],$admin)['event'];
    check($preview['message']==='customer plaintext'&&$preview['context']['future']==='[redacted]'&&$preview['context']['customer_id']==='[redacted]'&&$preview['context']['email']==='[redacted]','draft preview inherits global/project and does not use old source rules');
    check(ok('GET',$sp,null,$admin)['revision']===1,'preview does not publish rules');
    $other=$e;$other['source']='other';
    $ids=ok('POST','/ingest',['events'=>[$e,$other]],$bearer+['Idempotency-Key'=>'mixed'],201)['event_ids'];
    $read=fn($id)=>$pdo->query('SELECT message,context::text AS context FROM log_events WHERE id='.$pdo->quote($id))->fetch();
    $one=$read($ids[0]);$two=$read($ids[1]);
    check($one['message']==='[redacted]'&&$two['message']==='customer plaintext'&&!str_contains($one['context'],'private-id')&&!str_contains($two['context'],'private@example.test'),'mixed source isolation and stored masking');
    $new=ok('POST','/ingest/async',$e,$bearer+['Idempotency-Key'=>'new-job'],202)['job_id'];
    $body=$pdo->query('SELECT payload::text FROM jobs WHERE id='.$pdo->quote($new))->fetchColumn();
    check(!str_contains($body,'customer plaintext')&&!str_contains($body,'private-id')&&!str_contains($body,'private@example.test'),'durable payload masked before acknowledgement');
    ok('PUT',$sp,['revision'=>1,'rules'=>[rule('path','/message'),rule('path','/context/future')]],$admin);
    $q=new PostgresQueueDriver($pdo);
    for($i=0;$i<2;$i++)check($q->process($q->claim())==='completed','old/new job processed under latest policy');
    foreach([$queued,$new] as $jid){$job=$q->status($jid,$token['id'],$p);$stored=$read($job['result']['event_ids'][0]);check(!str_contains($stored['context'],'future-private')&&$stored['message']==='[redacted]','worker applies latest policy');}
    $replay=ok('POST','/ingest',$e,$bearer+['Idempotency-Key'=>'history']);check($replay['event_ids']===[$old],'prior receipt still resolves same UUID');
    // Historical rewrite preserves arbitrary numeric JSONB values not selected by rules.
    $stmt=$pdo->prepare("UPDATE log_events SET context=jsonb_set(context,'{exact}', '123.12345678901234567890123456789'::jsonb) WHERE id=:id");$stmt->execute(['id'=>$old]);
    $repo=new LogRepository($pdo);$tx=new TransactionManager($pdo);
    $dry=$tx->run(fn()=>$repo->redactHistory($p,null,50,false));check($dry['changed']>=1&&str_contains($read($old)['context'],'private-id'),'dry-run does not modify history');
    $written=$tx->run(fn()=>$repo->redactHistory($p,null,50,true));$history=$read($old);
    check($written['changed']>=1&&$history['message']==='[redacted]'&&!str_contains($history['context'],'private-id')&&str_contains($history['context'],'123.12345678901234567890123456789'),'history masks without rounding unrelated decimal');
    check($tx->run(fn()=>$repo->redactHistory($p,null,50,false))['changed']===0,'history rewrite idempotent');
    check((int)$pdo->query("SELECT count(*) FROM audit_log WHERE action='redaction.policy_changed'")->fetchColumn()===4,'policy changes audited');
    check((int)$pdo->query("SELECT count(*) FROM audit_log WHERE action='logs.history_redacted'")->fetchColumn()===1,'historical rewrite audited');
    // A separate reader holds a durable-write fence; policy publication must not bypass it.
    $second=$app->container()->get(\Cajeer\Logs\Database\ConnectionFactory::class)->make('pgsql');
    $second->beginTransaction();
    (new \Cajeer\Logs\Log\RedactionPolicies($second))->lock();
    $pdo->exec("SET lock_timeout='200ms'");
    try { check(call('PUT',$sp,['revision'=>2,'rules'=>[]],$admin)->status===503,'exclusive policy publication waits for active write fence'); }
    finally { $second->rollBack(); $pdo->exec("RESET lock_timeout"); }
    check(ok('GET',$sp,null,$admin)['revision']===2,'failed publication leaves revision unchanged');
    ok('PUT',$sp,['revision'=>2,'rules'=>[rule('regex','(a+)+$')]],$admin);
    $bad=$e;$bad['message']=str_repeat('a',10000).'!';$before=(int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn();
    check(call('POST','/ingest',$bad,$bearer+['Idempotency-Key'=>'regex-failure'])->status===503,'regex limit fails closed in real HTTP path');
    check((int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn()===$before,'failed masking writes no log');
    echo "Redaction PostgreSQL: $n checks passed\n";
}finally{$db->exec('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');}
