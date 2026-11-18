<?php
declare(strict_types=1);
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Database\MigrationRunner;
use Cajeer\Logs\Database\Repository\{ProjectRepository,UserRepository};
use Cajeer\Logs\Http\{Request,Response};
use Cajeer\Logs\Integration\WebhookOutbox;
if (getenv('CAJEER_TEST_DATABASE')!=='1') { fwrite(STDERR,"Use an isolated PostgreSQL with CAJEER_TEST_DATABASE=1 and DB_*\n");exit(1); }
$root=dirname(__DIR__);$bootstrap=require $root.'/core/bootstrap.php';$bootstrap->boot();$base=$bootstrap->container()->get(PDO::class);
$schema='cl_websub_'.bin2hex(random_bytes(8));$base->exec('CREATE SCHEMA "'.$schema.'"');
$n=0;
function verify(bool $result,string $label):void {global $n;if (!$result) throw new RuntimeException('FAIL '.$label);$n++;echo "PASS $label\n";}
function req(string $method,string $path,?array $body=null,array $auth=[]):Response {
    global $app;$headers=$auth;if ($body!==null) $headers['Content-Type']='application/json';
    return $app->handle(new Request($method,'/api/v1'.$path,$headers,[],$body===null?'':json_encode((object)$body,JSON_THROW_ON_ERROR)));
}
function response(Response $r):array {return json_decode($r->body,true,32,JSON_THROW_ON_ERROR);}
try {
    $app=new Application($root);$app->boot();$app->config()->set('database.schema',$schema);$app->config()->set('app.env','test');$app->config()->set('app.url','http://localhost:8080');
    $pdo=$app->container()->get(PDO::class);$runner=$app->container()->get(MigrationRunner::class);$runner->migrate();
    $users=new UserRepository($pdo);$users->create('webadmin@example.test','test-password-123456','administrator');
    $users->create('webview@example.test','test-password-123456','viewer');
    $p=(new ProjectRepository($pdo))->create('Alpha','alpha');$other=(new ProjectRepository($pdo))->create('Beta','beta');
    $login=function(string $email):array {$r=req('POST','/auth/login',['email'=>$email,'password'=>'test-password-123456']);verify($r->status===200,'login '.$email);return ['Cookie'=>explode(';',$r->header('Set-Cookie'),2)[0],'X-CSRF-Token'=>response($r)['csrf_token']];};
    $admin=$login('webadmin@example.test');$viewer=$login('webview@example.test');
    putenv('CAJEER_WEBHOOK_SUB='.str_repeat('s',32));putenv('CAJEER_WEBHOOK_NEW='.str_repeat('n',32));
    $rootPath='/projects/'.$p.'/webhooks';
    verify(req('GET',$rootPath,null,$viewer)->status===403,'viewer cannot read managed integrations');
    verify(req('POST',$rootPath,['url'=>'https://hook.example.com/w','secret_env'=>'CAJEER_WEBHOOK_SUB'],['Cookie'=>$admin['Cookie']])->status===403,'CSRF required');
    $create=req('POST',$rootPath,['url'=>'https://hook.example.com/w','secret_env'=>'CAJEER_WEBHOOK_SUB','events'=>['source.created','source.disabled']],$admin);
    verify($create->status===201,'create subscription through project API');$hook=response($create);$id=$hook['id'];
    verify($hook['events']===['source.created','source.disabled'] && !str_contains(json_encode($hook),'ssssssssssss'),'event filter and secret value redacted');
    verify(req('GET','/projects/'.$other.'/webhooks/'.$id,null,$admin)->status===404,'foreign project webhook not discoverable');
    verify(req('GET',$rootPath,null,$admin)->status===200,'list subscriptions');
    verify(req('POST',$rootPath,['url'=>'http://localhost/','secret_env'=>'CAJEER_WEBHOOK_SUB'],$admin)->status===422,'URL security validated');
    verify(req('POST',$rootPath,['url'=>'https://other.example.com/h','secret_env'=>'CAJEER_WEBHOOK_SUB','events'=>['source.created','source.created']],$admin)->status===422,'duplicate event filter denied');
    verify(req('PATCH',$rootPath.'/'.$id,['revision'=>99,'enabled'=>false],$admin)->status===409,'optimistic update conflict');
    $src=req('POST','/projects/'.$p.'/sources',['name'=>'Agent','slug'=>'agent'],$admin); verify($src->status===201,'create source');$sid=response($src)['id'];
    $db=$pdo->prepare('SELECT payload FROM webhook_deliveries WHERE webhook_id=:id');$db->execute(['id'=>$id]);$payload=json_decode($db->fetchColumn(),true);
    verify($payload['event']==='source.created' && $payload['source_id']===$sid,'source event committed to outbox');
    $test=req('POST',$rootPath.'/'.$id.'/test',null,$admin);verify($test->status===202,'queue actual test delivery');$did=response($test)['delivery_id'];
    $outbox=new WebhookOutbox($pdo);$state=$outbox->work(5,fn()=>503);
    verify($state['retry']===2,'test and event delivered to durable retry scheduler');
    $del=req('GET',$rootPath.'/'.$id.'/deliveries',null,$admin);verify($del->status===200&&count(response($del)['items'])===2,'delivery history endpoint scoped');
    $ats=req('GET',$rootPath.'/'.$id.'/attempts',null,$admin);verify($ats->status===200 && count(response($ats)['items'])===2,'attempt audit recorded');
    $failed=$pdo->prepare("UPDATE webhook_deliveries SET status='failed',completed_at=now() WHERE id=:id");$failed->execute(['id'=>$did]);
    verify(req('POST','/projects/'.$other.'/webhooks/'.$id.'/deliveries/'.$did.'/retry',null,$admin)->status===404,'cross-project delivery replay denied');
    verify(req('POST',$rootPath.'/'.$id.'/deliveries/'.$did.'/retry',null,$admin)->status===200,'manual retry existing stable ID');
    $patched=req('PATCH',$rootPath.'/'.$id,['revision'=>1,'secret_env'=>'CAJEER_WEBHOOK_NEW','enabled'=>false,'events'=>['logs.ingested']],$admin);
    verify($patched->status===200&&!response($patched)['enabled']&&response($patched)['revision']===2,'update, rotate secret reference and disable');
    $count=(int)$pdo->query("SELECT count(*) FROM webhook_deliveries WHERE status='cancelled'")->fetchColumn();verify($count>=1,'pending deliveries cancelled when disabled');
    verify(req('POST',$rootPath.'/'.$id.'/test',null,$admin)->status===404,'disabled subscriptions cannot create test deliveries');
    verify(req('PATCH',$rootPath.'/'.$id,['revision'=>2,'enabled'=>true],$admin)->status===200,'reenable webhook');
    $log=$pdo->query("SELECT action FROM audit_log WHERE action LIKE 'webhook.%'")->fetchAll(PDO::FETCH_COLUMN);
    verify(in_array('webhook.created',$log,true)&&in_array('webhook.updated',$log,true)&&in_array('webhook.test_queued',$log,true)&&in_array('webhook.delivery_retried',$log,true),'management mutations auditable');
    echo "Webhook subscription DB: $n tests passed\n";
} finally { $base->exec('DROP SCHEMA "'.$schema.'" CASCADE');putenv('CAJEER_WEBHOOK_SUB');putenv('CAJEER_WEBHOOK_NEW'); }
