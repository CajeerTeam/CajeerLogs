<?php
declare(strict_types=1);
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Database\{MigrationRunner,TransactionManager};
use Cajeer\Logs\Database\Repository\{UserRepository,ProjectRepository,SourceRepository,TokenRepository};
use Cajeer\Logs\Log\{IngestEvent,LogRepository};
use Cajeer\Logs\Api\Controller\AssistantController;
use Cajeer\Logs\Http\{Request,HttpException};
if(getenv('CAJEER_TEST_DATABASE')!=='1'||getenv('APP_ENV')!=='test') throw new RuntimeException('Use a disposable PostgreSQL database.');
$root=dirname(__DIR__);$baseApp=require $root.'/core/bootstrap.php';$baseApp->boot();$base=$baseApp->container()->get(PDO::class);$schema='cl_ai_'.bin2hex(random_bytes(8));$base->exec('CREATE SCHEMA "'.$schema.'"');
$n=0;function check(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
function rejected(callable $fn,int $status):bool{try{$fn();}catch(HttpException $e){return $e->status===$status;}return false;}
try {
 $app=new Application($root);$app->boot();$cfg=$app->config();$cfg->set('database.schema',$schema);$cfg->set('app.env','test');$cfg->set('app.url','https://logs.example.test');$cfg->set('security.required_totp_roles','');$cfg->set('security.required_totp_permissions','');$cfg->set('ai.enabled',true);$cfg->set('ai.api_key','fixture-key');$cfg->set('ai.model','fixture-model');$cfg->set('ai.include_messages',true);
 $pdo=$app->container()->get(PDO::class);$app->container()->get(MigrationRunner::class)->migrate();
 $user=(new UserRepository($pdo))->create('ai@example.test','fixture-password-ai-123','viewer');$projects=new ProjectRepository($pdo);$sources=new SourceRepository($pdo);$tokens=new TokenRepository($pdo);$p=$projects->create('Assistant','assistant');$other=$projects->create('Other','other');
 $pdo->prepare('INSERT INTO project_members(project_id,user_id) VALUES(:p,:u)')->execute(['p'=>$p,'u'=>$user]);
 $repo=new LogRepository($pdo);$tx=new TransactionManager($pdo);$stamp=gmdate('Y-m-d\TH:i:s\Z');
 $seed=function(string $project,string $text)use($repo,$tx,$tokens,$sources,$stamp){$src=$sources->create($project,'App','app');$token=$tokens->create('writer',['logs:write'],$project);return $tx->run(fn()=>$repo->storeBatch([new IngestEvent('app','error',$text,(object)['private_context'=>'not-transmitted','exception'=>(object)['stack_trace'=>"RuntimeException\n#0 /app.php(12): fail()\npassword=stack-password"]],$stamp,traceId:'shared-trace-sensitive',requestId:'shared-request-sensitive')],$project,['app'=>$src],$token['id']))[0];};
 $id=$seed($p,'timeout local-event password=private-password');$foreign=$seed($other,'timeout foreign-event');
 $login=$app->handle(new Request('POST','/api/v1/auth/login',['Origin'=>'https://logs.example.test','Content-Type'=>'application/json'],[],json_encode(['email'=>'ai@example.test','password'=>'fixture-password-ai-123']),'192.0.2.1',true));check($login->status===200,'real local session');
 $headers=['Cookie'=>explode(';',$login->header('Set-Cookie'),2)[0],'Origin'=>'https://logs.example.test','Content-Type'=>'application/json','X-CSRF-Token'=>json_decode($login->body,true)['csrf_token']];
 $request=fn($project,$h=null)=>new Request('POST','/api/v1/assistant/chat',$h??$headers,[],json_encode(['project_id'=>$project,'question'=>'What failed?','keyword'=>'timeout','hours'=>1,'consent'=>true]),'192.0.2.1',true);
 $calls=0;$expectedInvestigation=null;$controller=new AssistantController($app,function($url,$body,$key)use(&$calls,$id,$foreign,&$expectedInvestigation){++$calls;$payload=json_decode($body,true,64,JSON_THROW_ON_ERROR);$context=json_decode(end($payload['input'])['content'],true,64,JSON_THROW_ON_ERROR)['context'];if(isset($context['investigation'])){check(array_column($context['events'],'id')===$expectedInvestigation&&!str_contains($body,$foreign)&&!str_contains($body,'private-password')&&!str_contains($body,'stack-password')&&!str_contains($body,'shared-trace-sensitive')&&!str_contains($body,'shared-request-sensitive')&&!str_contains($body,'not-transmitted')&&str_contains($body,'RuntimeException'),'real PostgreSQL scoped correlation and sanitized stack');}else{check(array_column($context['events'],'id')===[$id]&&!str_contains($body,$foreign)&&!str_contains($body,'private-password')&&!str_contains($body,'not-transmitted'),'real PostgreSQL project-scoped sanitized context');}return [200,json_encode(['status'=>'completed','output'=>[['type'=>'message','role'=>'assistant','content'=>[['type'=>'output_text','text'=>'Timeout observed in '.$id]]]],'usage'=>['total_tokens'=>40]])];});
 check($app->handle(new Request('GET','/api/v1/assistant/status',$headers,['project_id'=>$p],remoteAddress:'192.0.2.1',secureTransport:true))->status===200,'authenticated status route');
 check(rejected(fn()=>$controller->run($request($other),'assistantChat'),403)&&$calls===0,'cross-project access blocked before provider');
 check(rejected(fn()=>$controller->run($request($p,array_replace($headers,['X-CSRF-Token'=>'wrong'])),'assistantChat'),403)&&$calls===0,'invalid CSRF blocked before provider');
 $result=$controller->run($request($p),'assistantChat');$data=json_decode($result->body,true,64,JSON_THROW_ON_ERROR);check($result->status===200&&$calls===1&&array_column($data['evidence'],'id')===[$id]&&str_contains($data['answer'],$id),'full chat path and trusted evidence');
 // Real correlated records: same trace across projects, request-only correlation, and an unrelated local record.
 $worker=$sources->create($p,'Worker','worker');$writer=$tokens->create('worker',['logs:write'],$p);
 $before=gmdate('Y-m-d\TH:i:s\Z',time()-5);$after=gmdate('Y-m-d\TH:i:s\Z',time());
 $rows=$tx->run(fn()=>$repo->storeBatch([new IngestEvent('worker','info','starting',(object)[],$before,traceId:'shared-trace-sensitive'),new IngestEvent('worker','notice','request continued',(object)[],$after,requestId:'shared-request-sensitive'),new IngestEvent('worker','warning','unrelated',(object)[],$before,traceId:'unrelated-trace')],$p,['worker'=>$worker],$writer['id']));
 // Avoid relying on UUID generation order if timestamps tie: sort from persisted timestamps.
 $expectedInvestigation=[$rows[0],$id,$rows[1]];if($stamp===$after && strcmp($id,$rows[1])>0)$expectedInvestigation=[$rows[0],$rows[1],$id];
 $investigate=new Request('POST','/api/v1/assistant/chat',$headers,[],json_encode(['project_id'=>$p,'question'=>'Investigate this event','hours'=>1,'investigation'=>['event_id'=>$id],'consent'=>true]),'192.0.2.1',true);
 $investigated=json_decode($controller->run($investigate,'assistantChat')->body,true,64,JSON_THROW_ON_ERROR);
 check($calls===2&&array_column($investigated['evidence'],'id')===$expectedInvestigation&&$investigated['investigation']['seed_count']===1&&$investigated['investigation']['related_count']===2&&$investigated['investigation']['stack_count']===1,'full investigation endpoint chronological evidence and stack');
 $scope=\Cajeer\Logs\AI\Assistant::validate(['project_id'=>$p,'question'=>'Trace','hours'=>1,'investigation'=>['trace_id'=>'shared-trace-sensitive'],'consent'=>true]);
 $metadata=\Cajeer\Logs\AI\Investigation::sample($pdo,$scope['query'],$scope['investigation'],5000,false);
 check(array_column($metadata['events'],'id')===$expectedInvestigation&&!str_contains(json_encode($metadata),'shared-trace-sensitive')&&!str_contains(json_encode($metadata),'RuntimeException')&&$metadata['investigation']['stack_count']===0,'real explicit trace lookup and metadata-only policy');
 $cfg->set('ai.enabled',false);check(rejected(fn()=>$controller->run($request($p),'assistantChat'),403)&&$calls===2,'disabled provider cannot be called');
 $cfg->set('ai.enabled',true);$pdo->prepare('DELETE FROM project_members WHERE project_id=:p AND user_id=:u')->execute(['p'=>$p,'u'=>$user]);check(rejected(fn()=>$controller->run($request($p),'assistantChat'),403)&&$calls===2,'revoked project access enforced');
 echo "$n Assistant PostgreSQL checks passed\n";
} finally {unset($controller,$app,$pdo,$repo,$tx);$base->exec('DROP SCHEMA "'.$schema.'" CASCADE');}
