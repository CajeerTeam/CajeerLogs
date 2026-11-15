<?php
declare(strict_types=1);
use Cajeer\Logs\Database\MigrationRunner;
use Cajeer\Logs\Database\Repository\UserRepository;
use Cajeer\Logs\Http\{Request,Response};
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Security\SignedPayload;
if(getenv('CAJEER_TEST_DATABASE')!=='1'){fwrite(STDERR,"Set CAJEER_TEST_DATABASE=1 for an isolated PostgreSQL database.\n");exit(1);}
$root=dirname(__DIR__);$base=require $root.'/core/bootstrap.php';$base->boot();$db=$base->container()->get(PDO::class);
$schema='cl_hmac_'.bin2hex(random_bytes(8));$db->exec('CREATE SCHEMA "'.$schema.'"');$n=0;
function check(bool $ok,string $name):void{global $n;if(!$ok)throw new RuntimeException('FAIL '.$name);++$n;echo "PASS $name\n";}
function data(Response $r):array{return json_decode($r->body,true,64,JSON_THROW_ON_ERROR);}
function call(string $method,string $path,?array $body=null,array $h=[]):Response{global $app;return $app->handle(new Request($method,'/api/v1'.$path,$h+['Content-Type'=>'application/json'],[],$body===null?'':json_encode($body,JSON_THROW_ON_ERROR),'127.0.0.1'));}
function ok(string $method,string $path,?array $body,array $h,int $code=200):array{$r=call($method,$path,$body,$h);check($r->status===$code,$method.' '.$path.' '.$r->status);return data($r);}
function headers(string $body,string $bearer,?array $key,string $idempotency,string $path='/api/v1/ingest',?string $nonce=null):array{
    $ts=(string)time();$nonce??=bin2hex(random_bytes(16));$r=new Request('POST',$path,[],[],$body);$signer=new SignedPayload();
    $h=['Authorization'=>'Bearer '.$bearer,'Content-Type'=>'application/json','Idempotency-Key'=>$idempotency,'X-Log-Timestamp'=>$ts,'X-Log-Nonce'=>$nonce];
    if($key!==null)$h['X-Log-Key-Id']=$key['current_key_id'];
    $h['X-Log-Signature']=$signer->sign($key===null?SignedPayload::canonical($r,$ts,$nonce,$idempotency):SignedPayload::canonicalV2($r,$ts,$nonce,$idempotency,$key['current_key_id']),$key===null?$bearer:$key['signing_secret']);return $h;
}
function ingest(string $body,array $h,int $code,string $label,string $path='/api/v1/ingest'):Response{global $app;$r=$app->handle(new Request('POST',$path,$h,[],$body,'127.0.0.1'));check($r->status===$code,$label.' HTTP '.$r->status);return $r;}
try{
    $master=bin2hex(random_bytes(32));$app=new Application($root);$app->boot();$app->config()->set('database.schema',$schema);$app->config()->set('app.env','test');$app->config()->set('app.url','http://localhost:8080');$app->config()->set('security.totp_key',$master);$app->config()->set('security.require_admin_totp',false);$app->config()->set('api.rate_limit',600);$app->config()->set('ingest.require_hmac',false);
    $pdo=$app->container()->get(PDO::class);$app->container()->get(MigrationRunner::class)->migrate();
    (new UserRepository($pdo))->create('hmac@example.test','test-hmac-password-1234','administrator');
    $login=call('POST','/auth/login',['email'=>'hmac@example.test','password'=>'test-hmac-password-1234']);check($login->status===200,'administrator login');$admin=['Cookie'=>explode(';',$login->header('Set-Cookie'),2)[0],'X-CSRF-Token'=>data($login)['csrf_token']];
    $project=ok('POST','/projects',['name'=>'HMAC','slug'=>'hmac'],$admin,201)['id'];$path='/projects/'.$project;$source=ok('POST',$path.'/sources',['name'=>'App','slug'=>'app'],$admin,201)['id'];
    $t=ok('POST',$path.'/tokens',['name'=>'Writer','scopes'=>['logs:write']],$admin,201);$bearer=$t['token'];$tp=$path.'/tokens/'.$t['id'];
    $body=json_encode(['timestamp'=>gmdate('Y-m-d\TH:i:s\Z'),'source'=>'app','level'=>'info','message'=>'HMAC test'],JSON_THROW_ON_ERROR);
    $unsigned=['Authorization'=>'Bearer '.$bearer,'Content-Type'=>'application/json'];
    ingest($body,$unsigned,201,'legacy optional');
    ok('PUT',$path.'/signing',['required'=>true],$admin);ingest($body,$unsigned,401,'project requires signing');
    $v1=headers($body,$bearer,null,'legacy-signed');ingest($body,$v1,201,'legacy v1 signed');
    ok('PUT',$path.'/signing',['required'=>false],$admin);ok('POST',$tp.'/signing',['mode'=>'bearer'],$admin);ingest($body,$unsigned,401,'credential requires signing independent of project');
    $a=ok('POST',$tp.'/signing',['mode'=>'separate'],$admin);check(isset($a['signing_secret'])&&str_starts_with($a['signing_secret'],'sk_'),'new secret shown once');
    $meta=ok('GET',$tp.'/signing',null,$admin);check(!isset($meta['signing_secret'])&&!str_contains(json_encode($meta),$a['signing_secret']),'metadata excludes secret');
    $cipher=$pdo->query('SELECT current_ciphertext FROM ingest_signing WHERE token_id='.$pdo->quote($t['id']))->fetchColumn();check(!str_contains($cipher,$a['signing_secret']),'ciphertext not plaintext');
    ingest($body,headers($body,$bearer,null,'downgrade'),401,'no legacy fallback');
    $h=headers($body,$bearer,$a,'v2-one');$first=data(ingest($body,$h,201,'separate signature accepted'));ingest($body,$h,409,'nonce replay rejected');
    $retry=data(ingest($body,headers($body,$bearer,$a,'v2-one'),200,'fresh nonce idempotent retry'));check($retry['event_ids']===$first['event_ids'],'same event UUID');
    $b=ok('POST',$tp.'/signing',['mode'=>'separate','overlap_seconds'=>3600,'expected_key_id'=>$a['current_key_id']],$admin);check($b['previous_key_id']===$a['current_key_id'],'rotation retains previous key');
    check(call('POST',$tp.'/signing',['mode'=>'separate','expected_key_id'=>$a['current_key_id']],$admin)->status===409,'stale key rotation rejected');
    ingest($body,headers($body,$bearer,$a,'overlap-old'),201,'old key overlap');ingest($body,headers($body,$bearer,$b,'overlap-new'),201,'new key overlap');
    $nonce=bin2hex(random_bytes(16));ingest($body,headers($body,$bearer,$a,'same-nonce-old','/api/v1/ingest',$nonce),201,'nonce used by previous key');ingest($body,headers($body,$bearer,$b,'same-nonce-new','/api/v1/ingest',$nonce),409,'nonce shared across key IDs');
    $retire=['expected_key_id'=>$b['current_key_id'],'expected_previous_key_id'=>$a['current_key_id']];
    check(call('DELETE',$tp.'/signing/previous',['expected_key_id'=>$a['current_key_id'],'expected_previous_key_id'=>$a['current_key_id']],$admin)->status===409,'stale retirement rejected');
    check(call('DELETE',$tp.'/signing/previous',$retire,['Cookie'=>$admin['Cookie']])->status===403,'retirement requires CSRF');
    $retired=ok('DELETE',$tp.'/signing/previous',$retire,$admin);check($retired['current_key_id']===$b['current_key_id']&&$retired['previous_key_id']===null&&$retired['previous_until']===null,'old key retired without changing current');
    ingest($body,headers($body,$bearer,$a,'retired-old'),401,'retired key no longer accepted');ingest($body,headers($body,$bearer,$b,'retired-new'),201,'current key still works');
    $c=ok('POST',$tp.'/signing',['mode'=>'separate','overlap_seconds'=>3600,'expected_key_id'=>$b['current_key_id']],$admin);
    check(call('DELETE',$tp.'/signing/previous',$retire,$admin)->status===409,'old retirement cannot clear new overlap');
    $pdo->exec('UPDATE ingest_signing SET previous_until=clock_timestamp()-interval \'1 second\' WHERE token_id='.$pdo->quote($t['id']));ingest($body,headers($body,$bearer,$b,'expired-old'),401,'expired overlap rejected');$b=$c;

    $async='/api/v1/ingest/async';$job=data(ingest($body,headers($body,$bearer,$b,'signed-job',$async),202,'v2 async accepted',$async));
    $h=headers($body,$bearer,$b,'race-same-nonce');$before=(int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn();
    $program=<<<'WORKER'
$app=require 'core/bootstrap.php';$r=$app->handle(new \Cajeer\Logs\Http\Request('POST','/api/v1/ingest',json_decode(getenv('CL_HMAC_HEADERS'),true,32,JSON_THROW_ON_ERROR),[],getenv('CL_HMAC_BODY')));echo json_encode(['status'=>$r->status]);
WORKER;
    $children=[];$pdo->beginTransaction();
    try{
        $pdo->query('SELECT id FROM api_tokens WHERE id='.$pdo->quote($t['id']).' FOR UPDATE')->closeCursor();
        for($i=0;$i<2;$i++){$env=array_replace(getenv(),['APP_ENV'=>'test','APP_URL'=>'http://localhost:8080','DB_SCHEMA'=>$schema,'SECURITY_TOTP_KEY'=>$master,'INGEST_REQUIRE_HMAC'=>'false','API_RATE_LIMIT_PER_MINUTE'=>'600','CL_HMAC_HEADERS'=>json_encode($h),'CL_HMAC_BODY'=>$body]);$child=proc_open([PHP_BINARY,'-r',$program],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root,$env);if(!is_resource($child))throw new RuntimeException('Cannot start replay test');fclose($pipes[0]);$children[]=[$child,$pipes];}
        usleep(250000);$pdo->commit();$statuses=[];foreach($children as [$child,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($child)!==0)throw new RuntimeException('Replay worker failed '.$err);$statuses[]=json_decode($out,true,32,JSON_THROW_ON_ERROR)['status'];}$children=[];sort($statuses);check($statuses===[201,409]&&(int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn()===$before+1,'concurrent replay commits exactly once');
    }finally{if($pdo->inTransaction())$pdo->rollBack();foreach($children as [$child,$pipes]){if(is_resource($child))proc_terminate($child);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);if(is_resource($child))proc_close($child);}}
    $next=ok('POST',$tp.'/rotate',['revision'=>$t['revision'],'expires_in'=>3600,'overlap_seconds'=>0],$admin,201);check(isset($next['signing']['signing_secret'])&&$next['signing']['current_key_id']!==$b['current_key_id'],'bearer rotation inherits separate mode with new signing secret');
    ingest($body,headers($body,$next['token'],$b,'foreign-key'),401,'old signing key not valid for successor');ingest($body,headers($body,$next['token'],$next['signing'],'successor'),201,'successor signs with its own key');
    $q=$app->container()->get(\Cajeer\Logs\Queue\PostgresQueueDriver::class);check($q->process($q->claim())==='completed','already accepted signed job completes after credential revoke');
    $qrow=$q->status($job['job_id'],$t['id'],$project);check($qrow['status']==='completed','signed job durable result');
    $wrong=ok('POST',$path.'/tokens',['name'=>'Separate at issue','scopes'=>['logs:write'],'signing_mode'=>'separate'],$admin,201);check(isset($wrong['signing']['signing_secret']),'atomic issue with separate key');
    ingest($body,headers($body,$wrong['token'],$next['signing'],'cross-token-key'),401,'cross-token signing key rejected');
    check($app->handle(new Request('GET','/ready'))->status===200,'readiness verifies encryption key');$app->config()->set('security.totp_key',bin2hex(random_bytes(32)));check($app->handle(new Request('GET','/ready'))->status===503,'wrong encryption key blocks readiness');
    echo "HMAC PostgreSQL: $n checks passed\n";
}finally{$db->exec('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');}
