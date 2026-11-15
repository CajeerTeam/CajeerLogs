<?php
declare(strict_types=1);
use Cajeer\Logs\Database\{ConnectionFactory,MigrationRunner,TransactionManager};
use Cajeer\Logs\Database\Repository\{ProjectRepository,SourceRepository,TokenRepository,UserRepository};
use Cajeer\Logs\Http\Request;
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Queue\PostgresQueueDriver;
use Cajeer\Logs\Security\SignedPayload;
if(getenv('CAJEER_TEST_DATABASE')!=='1'){fwrite(STDERR,"Use CAJEER_TEST_DATABASE=1 with a disposable PostgreSQL 17/18 database.\n");exit(1);}
$root=dirname(__DIR__);$baseApp=require $root.'/core/bootstrap.php';$baseApp->boot();$base=$baseApp->container()->get(PDO::class);$schema='cl_tokens_'.bin2hex(random_bytes(6));$base->exec('CREATE SCHEMA "'.$schema.'"');$tmp=sys_get_temp_dir().'/'.$schema;mkdir($tmp,0700);
$n=0;
function check(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
function api(string $method,string $path,array $headers=[],?array $body=null,int $status=200,array $query=[]):array{global $app;$r=$app->handle(new Request($method,'/api/v1'.$path,$headers+['Content-Type'=>'application/json'],$query,$body===null?'':json_encode((object)$body,JSON_THROW_ON_ERROR),'127.0.0.1'));check($r->status===$status,"$method $path expected $status, got {$r->status}");return [$r->body===''?[]:json_decode($r->body,true,64,JSON_THROW_ON_ERROR),$r];}
function login(string $email):array{[$d,$r]=api('POST','/auth/login',[],['email'=>$email,'password'=>'test-only-password-12345']);return ['Cookie'=>explode(';',$r->header('Set-Cookie'),2)[0],'X-CSRF-Token'=>$d['csrf_token']];}
function bearer(array $token):array{return ['Authorization'=>'Bearer '.$token['token']];}
function signed(array $token,array $event,string $key,?string $signingSecret=null):array{ $body=json_encode((object)$event,JSON_THROW_ON_ERROR);$ts=(string)time();$nonce=bin2hex(random_bytes(16));$headers=bearer($token)+['Content-Type'=>'application/json','Idempotency-Key'=>$key,'X-Log-Timestamp'=>$ts,'X-Log-Nonce'=>$nonce];$req=new Request('POST','/api/v1/ingest',$headers,[],$body);$headers['X-Log-Signature']=(new SignedPayload())->sign(SignedPayload::canonical($req,$ts,$nonce,$key),$signingSecret??$token['token']);return $headers; }
try{
 $app=new Application($root);$app->boot();$app->config()->set('database.schema',$schema);$app->config()->set('app.env','test');$app->config()->set('app.url','http://localhost:8080');$app->config()->set('api.rate_limit',600);$app->config()->set('ingest.require_hmac',false);$app->config()->set('security.require_admin_totp',false);$pdo=$app->container()->get(PDO::class);
 // Real historical schema and existing hash survive migration 0011.
 foreach(glob($root.'/migrations/pgsql/*.sql') as $f) if((int)substr(basename($f),0,4)<11)copy($f,$tmp.'/'.basename($f));(new MigrationRunner($pdo,$tmp,$schema))->migrate();
 $actor=(new UserRepository($pdo))->create('admin@example.test','test-only-password-12345','administrator');$p=(new ProjectRepository($pdo))->create('Project','project');$source=(new SourceRepository($pdo))->create($p,'App','app');
 $legacy=(new TokenRepository($pdo))->create('Existing',['logs:read'],$p,$source);$runner=$app->container()->get(MigrationRunner::class);
 check($runner->migrate()===['0011_token_management.sql','0012_database_integrity.sql','0013_ingest_signing.sql','0014_delivery_idempotency.sql','0015_authentication_sessions.sql','0016_saved_log_views.sql']&&$runner->migrate()===[]&&$runner->status()['integrity']==='verified','0.1.7 upgrade and replay');
 check((new TokenRepository($pdo))->findActive($legacy['token'])['id']===$legacy['id'],'existing credential survives upgrade');
 $admin=login('admin@example.test');$path='/projects/'.$p;$tokens=$path.'/tokens';$accounts=$path.'/service-accounts';
 $other=api('POST','/projects',$admin,['name'=>'Other','slug'=>'other'],201)[0]['id'];$foreign=api('POST','/projects/'.$other.'/sources',$admin,['name'=>'Foreign','slug'=>'foreign'],201)[0]['id'];
 $operator=api('POST','/users',$admin,['email'=>'op@example.test','password'=>'test-only-password-12345','role'=>'operator'],201)[0]['id'];$viewer=api('POST','/users',$admin,['email'=>'view@example.test','password'=>'test-only-password-12345','role'=>'viewer'],201)[0]['id'];
 api('PUT',$path.'/members/'.$operator,$admin,['role'=>'operator'],204);api('PUT',$path.'/members/'.$viewer,$admin,['role'=>'viewer'],204);$op=login('op@example.test');$view=login('view@example.test');
 api('POST',$accounts,$view,['name'=>'Denied'],403);api('GET','/projects/'.$other.'/tokens',$op,null,403);
 $acc=api('POST',$accounts,$op,['name'=>'Backend'],201)[0];$foreignAcc=api('POST','/projects/'.$other.'/service-accounts',$admin,['name'=>'Other service'],201)[0];
 api('POST',$accounts,$op,['name'=>'Backend'],409);api('POST',$tokens,$op,['name'=>'Foreign account','scopes'=>['logs:read'],'service_account_id'=>$foreignAcc['id']],409);
 api('POST',$tokens,$op,['name'=>'Foreign source','scopes'=>['logs:read'],'source_id'=>$foreign],404);
 api('POST',$tokens,$op,['name'=>'Object scope','scopes'=>(object)['0'=>'logs:write']],422);
 $writer=api('POST',$tokens,$op,['name'=>'Writer','description'=>'production','scopes'=>['logs:read','logs:write'],'source_id'=>$source,'service_account_id'=>$acc['id'],'expires_in'=>7200],201)[0];$old=$tokens.'/'.$writer['id'];
 check($writer['revision']==='1'&&$writer['service_account_id']===$acc['id']&&$writer['created_by']===$operator,'issued scoped service credential');
 $list=api('GET',$tokens,$op,null,200,['service_account_id'=>$acc['id'],'status'=>'active'])[0];check(count($list['items'])===1&&!isset($list['items'][0]['token_hash'])&&!str_contains(json_encode($list),$writer['token']),'filtered list excludes secrets');
 $stmt=$pdo->prepare('SELECT token_hash,last_used_at FROM api_tokens WHERE id=:id');$stmt->execute(['id'=>$writer['id']]);check($stmt->fetch()['token_hash']===hash('sha256',$writer['token']),'only digest persisted');
 api('GET','/auth/token',bearer($writer));$stmt->execute(['id'=>$writer['id']]);$used=$stmt->fetch()['last_used_at'];api('GET','/auth/token',bearer($writer));$stmt->execute(['id'=>$writer['id']]);check($used!==null&&$stmt->fetch()['last_used_at']===$used,'fresh last_used_at not rewritten');
 api('PATCH',$old,$view,['revision'=>'1','name'=>'Denied'],403);api('PATCH',$old,['Cookie'=>$op['Cookie']],['revision'=>'1','name'=>'No CSRF'],403);api('PATCH','/projects/'.$other.'/tokens/'.$writer['id'],$admin,['revision'=>'1','name'=>'Denied'],404);
 $changed=api('PATCH',$old,$op,['revision'=>'1','name'=>'Writer v2','description'=>'changed','requests_per_minute'=>100])[0];check($changed['revision']==='2'&&$changed['name']==='Writer v2','metadata revision');api('PATCH',$old,$op,['revision'=>'1','name'=>'Stale'],409);api('PATCH',$old,$op,['revision'=>'2','scopes'=>['logs:read']],422);
 $event=['source'=>'app','level'=>'info','message'=>'rotation event','timestamp'=>gmdate('Y-m-d\TH:i:s\Z')];$bodyHeaders=bearer($writer)+['Idempotency-Key'=>'before-rotation'];
 $first=api('POST','/ingest',$bodyHeaders,$event,201)[0];$job=api('POST','/ingest/async',bearer($writer)+['Idempotency-Key'=>'pending-before-rotation'],$event,202)[0]['job_id'];
 $rotated=api('POST',$old.'/rotate',$op,['revision'=>'2','expires_in'=>3600,'overlap_seconds'=>600,'scopes'=>['logs:write']],201)[0];
 check($rotated['id']!==$writer['id']&&$rotated['token']!==$writer['token']&&$rotated['source_id']===$source&&$rotated['service_account_id']===$acc['id']&&$rotated['scopes']===['logs:write']&&$rotated['previous']['rotated_to_id']===$rotated['id'],'successor narrowed, same source and account');
 check(strtotime($rotated['previous']['expires_at'])<=time()+601&&strtotime($rotated['previous']['expires_at'])>time(),'bounded overlap expiry');
 api('POST',$old.'/rotate',$op,['revision'=>'3','overlap_seconds'=>0],409);api('POST',$tokens.'/'.$rotated['id'].'/rotate',$op,['revision'=>'1','scopes'=>['logs:read','logs:write']],422);
 api('GET','/auth/token',bearer($writer));api('GET','/auth/token',bearer($rotated));api('GET','/logs',bearer($rotated),null,403,['project_id'=>$p]);
 check(api('POST','/ingest',$bodyHeaders,$event)[0]['event_ids']===$first['event_ids'],'old credential can replay during overlap');
 api('POST',$old.'/rotate',$op,['revision'=>'3','scopes'=>(object)['0'=>'logs:write']],422);
 api('GET','/ingest/jobs/'.$job,bearer($rotated));api('GET','/ingest/jobs/'.$job,bearer($writer));
 $app->config()->set('ingest.require_hmac',true);api('POST','/ingest',signed($rotated,$event,'wrong-signing-key',$writer['token']),$event,401);api('POST','/ingest',signed($rotated,$event,'new-signed-key'),$event,201);$app->config()->set('ingest.require_hmac',false);
 // Simulate expiry using DB clock without a long sleep, then verify both credentials.
 $pdo->prepare("UPDATE api_tokens SET expires_at=clock_timestamp()-interval '1 second' WHERE id=:id")->execute(['id'=>$writer['id']]);api('GET','/auth/token',bearer($writer),null,401);api('GET','/auth/token',bearer($rotated));
 $queue=new PostgresQueueDriver($pdo);$claim=$queue->claim();check($claim&&$queue->process($claim)==='completed','pre-rotation pending job survives expiry and narrowed successor');
 // Immediate revoke waits for transactional authentication even when usage telemetry is fresh.
 $second=(new ConnectionFactory($app->config()))->make('pgsql');$second->exec('SET lock_timeout=100');$pdo->beginTransaction();
 try{check((new TokenRepository($pdo))->findActive($rotated['token'])!==null,'transactional auth acquires credential lock');try{(new TokenRepository($second))->revoke($rotated['id']);throw new RuntimeException('revoke should wait');}catch(PDOException $e){check((string)$e->getCode()==='55P03','concurrent revoke waits for ingest lock');}}finally{$pdo->rollBack();}
 $replacement=api('POST',$tokens.'/'.$rotated['id'].'/rotate',$op,['revision'=>'1','expires_in'=>3600,'overlap_seconds'=>0],201)[0];api('GET','/auth/token',bearer($rotated),null,401);api('GET','/auth/token',bearer($replacement));
 $limited=api('POST',$tokens,$op,['name'=>'Limited','scopes'=>['logs:write'],'source_id'=>$source,'requests_per_minute'=>2],201)[0];
 if((float)$pdo->query('SELECT extract(second from clock_timestamp())')->fetchColumn()>55)sleep(5);
 api('GET','/auth/token',bearer($limited));api('POST','/ingest',bearer($limited),$event,201);api('GET','/auth/token',bearer($limited),null,429); // shared limit; ingest re-auth is not double charged.
 $wide=api('POST',$tokens,$op,['name'=>'Wide','scopes'=>['logs:write']],201)[0];$foreignToken=api('POST','/projects/'.$other.'/tokens',$admin,['name'=>'Foreign','scopes'=>['logs:write']],201)[0];
 api('POST',$tokens.'/revoke-all',$op,['confirm'=>false],422);api('POST',$tokens.'/revoke-all',$op,['confirm'=>true,'source_id'=>$foreign],404);
 $result=api('POST',$accounts.'/'.$acc['id'].'/disable',$op,['confirm'=>true])[0];check($result['disabled_at']!==null&&$result['revoked']>=1,'disable service revokes remaining credentials');api('GET','/auth/token',bearer($replacement),null,401);api('POST',$tokens,$op,['name'=>'Denied','scopes'=>['logs:write'],'service_account_id'=>$acc['id']],409);
 $result=api('POST',$tokens.'/revoke-all',$op,['confirm'=>true,'source_id'=>$source])[0];check($result['revoked']>=2,'source bulk revoke');api('GET','/auth/token',bearer($legacy),null,401);api('GET','/auth/token',bearer($limited),null,401);api('GET','/auth/token',bearer($wide));api('GET','/auth/token',bearer($foreignToken));
 api('POST',$tokens.'/revoke-all',$op,['confirm'=>true]);api('GET','/auth/token',bearer($wide),null,401);api('GET','/auth/token',bearer($foreignToken));
 $sys=api('POST','/system/tokens',$admin,['name'=>'Monitor','scopes'=>['system:read']],201)[0];$sys2=api('POST','/system/tokens/'.$sys['id'].'/rotate',$admin,['revision'=>'1','overlap_seconds'=>0],201)[0];api('GET','/auth/token',bearer($sys),null,401);api('GET','/system/report',bearer($sys2));api('POST','/system/tokens/revoke-all',$admin,['confirm'=>true]);api('GET','/auth/token',bearer($sys2),null,401);
 check((int)$pdo->query("SELECT count(*) FROM audit_log WHERE action='token.rotated'")->fetchColumn()===3,'rotation audit exactly once per committed rotation');
 $audit=$pdo->query('SELECT context::text FROM audit_log')->fetchAll(PDO::FETCH_COLUMN);check(!str_contains(json_encode($audit),$writer['token'])&&!str_contains(json_encode($audit),$rotated['token']),'no credentials in audit');
 echo "API Token Management PostgreSQL: $n checks passed\n";
}finally{if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();$base->exec('DROP SCHEMA "'.$schema.'" CASCADE');foreach(glob($tmp.'/*')?:[] as $f)unlink($f);rmdir($tmp);}
