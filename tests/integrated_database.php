<?php
declare(strict_types=1);
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Database\MigrationRunner;
use Cajeer\Logs\Database\Repository\{UserRepository,ProjectRepository,SourceRepository,TokenRepository};
use Cajeer\Logs\Log\{IngestEvent,LogRepository};
use Cajeer\Logs\Database\TransactionManager;
use Cajeer\Logs\Http\{Request,HttpException};
use Cajeer\Logs\Security\Skif4erOidc;
use Cajeer\Logs\Api\Controller\AssistantController;
if(getenv('CAJEER_TEST_DATABASE')!=='1'||getenv('APP_ENV')!=='test')throw new RuntimeException('Use a disposable PostgreSQL database.');
$root=dirname(__DIR__);$baseApp=require $root.'/core/bootstrap.php';$baseApp->boot();$base=$baseApp->container()->get(PDO::class);$schema='cl_integrated_'.bin2hex(random_bytes(8));$base->exec('CREATE SCHEMA "'.$schema.'"');
$n=0;function check(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
function rejected(callable $fn,int $status):bool{try{$fn();}catch(HttpException $e){return $e->status===$status;}return false;}
try {
 $app=new Application($root);$app->boot();$cfg=$app->config();
 foreach(['database.schema'=>$schema,'app.env'=>'test','app.url'=>'https://logs.example.test','security.require_admin_totp'=>false,'security.required_totp_roles'=>'','security.required_totp_permissions'=>'','skif4er.enabled'=>true,'skif4er.client_id'=>'test-client','skif4er.client_secret'=>'test-secret','ai.enabled'=>true,'ai.api_key'=>'test-key','ai.model'=>'test-model','ai.include_messages'=>true] as $k=>$v)$cfg->set($k,$v);
 $pdo=$app->container()->get(PDO::class);$app->container()->get(MigrationRunner::class)->migrate();
 $user=(new UserRepository($pdo))->create('integrated@example.test','integrated-local-password-123','viewer');$projects=new ProjectRepository($pdo);$p=$projects->create('Integrated','integrated');$foreign=$projects->create('Foreign','foreign');
 $pdo->prepare('INSERT INTO project_members(project_id,user_id) VALUES(:p,:u)')->execute(['p'=>$p,'u'=>$user]);
 $pdo->prepare('INSERT INTO skif4er_identities(subject,user_id) VALUES(:s,:u)')->execute(['s'=>'integrated-subject','u'=>$user]);
 $seed=function($project,$msg)use($pdo){$source=(new SourceRepository($pdo))->create($project,'App','app');$token=(new TokenRepository($pdo))->create('writer',['logs:write'],$project);return (new TransactionManager($pdo))->run(fn()=>(new LogRepository($pdo))->storeBatch([new IngestEvent('app','error',$msg,(object)[],gmdate('Y-m-d\TH:i:s\Z'),traceId:'shared-trace')],$project,['app'=>$source],$token['id']))[0];};
 $id=$seed($p,'timeout password=private-local');$other=$seed($foreign,'timeout foreign-private');
 $call=fn($method,$path,$headers=[],$query=[],$body=[])=>new Request($method,'/api/v1'.$path,['Origin'=>'https://logs.example.test','Content-Type'=>'application/json']+$headers,$query,json_encode((object)$body,JSON_THROW_ON_ERROR),'192.0.2.1',true);
 $password=$app->handle($call('POST','/auth/login',[],[],['email'=>'integrated@example.test','password'=>'integrated-local-password-123']));check($password->status===200,'local password session');
 $headers=fn($res)=>['Cookie'=>explode(';',$res->header('Set-Cookie'),2)[0],'X-CSRF-Token'=>json_decode($res->body,true)['csrf_token']];$local=$headers($password);
 // Provider boundary is an already verified callback (JWT and discovery tested separately).
 $browser=Skif4erOidc::random();$pdo->prepare("INSERT INTO skif4er_login_flows(browser_hash,subject,user_id,expires_at) VALUES(:b,:s,:u,clock_timestamp()+interval '5 minutes')")->execute(['b'=>hash('sha256',$browser),'s'=>'integrated-subject','u'=>$user]);
 $login=$app->handle($call('POST','/auth/skif4er/finish',['Cookie'=>'__Host-cajeerlogs_oidc='.$browser]));check($login->status===200,'external callback creates real local session');$external=$headers($login);
 $chat=fn($project,$h)=>$call('POST','/assistant/chat',$h,[],['project_id'=>$project,'question'=>'Investigate timeout','hours'=>1,'incident'=>true,'save_history'=>true,'consent'=>true]);
 $calls=0;$revoke=false;$controller=new AssistantController($app,function($url,$body,$key)use(&$calls,&$revoke,$app,$call,$local,$id,$other){++$calls;check(str_contains($body,$id)&&!str_contains($body,$other)&&!str_contains($body,'private-local')&&!str_contains($body,'foreign-private'),'external session sends only authorized sanitized evidence');if($revoke){$res=$app->handle($call('POST','/auth/skif4er/revoke',$local,[],['password'=>'integrated-local-password-123']));check($res->status===200,'revoke external session during upstream response');}return [200,json_encode(['status'=>'completed','output'=>[['type'=>'message','role'=>'assistant','content'=>[['type'=>'output_text','text'=>'Timeout evidence '.$id]]]]])];});
 check(rejected(fn()=>$controller->run($chat($foreign,$external),'assistantChat'),403)&&$calls===0,'external account cannot access foreign project');
 check($app->handle($call('GET','/system/updates',$external))->status===403,'external viewer cannot manage or read system updates');
 $answer=json_decode($controller->run($chat($p,$external),'assistantChat')->body,true);check(isset($answer['history_id'])&&array_column($answer['evidence'],'id')===[$id],'external session saves incident history');
 $history=$call('GET','/assistant/history',$local,['project_id'=>$p,'id'=>$answer['history_id']]);check($controller->run($history,'assistantHistory')->status===200,'same local owner reopens history using password session');
 $revoke=true;check(rejected(fn()=>$controller->run($chat($p,$external),'assistantChat'),401),'revocation during upstream call prevents response/history delivery');
 check($calls===2&&$app->handle($call('GET','/auth/me',$external))->status===401&&$app->handle($call('GET','/auth/me',$local))->status===200,'only external session revoked');
 $list=json_decode($controller->run($call('GET','/assistant/history',$local,['project_id'=>$p]),'assistantHistory')->body,true);check(count($list['items'])===1,'revoked in-flight request did not save history');
 $cfg->set('skif4er.client_secret','');
 check($app->handle($call('GET','/auth/skif4er/account',$local))->status===200,'local account remains accessible when provider config is broken');
 check($app->handle($call('POST','/auth/skif4er/unlink',$local,[],['password'=>'integrated-local-password-123']))->status===200,'local unlink works without provider credentials');
 echo "Integrated PostgreSQL: $n checks passed\n";
} finally {if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();$base->exec('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');}
