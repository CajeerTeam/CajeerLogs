<?php
declare(strict_types=1);
use Cajeer\Logs\Database\{ConnectionFactory,MigrationRunner,TransactionManager};
use Cajeer\Logs\Database\Repository\ProjectRepository;
use Cajeer\Logs\Http\{Request,Response,HttpException};
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Lifecycle\Retention;
use Cajeer\Logs\Queue\PostgresQueueDriver;
if(getenv('CAJEER_TEST_DATABASE')!=='1'){fwrite(STDERR,"Use a disposable PostgreSQL database with CAJEER_TEST_DATABASE=1.\n");exit(1);}
$root=dirname(__DIR__);$baseApp=require $root.'/core/bootstrap.php';$baseApp->boot();$base=$baseApp->container()->get(PDO::class);$schema='cl_projects_'.bin2hex(random_bytes(6));$base->exec('CREATE SCHEMA "'.$schema.'"');$tmp=sys_get_temp_dir().'/'.$schema;mkdir($tmp,0700);
$n=0;function check(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
function api(string $method,string $path,array $headers=[],?array $body=null,int $status=200,array $query=[]):array{global $app;$r=$app->handle(new Request($method,'/api/v1'.$path,$headers+['Content-Type'=>'application/json'],$query,$body===null?'':json_encode((object)$body,JSON_THROW_ON_ERROR),'127.0.0.1'));check($r->status===$status,"$method $path expected $status, got {$r->status}: ".substr($r->body,0,200));return [$r->body===''?[]:json_decode($r->body,true,64,JSON_THROW_ON_ERROR),$r];}
function login(string $email):array{[$d,$r]=api('POST','/auth/login',[],['email'=>$email,'password'=>'test-password-12345']);return ['Cookie'=>explode(';',$r->header('Set-Cookie'),2)[0],'X-CSRF-Token'=>$d['csrf_token']];}
try{
 $app=new Application($root);$app->boot();$app->config()->set('database.schema',$schema);$app->config()->set('app.env','test');$app->config()->set('app.url','http://localhost:8080');$pdo=$app->container()->get(PDO::class);
 foreach(glob($root.'/migrations/pgsql/000[1-8]_*.sql') as $f)copy($f,$tmp.'/'.basename($f));(new MigrationRunner($pdo,$tmp,$schema))->migrate();
 $legacy=$pdo->query("INSERT INTO projects(name,slug) VALUES('Legacy','legacy') RETURNING id")->fetchColumn();
 $pdo->exec("INSERT INTO users(email,password_hash,role) VALUES ('admin@example.test',".$pdo->quote(password_hash('test-password-12345',PASSWORD_ARGON2ID)).",'administrator')");
 $runner=$app->container()->get(MigrationRunner::class);check($runner->migrate()===['0009_project_management.sql','0010_source_management.sql','0011_token_management.sql','0012_database_integrity.sql','0013_ingest_signing.sql','0014_delivery_idempotency.sql','0015_authentication_sessions.sql','0016_saved_log_views.sql','0017_rbac_roles_permissions.sql','0018_identity_security.sql','0019_audit_accountability.sql','0020_rate_limit_buckets.sql','0021_retention_execution_history.sql','0022_queue_accounting.sql','0023_queue_backpressure.sql','0024_project_queue_fairness.sql']&&$runner->migrate()===[]&&$runner->status()['integrity']==='verified','upgrade old schema and preserve history');
 $repo=new ProjectRepository($pdo);check($repo->find($legacy)['status']==='active'&&$repo->find($legacy)['revision']==='1','existing project defaults');
 $admin=login('admin@example.test');$p=api('POST','/projects',$admin,['name'=>'Project A','slug'=>'project-a'],201)[0]['id'];$path='/projects/'.$p;
 $other=api('POST','/projects',$admin,['name'=>'Project B','slug'=>'project-b'],201)[0]['id'];
 $op=api('POST','/users',$admin,['email'=>'op@example.test','password'=>'test-password-12345','role'=>'operator'],201)[0]['id'];$viewer=api('POST','/users',$admin,['email'=>'viewer@example.test','password'=>'test-password-12345','role'=>'viewer'],201)[0]['id'];
 api('PUT',$path.'/members/'.$op,$admin,['role'=>'viewer'],204);api('PUT',$path.'/members/'.$viewer,$admin,['role'=>'operator'],204);$opAuth=login('op@example.test');$viewerAuth=login('viewer@example.test');
 check(api('GET',$path,$opAuth)[0]['effective_role']==='viewer','operator constrained per project');check(api('GET',$path,$viewerAuth)[0]['effective_role']==='viewer','viewer never elevated');api('GET','/projects/'.$other.'/stats',$opAuth,null,403);api('POST',$path.'/sources',$opAuth,['name'=>'App','slug'=>'app'],403);api('PATCH',$path,$opAuth,['revision'=>'1','name'=>'Denied'],403);
 api('PUT',$path.'/members/'.$op,$admin,['role'=>'operator'],204);check(api('GET',$path,$opAuth)[0]['effective_role']==='operator','project role restored');
 $source=api('POST',$path.'/sources',$opAuth,['name'=>'App','slug'=>'app'],201)[0]['id'];$tok=api('POST',$path.'/tokens',$opAuth,['name'=>'writer','scopes'=>['logs:read','logs:write'],'source_id'=>$source],201)[0]['token'];$bearer=['Authorization'=>'Bearer '.$tok];
 $edited=api('PATCH',$path,$admin,['revision'=>'1','name'=>'Renamed','description'=>'Description','metadata'=>(object)['env'=>'test'],'retention_days'=>30,'max_batch_events'=>2,'max_body_bytes'=>2048,'events_per_minute'=>null])[0];check($edited['revision']==='2'&&$edited['metadata']['env']==='test'&&$edited['retention_days']===30,'metadata and policies persisted');api('PATCH',$path,$admin,['revision'=>'1','name'=>'Stale'],409);api('PATCH',$path,$admin,['revision'=>'2','slug'=>'change'],422);
 $list=api('GET','/projects',$admin,null,200,['q'=>'renamed','status'=>'active'])[0];check(count($list['items'])===1&&$list['items'][0]['id']===$p,'search and status filter');
 $uuidList=api('GET','/projects',$opAuth,null,200,['q'=>$p])[0];check(count($uuidList['items'])===1&&$uuidList['items'][0]['id']===$p,'project UUID search');check(api('GET','/projects',$opAuth,null,200,['q'=>$other])[0]['items']===[],'foreign project UUID hidden');
 $e=['timestamp'=>gmdate('Y-m-d\TH:i:s\Z'),'source'=>'app','level'=>'info','message'=>'before archive'];$sync=$bearer+['Idempotency-Key'=>'sync-project'];$async=$bearer+['Idempotency-Key'=>'async-project'];
 $id=api('POST','/ingest',$sync,$e,201)[0]['event_ids'][0];$job=api('POST','/ingest/async',$async,$e,202)[0]['job_id'];
 api('POST','/ingest',$bearer,['events'=>[$e,$e,$e]],413);api('POST','/ingest',$bearer,array_replace($e,['message'=>str_repeat('x',2200)]),413);
 $arch=api('POST',$path.'/archive',$admin,['revision'=>'2'])[0];check($arch['status']==='archived'&&$arch['revision']==='3','archived project');api('POST','/ingest',$bearer,$e,409);api('POST','/ingest/async',$bearer+['Idempotency-Key'=>'new-archived'],$e,409);
 check(api('POST','/ingest',$sync,$e)[0]['event_ids']===[$id],'existing sync receipt available while archived');check(api('POST','/ingest/async',$async,$e)[0]['job_id']===$job,'existing async receipt available while archived');
 $queue=new PostgresQueueDriver($pdo);$claim=$queue->claim();check($claim&&$queue->process($claim)==='completed','accepted job drains archived project');
 check(api('GET','/logs/'.$id,$bearer,null,200,['project_id'=>$p])[0]['data']['id']===$id,'archived history still readable');$stats=api('GET',$path.'/stats',$opAuth)[0];check($stats['events']===2&&$stats['sources']===1&&$stats['active_sources']===1&&$stats['disabled_sources']===0&&$stats['archived_sources']===0&&$stats['queued_jobs']===0,'actual statistics after draining');
 $rest=api('POST',$path.'/restore',$admin,['revision'=>'3'])[0];check($rest['status']==='active','restore resumes ingest');api('POST','/ingest',$bearer,$e,201);
 (new Retention($pdo))->set($p,14);check($repo->find($p)['revision']==='5','CLI retention participates in revision');api('PATCH',$path,$admin,['revision'=>'4','name'=>'stale'],409);
 $updated=api('PATCH',$path,$admin,['revision'=>'5','events_per_minute'=>1])[0];api('POST','/ingest',$bearer,['events'=>[$e,$e]],429);
 // An independent session cannot archive while admission holds the project row share lock.
 $second=(new ConnectionFactory($app->config()))->make('pgsql');$second->exec('SET lock_timeout=100');$pdo->beginTransaction();
 try{$repo->admit($p,100,1);try{(new TransactionManager($second))->run(fn()=>(new ProjectRepository($second))->archive($p,'6',true));throw new RuntimeException('archive should wait');}catch(PDOException $e){check((string)$e->getCode()==='55P03','concurrent archive waits for admission');}}finally{$pdo->rollBack();}
 check($repo->find($p)['status']==='active'&&$repo->find($p)['revision']==='6','lock timeout does not mutate project');
 // Keep both quota checks in the same fixed window, avoiding a flaky boundary test.
 if ((float)$pdo->query('SELECT extract(second from clock_timestamp())')->fetchColumn()>58) sleep(2);
 // Force a known minute bucket and verify weighted quota in the same transaction, then rollback deliberately.
 $tx=new TransactionManager($pdo);try{$tx->run(function()use($repo,$p){$repo->admit($p,100,1);try{$repo->admit($p,100,1);throw new RuntimeException('quota unexpectedly accepted');}catch(HttpException $e){check($e->status===429,'shared quota enforced');}throw new DomainException('rollback quota');});}catch(DomainException){}
 api('POST','/ingest',$bearer,$e,201);$s=$pdo->prepare("SELECT count(*) FROM audit_log WHERE target=:id AND action IN ('project.updated','project.archived','project.restored')");$s->execute(['id'=>$p]);check((int)$s->fetchColumn()===4,'lifecycle audit committed');
 echo "Project Management PostgreSQL: $n checks passed\n";
}finally{$base->exec('DROP SCHEMA "'.$schema.'" CASCADE');foreach(glob($tmp.'/*')?:[] as $f)unlink($f);rmdir($tmp);}
