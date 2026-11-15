<?php
declare(strict_types=1);
use Cajeer\Logs\Database\{ConnectionFactory,MigrationRunner,TransactionManager};
use Cajeer\Logs\Database\Repository\{ProjectRepository,SourceRepository};
use Cajeer\Logs\Http\{Request,Response,HttpException};
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Lifecycle\Retention;
use Cajeer\Logs\Queue\PostgresQueueDriver;
if(getenv('CAJEER_TEST_DATABASE')!=='1'){fwrite(STDERR,"Use a disposable PostgreSQL database with CAJEER_TEST_DATABASE=1.\n");exit(1);}
$root=dirname(__DIR__);$baseApp=require $root.'/core/bootstrap.php';$baseApp->boot();$base=$baseApp->container()->get(PDO::class);$schema='cl_sources_'.bin2hex(random_bytes(6));$base->exec('CREATE SCHEMA "'.$schema.'"');$tmp=sys_get_temp_dir().'/'.$schema;mkdir($tmp,0700);
$n=0;function check(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
function api(string $method,string $path,array $headers=[],?array $body=null,int $status=200,array $query=[]):array{global $app;$r=$app->handle(new Request($method,'/api/v1'.$path,$headers+['Content-Type'=>'application/json'],$query,$body===null?'':json_encode((object)$body,JSON_THROW_ON_ERROR),'127.0.0.1'));check($r->status===$status,"$method $path expected $status, got {$r->status}: ".substr($r->body,0,200));return [$r->body===''?[]:json_decode($r->body,true,64,JSON_THROW_ON_ERROR),$r];}
function login(string $email):array{[$d,$r]=api('POST','/auth/login',[],['email'=>$email,'password'=>'test-password-12345']);return ['Cookie'=>explode(';',$r->header('Set-Cookie'),2)[0],'X-CSRF-Token'=>$d['csrf_token']];}
try{
 $app=new Application($root);$app->boot();$app->config()->set('database.schema',$schema);$app->config()->set('app.env','test');$app->config()->set('app.url','http://localhost:8080');$pdo=$app->container()->get(PDO::class);
 foreach(glob($root.'/migrations/pgsql/000[1-9]_*.sql') as $f)copy($f,$tmp.'/'.basename($f));(new MigrationRunner($pdo,$tmp,$schema))->migrate();
 $legacy=$pdo->query("INSERT INTO projects(name,slug) VALUES('Legacy','legacy') RETURNING id")->fetchColumn();
 $old=(new SourceRepository($pdo))->create($legacy,'Old app','old-app');
 $pdo->exec("INSERT INTO users(email,password_hash,role) VALUES ('admin@example.test',".$pdo->quote(password_hash('test-password-12345',PASSWORD_ARGON2ID)).",'administrator')");
 $runner=$app->container()->get(MigrationRunner::class);check($runner->migrate()===['0010_source_management.sql','0011_token_management.sql','0012_database_integrity.sql','0013_ingest_signing.sql','0014_delivery_idempotency.sql','0015_authentication_sessions.sql','0016_saved_log_views.sql','0017_rbac_roles_permissions.sql','0018_identity_security.sql','0019_audit_accountability.sql']&&$runner->migrate()===[]&&$runner->status()['integrity']==='verified','upgrade 0.1.6 source schema');
 $repo=new SourceRepository($pdo);check($repo->find($legacy,$old)['status']==='active'&&$repo->find($legacy,$old)['revision']==='1','existing sources preserved');
 $admin=login('admin@example.test');$p=api('POST','/projects',$admin,['name'=>'Project A','slug'=>'project-a'],201)[0]['id'];$path='/projects/'.$p;
 $other=api('POST','/projects',$admin,['name'=>'Project B','slug'=>'project-b'],201)[0]['id'];
 $op=api('POST','/users',$admin,['email'=>'op@example.test','password'=>'test-password-12345','role'=>'operator'],201)[0]['id'];$viewer=api('POST','/users',$admin,['email'=>'viewer@example.test','password'=>'test-password-12345','role'=>'viewer'],201)[0]['id'];
 api('PUT',$path.'/members/'.$op,$admin,['role'=>'operator'],204);api('PUT',$path.'/members/'.$viewer,$admin,['role'=>'viewer'],204);$opAuth=login('op@example.test');$viewerAuth=login('viewer@example.test');
 $id=api('POST',$path.'/sources',$opAuth,['name'=>'App','slug'=>'app','source_type'=>'nginx','metadata'=>(object)['environment'=>'test']],201)[0]['id'];$sourcePath=$path.'/sources/'.$id;
 $id2=api('POST',$path.'/sources',$admin,['name'=>'Worker','slug'=>'worker'],201)[0]['id'];$path2=$path.'/sources/'.$id2;
 $foreign=api('POST','/projects/'.$other.'/sources',$admin,['name'=>'Foreign','slug'=>'app'],201)[0]['id'];
 check(api('GET',$sourcePath,$opAuth)[0]['source_type']==='nginx','created type and metadata');
 api('PATCH',$sourcePath,$viewerAuth,['revision'=>'1','name'=>'Denied'],403);api('GET','/projects/'.$other.'/sources/'.$foreign,$opAuth,null,403);api('PATCH',$path.'/sources/'.$foreign,$admin,['revision'=>'1','name'=>'Denied'],404);
 $edited=api('PATCH',$sourcePath,$opAuth,['revision'=>'1','name'=>'Renamed','description'=>'description','metadata'=>(object)['owner'=>'ops'],'max_batch_events'=>1,'max_body_bytes'=>2048,'retention_days'=>2])[0];check($edited['revision']==='2'&&$edited['metadata']['owner']==='ops','source update');api('PATCH',$sourcePath,$opAuth,['revision'=>'1','name'=>'Stale'],409);api('PATCH',$sourcePath,$opAuth,['revision'=>'2','slug'=>'new'],422);
 $list=api('GET',$path.'/sources',$opAuth,null,200,['q'=>'renamed','status'=>'active'])[0];check(count($list['items'])===1&&$list['items'][0]['id']===$id,'source search');
 $filtered=api('GET',$path.'/sources',$opAuth,null,200,['q'=>$id,'status'=>'active','source_type'=>'nginx'])[0];check(count($filtered['items'])===1&&$filtered['items'][0]['id']===$id,'source UUID and type search');check(api('GET',$path.'/sources',$opAuth,null,200,['source_type'=>'server'])[0]['items']===[],'source type filter empty');api('GET',$path.'/sources',$opAuth,null,422,['source_type'=>'bogus']);api('GET','/projects/'.$other.'/sources',$opAuth,null,403,['source_type'=>'nginx']);
 $tok=api('POST',$path.'/tokens',$opAuth,['name'=>'writer','scopes'=>['logs:read','logs:write'],'source_id'=>$id],201)[0]['token'];$bearer=['Authorization'=>'Bearer '.$tok];
 $wide=api('POST',$path.'/tokens',$opAuth,['name'=>'wide','scopes'=>['logs:write']],201)[0]['token'];$all=['Authorization'=>'Bearer '.$wide];
 api('GET',$path2,$bearer,null,403);api('GET',$path2.'/stats',$bearer,null,403);api('GET',$path.'/sources',$bearer,null,403);
 $e=['timestamp'=>gmdate('Y-m-d\TH:i:s\Z'),'source'=>'app','level'=>'info','message'=>'source event'];$e2=array_replace($e,['source'=>'worker']);$sync=$bearer+['Idempotency-Key'=>'sync-source'];$async=$bearer+['Idempotency-Key'=>'async-source'];
 $eid=api('POST','/ingest',$sync,$e,201)[0]['event_ids'][0];$job=api('POST','/ingest/async',$async,$e,202)[0]['job_id'];
 api('POST','/ingest',$bearer,['events'=>[$e,$e]],413);api('POST','/ingest',$bearer,array_replace($e,['message'=>str_repeat('x',2200)]),413);
 check(api('POST',$sourcePath.'/disable',$opAuth,['revision'=>'2'])[0]['status']==='disabled','disable source');api('POST','/ingest',$bearer,$e,409);api('POST','/ingest/async',$bearer+['Idempotency-Key'=>'new-disabled'],$e,409);
 api('POST','/ingest',$all,['events'=>[$e2,$e]],409);check($repo->find($p,$id2)['accepted_events_total']==='0'&&$repo->find($p,$id2)['rejected_events_total']==='1','mixed batch rollback including source counters, rejected events recorded');
 check(api('POST','/ingest',$sync,$e)[0]['event_ids']===[$eid],'receipt replay while disabled');check(api('POST','/ingest/async',$async,$e)[0]['job_id']===$job,'job replay while disabled');
 check(api('POST',$sourcePath.'/archive',$opAuth,['revision'=>'3'])[0]['status']==='archived','archive source');api('POST',$sourcePath.'/enable',$opAuth,['revision'=>'4'],409);
 $queue=new PostgresQueueDriver($pdo);$claim=$queue->claim();check($claim&&$queue->process($claim)==='completed','accepted job drains archived source');
 check(api('GET','/logs/'.$eid,$bearer,null,200,['project_id'=>$p])[0]['data']['id']===$eid,'archived history still readable');$stats=api('GET',$sourcePath.'/stats',$bearer)[0];check($stats['events']===2&&$stats['accepted_events_total']==='2'&&$stats['last_seen_at']!==null&&$stats['last_received_at']!==null,'real source statistics and no double count');
 check(api('POST',$sourcePath.'/restore',$opAuth,['revision'=>'4'])[0]['status']==='disabled','restore does not implicitly enable');api('POST','/ingest',$bearer,$e,409);api('POST',$sourcePath.'/enable',$opAuth,['revision'=>'5']);
 api('POST','/ingest',$all,['events'=>[$e,$e2]],201);check($repo->find($p,$id2)['accepted_events_total']==='1','mixed batch accepted per source');
 api('PATCH',$sourcePath,$opAuth,['revision'=>'6','events_per_minute'=>1,'max_batch_events'=>null]);
 // Both tokens must share the same quota and rollback must undo it. Ensure same minute.
 if ((float)$pdo->query('SELECT extract(second from clock_timestamp())')->fetchColumn()>57) sleep(3);
 api('POST','/ingest',$bearer,$e,201);api('POST','/ingest',$all,$e,429);
 $second=(new ConnectionFactory($app->config()))->make('pgsql');$second->exec('SET lock_timeout=100');$pdo->beginTransaction();
 try{$repo->admit($p,[$id2=>1],100);try{(new TransactionManager($second))->run(fn()=>(new SourceRepository($second))->transition($p,$id2,'1','disable'));throw new RuntimeException('disable should wait');}catch(PDOException $ex){check((string)$ex->getCode()==='55P03','concurrent disable waits for admission');}}finally{$pdo->rollBack();}
 // Source retention can shorten project policy, never extend it.
 $pdo->exec("UPDATE log_events SET received_at=now()-interval '3 days' WHERE source_id=".$pdo->quote($id));
 $pdo->exec("UPDATE log_events SET received_at=now()-interval '3 days' WHERE source_id=".$pdo->quote($id2));
 (new Retention($pdo))->set($p,10);check((new Retention($pdo))->run(100)===4,'source-specific retention deletes only expired source rows');
 check($repo->statistics($p,$id)['events']===0&&$repo->statistics($p,$id2)['events']===1&&$repo->find($p,$id)['last_seen_at']!==null,'retention preserves last seen and other source history');
 $a=$pdo->prepare("SELECT count(*) FROM audit_log WHERE target=:id AND action IN ('source.updated','source.disable','source.archive','source.restore','source.enable')");$a->execute(['id'=>$id]);check((int)$a->fetchColumn()===6,'source lifecycle audited');
 echo "Source Management PostgreSQL: $n checks passed\n";
}finally{$base->exec('DROP SCHEMA "'.$schema.'" CASCADE');foreach(glob($tmp.'/*')?:[] as $f)unlink($f);rmdir($tmp);}
