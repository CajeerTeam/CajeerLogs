<?php
declare(strict_types=1);
use Cajeer\Logs\Database\{MigrationRunner,TransactionManager,IntegrityInspector};
use Cajeer\Logs\Database\Repository\{ProjectRepository,SourceRepository,TokenRepository,UserRepository};
use Cajeer\Logs\Http\{Request,Response};
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Log\{IngestValidator,LogRepository};
use Cajeer\Logs\Queue\PostgresQueueDriver;
if(getenv('CAJEER_TEST_DATABASE')!=='1'){fwrite(STDERR,"Use CAJEER_TEST_DATABASE=1 with an isolated PostgreSQL 17/18 database.\n");exit(1);}
$root=dirname(__DIR__);$base=require $root.'/core/bootstrap.php';$base->boot();$db=$base->container()->get(PDO::class);
$schema='cl_delivery_'.bin2hex(random_bytes(8));$db->exec('CREATE SCHEMA "'.$schema.'"');$tmp=sys_get_temp_dir().'/'.$schema;mkdir($tmp,0700);$n=0;
function check(bool $ok,string $name):void{global $n;if(!$ok)throw new RuntimeException('FAIL '.$name);++$n;echo "PASS $name\n";}
function data(Response $r):array{return json_decode($r->body,true,64,JSON_THROW_ON_ERROR);}
function send(string $body,string $key,string $token,bool $async=false):Response{global $app;return $app->handle(new Request('POST','/api/v1/ingest'.($async?'/async':''),['Content-Type'=>'application/json','Authorization'=>'Bearer '.$token,'Idempotency-Key'=>$key],[],$body,'127.0.0.1'));}
function poll(string $id,string $token):Response{global $app;return $app->handle(new Request('GET','/api/v1/ingest/jobs/'.$id,['Authorization'=>'Bearer '.$token],[],'','127.0.0.1'));}
function expect(string $body,string $key,string $token,int $status,bool $async=false):array{$r=send($body,$key,$token,$async);check($r->status===$status,"$key expected $status, got {$r->status}");return data($r);}
try{
    $master=bin2hex(random_bytes(32));$app=new Application($root);$app->boot();$app->config()->set('database.schema',$schema);$app->config()->set('app.env','test');$app->config()->set('app.url','http://localhost:8080');$app->config()->set('api.rate_limit',600);$app->config()->set('ingest.require_hmac',false);$app->config()->set('security.totp_key',$master);$app->config()->set('ingest.idempotency_seconds',172800);
    $pdo=$app->container()->get(PDO::class);$tx=new TransactionManager($pdo);
    foreach(glob($root.'/migrations/pgsql/*.sql') as $f)if((int)substr(basename($f),0,4)<14)copy($f,$tmp.'/'.basename($f));
    (new MigrationRunner($pdo,$tmp,$schema))->migrate();
    $actor=(new UserRepository($pdo))->create('delivery@example.test','test-delivery-password-1234','administrator');
    $project=(new ProjectRepository($pdo))->create('Delivery','delivery');$source=(new SourceRepository($pdo))->create($project,'App','app');
    $tokens=new TokenRepository($pdo);$old=$tokens->create('Old',['logs:write'],$project,$source);$next=$tokens->create('Historical successor',['logs:write'],$project,$source);
    $pdo->prepare('UPDATE api_tokens SET rotated_to_id=:next,rotated_at=clock_timestamp() WHERE id=:old')->execute(['next'=>$next['id'],'old'=>$old['id']]);
    $body=json_encode(['timestamp'=>gmdate('Y-m-d\TH:i:s\Z'),'source'=>'app','level'=>'info','message'=>'delivery guarantee'],JSON_THROW_ON_ERROR);
    $event=(new IngestValidator())->parse(new Request('POST','/api/v1/ingest',['Content-Type'=>'application/json'],[],$body))[0];
    $repo=new LogRepository($pdo);$legacyHash=hash_hmac('sha256',$body,$old['token']);
    $legacy=$tx->run(function()use($repo,$event,$project,$source,$old,$legacyHash){$ids=$repo->storeBatch([$event],$project,['app'=>$source],$old['id']);$repo->saveReceipt($old['id'],hash('sha256','legacy-sync'),$legacyHash,$ids);return $ids;});
    $q=new PostgresQueueDriver($pdo);$legacyJob=$tx->run(fn()=>$q->enqueue($old['id'],$project,hash('sha256','legacy-async'),$legacyHash,[$event],['app'=>$source]))['job_id'];
    $runner=$app->container()->get(MigrationRunner::class);check($runner->migrate()===['0014_delivery_idempotency.sql','0015_authentication_sessions.sql','0016_saved_log_views.sql','0017_rbac_roles_permissions.sql','0018_identity_security.sql']&&$runner->migrate()===[],'real migration from 0.2.6 schema');
    check($pdo->query('SELECT delivery_family_id FROM api_tokens WHERE id='.$pdo->quote($next['id']))->fetchColumn()===$old['id'],'historic rotation lineage backfilled');
    check(expect($body,'legacy-sync',$old['token'],200)['event_ids']===$legacy,'historic receipt replay preserved');expect($body,'legacy-sync',$next['token'],409);
    check(expect($body,'legacy-async',$old['token'],200,true)['job_id']===$legacyJob,'historic async replay preserved');expect($body,'legacy-async',$next['token'],409,true);check(poll($legacyJob,$next['token'])->status===404,'historical job ownership not expanded');
    $first=expect($body,'new-sync',$old['token'],201);$count=(int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn();$accepted=$pdo->query('SELECT accepted_events_total FROM sources WHERE id='.$pdo->quote($source))->fetchColumn();
    $again=expect($body,'new-sync',$next['token'],200);check($again['event_ids']===$first['event_ids']&&$again['stored']===0,'lost response resolved by successor with original UUID');
    check((int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn()===$count&&$pdo->query('SELECT accepted_events_total FROM sources WHERE id='.$pdo->quote($source))->fetchColumn()===$accepted,'replay does not insert or charge event counters');
    expect($body.' ','new-sync',$next['token'],409);expect($body,'new-sync',$next['token'],409,true);
    $job=expect($body,'new-async',$old['token'],202,true)['job_id'];check(expect($body,'new-async',$next['token'],200,true)['job_id']===$job,'async replay same job across rotation');
    check(poll($job,$next['token'])->status===200,'successor polls new family job');expect($body,'new-async',$next['token'],409);
    $unrelated=$tokens->create('Independent',['logs:write'],$project,$source);check(poll($job,$unrelated['token'])->status===404,'independent credential cannot poll family job');expect($body,'new-sync',$unrelated['token'],201);
    $other=(new ProjectRepository($pdo))->create('Other','other');$otherSource=(new SourceRepository($pdo))->create($other,'App','app');$foreign=$tokens->create('Foreign',['logs:write'],$other,$otherSource);check(poll($job,$foreign['token'])->status===404,'other project cannot poll job');
    $tokens->revoke($old['id']);expect($body,'new-sync',$old['token'],401);check($q->process($q->claim())==='completed'&&$q->process($q->claim())==='completed','accepted jobs complete after original token revoke');
    check(data(poll($job,$next['token']))['status']==='completed','new owner sees committed job result');
    $third=$tx->run(fn()=>$tokens->rotate($project,$next['id'],'1',3600,600,$actor,null,$master));
    check(expect($body,'new-sync',$third['token'],200)['event_ids']===$first['event_ids'],'second rotation preserves root namespace');
    // Two independent PHP/PG sessions contend for the same key under different credentials.
    $program=<<<'WORKER'
$app=require 'core/bootstrap.php';$r=$app->handle(new \Cajeer\Logs\Http\Request('POST','/api/v1/ingest',['Authorization'=>'Bearer '.getenv('CL_DELIVERY_TOKEN'),'Content-Type'=>'application/json','Idempotency-Key'=>'race'],[],getenv('CL_DELIVERY_BODY'),'127.0.0.1'));echo json_encode(['status'=>$r->status,'body'=>json_decode($r->body,true)]);
WORKER;
    $before=(int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn();$children=[];$pdo->beginTransaction();
    try{
        $pdo->query('SELECT id FROM projects WHERE id='.$pdo->quote($project).' FOR UPDATE')->closeCursor();
        foreach([$next['token'],$third['token']] as $secret){$env=array_replace(getenv(),['APP_ENV'=>'test','APP_URL'=>'http://localhost:8080','DB_SCHEMA'=>$schema,'SECURITY_TOTP_KEY'=>$master,'INGEST_REQUIRE_HMAC'=>'false','API_RATE_LIMIT_PER_MINUTE'=>'600','CL_DELIVERY_TOKEN'=>$secret,'CL_DELIVERY_BODY'=>$body]);$child=proc_open([PHP_BINARY,'-r',$program],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root,$env);if(!is_resource($child))throw new RuntimeException('Unable to start delivery race');fclose($pipes[0]);$children[]=[$child,$pipes];}
        usleep(250000);$pdo->commit();$results=[];foreach($children as [$child,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($child)!==0)throw new RuntimeException('Delivery race failed '.$err);$results[]=json_decode($out,true,32,JSON_THROW_ON_ERROR);}$children=[];
        $statuses=array_column($results,'status');sort($statuses);check($statuses===[200,201]&&$results[0]['body']['event_ids']===$results[1]['body']['event_ids']&&(int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn()===$before+1,'concurrent old/new credentials commit one operation');
    }finally{if($pdo->inTransaction())$pdo->rollBack();foreach($children as [$child,$pipes]){if(is_resource($child))proc_terminate($child);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);if(is_resource($child))proc_close($child);}}
    // The accepted retention is immutable even if operator changes defaults.
    $app->config()->set('ingest.idempotency_seconds',86400);$pdo->prepare("UPDATE jobs SET completed_at=clock_timestamp()-interval '36 hours' WHERE id=:id")->execute(['id'=>$job]);check($q->cleanup()===0&&poll($job,$third['token'])->status===200,'original two-day job guarantee survives changed default');
    $pdo->prepare("UPDATE jobs SET completed_at=clock_timestamp()-interval '49 hours' WHERE id=:id")->execute(['id'=>$job]);check($q->cleanup()===1&&poll($job,$third['token'])->status===404,'terminal job purged only after original retention');expect($body,'new-async',$third['token'],202,true);
    $pdo->prepare("UPDATE ingest_receipts SET expires_at=clock_timestamp()-interval '1 second' WHERE token_id=:token AND key_hash=:key")->execute(['token'=>$old['id'],'key'=>hash('sha256','new-sync')]);
    $replacement=expect($body,'new-sync',$third['token'],201);check($replacement['event_ids']!==$first['event_ids'],'expired sync key may be reused only after expiry');
    $report=(new IntegrityInspector($pdo))->inspect();check($report['status']==='ok'&&isset($report['checks']['delivery_family'],$report['checks']['delivery_key_overlap'],$report['checks']['delivery_job_count']),'delivery integrity checks use real data');
    echo "Delivery PostgreSQL: $n checks passed\n";
}finally{if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();$db->exec('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');foreach(glob($tmp.'/*')?:[] as $f)unlink($f);rmdir($tmp);}
