<?php
declare(strict_types=1);

use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Database\ConnectionFactory;
use Cajeer\Logs\Database\MigrationRunner;
use Cajeer\Logs\Database\Repository\UserRepository;
use Cajeer\Logs\Http\{Request,Response};
use Cajeer\Logs\Security\RateLimiter;
use Cajeer\Logs\Console\Command\AuthCleanupCommand;

if (getenv('CAJEER_TEST_DATABASE')!=='1') { fwrite(STDERR,"Set CAJEER_TEST_DATABASE=1 and DB_* for an isolated PostgreSQL test database.\n"); exit(1); }
$root=dirname(__DIR__); $app=require $root.'/core/bootstrap.php'; $app->boot();
$base=$app->container()->get(PDO::class); $schema='cl_identity_'.bin2hex(random_bytes(8));
$base->exec('CREATE SCHEMA "'.$schema.'"');
$tmp=sys_get_temp_dir().'/'.$schema; mkdir($tmp,0700);
$count=0;
function verify(bool $ok,string $name):void { global $count; if (!$ok) { throw new RuntimeException('FAIL '.$name); } ++$count; echo "PASS $name\n"; }
function callApi(string $method,string $path,?array $data=null,array $headers=[],array $query=[],string $ip='127.0.0.1'):Response {
    global $app;
    if ($data!==null) { $headers['Content-Type']='application/json'; }
    return $app->handle(new Request($method,'/api/v1'.$path,$headers,$query,$data===null?'':json_encode((object)$data,JSON_THROW_ON_ERROR),$ip));
}
function data(Response $r):array { return json_decode($r->body,true,64,JSON_THROW_ON_ERROR); }
function ok(string $method,string $path,?array $body=null,array $headers=[],int $status=200):Response {
    $r=callApi($method,$path,$body,$headers); verify($r->status===$status,$method.' '.$path.' => '.$status.' (got '.$r->status.')'); return $r;
}
function login(string $email,string $password,array $headers=[]):array {
    $r=ok('POST','/auth/login',['email'=>$email,'password'=>$password],$headers);
    $cookie=$r->header('Set-Cookie'); $d=data($r);
    verify(str_contains($cookie,'HttpOnly')&&str_contains($cookie,'SameSite=Strict')&&!isset($d['user']['password_hash'])&&strlen($d['csrf_token'])===64,'login returns secure session and public identity');
    return ['Cookie'=>explode(';',$cookie,2)[0],'X-CSRF-Token'=>$d['csrf_token']];
}
try {
    $app=new Application($root); $app->boot(); $app->config()->set('database.schema',$schema); $app->config()->set('app.env','test'); $app->config()->set('app.url','http://localhost:8080');
    $pdo=$app->container()->get(PDO::class);
    // Exercise a real 0.0.2 -> 0.0.3 upgrade, not just fresh schema installation.
    foreach (['0001_initial_schema.sql','0002_projects_sources.sql'] as $file) { copy($root.'/migrations/pgsql/'.$file,$tmp.'/'.$file); }
    (new MigrationRunner($pdo,$tmp,$schema))->migrate();
    $stmt=$pdo->prepare("INSERT INTO users(email,password_hash,role) VALUES ('admin@example.test',:hash,'administrator')"); $stmt->execute(['hash'=>password_hash('admin-password-12345',PASSWORD_ARGON2ID)]);
    $adminId=$pdo->query('SELECT id FROM users')->fetchColumn();
    $runner=$app->container()->get(MigrationRunner::class);
    verify($runner->migrate()===['0003_identity_access.sql','0004_log_ingestion.sql','0005_log_search.sql','0006_sdk_integrations.sql','0007_queue_lifecycle.sql','0008_security_operations.sql','0009_project_management.sql','0010_source_management.sql','0011_token_management.sql','0012_database_integrity.sql','0013_ingest_signing.sql']&&$runner->migrate()===[],'0.0.2 upgrade to current schema and idempotent migration');
    verify($pdo->query('SELECT id FROM users')->fetchColumn()===$adminId,'upgrade preserves administrator');
    verify($app->handle(new Request('GET','/ready'))->status===200,'identity schema readiness');
    ok('POST','/auth/login',['email'=>'admin@example.test','password'=>'wrong'],[],401);
    ok('POST','/auth/login',['email'=>'admin@example.test','password'=>'admin-password-12345'],['Origin'=>'https://foreign.example'],403);
    $admin=login('admin@example.test','admin-password-12345');
    $admin2=login('admin@example.test','admin-password-12345',$admin);
    verify($admin['Cookie']!==$admin2['Cookie'],'session rotation on login');
    ok('GET','/auth/me',null,$admin,401); $admin=$admin2;
    $me=data(ok('GET','/auth/me',null,$admin)); verify($me['user']['id']===$adminId,'session principal persisted');
    $secret=explode('=',$admin['Cookie'],2)[1];
    verify($pdo->query('SELECT token_hash FROM auth_sessions')->fetchColumn()===hash('sha256',$secret),'session secret stored only as digest');
    ok('POST','/projects',['name'=>'First','slug'=>'first'],['Cookie'=>$admin['Cookie']],403);
    ok('POST','/projects',['name'=>'First','slug'=>'first'],$admin+['Origin'=>'https://foreign.example'],403);
    $p1=data(ok('POST','/projects',['name'=>'First','slug'=>'first'],$admin,201))['id'];
    $p2=data(ok('POST','/projects',['name'=>'Second','slug'=>'second'],$admin,201))['id'];
    ok('POST','/projects',['name'=>'Duplicate','slug'=>'first'],$admin,409);
    $op=data(ok('POST','/users',['email'=>'operator@example.test','password'=>'operator-password-123','role'=>'operator'],$admin,201))['id'];
    $viewer=data(ok('POST','/users',['email'=>'viewer@example.test','password'=>'viewer-password-123','role'=>'viewer'],$admin,201))['id'];
    ok('PATCH','/users/'.$adminId,['blocked'=>true],$admin,409);
    ok('PATCH','/users/'.$adminId,['role'=>'viewer'],$admin,409);
    ok('PUT','/projects/'.$p1.'/members/'.$op,null,$admin,204);
    ok('PUT','/projects/'.$p1.'/members/'.$viewer,null,$admin,204);
    verify(count(data(ok('GET','/projects/'.$p1.'/members',null,$admin))['items'])===2,'membership listing');
    $operator=login('operator@example.test','operator-password-123'); $view=login('viewer@example.test','viewer-password-123');
    verify(count(data(ok('GET','/projects',null,$operator))['items'])===1,'project list scoped');
    $page=data(callApi('GET','/projects',null,$admin,['limit'=>'1']));
    verify(count($page['items'])===1&&$page['next_cursor']!==null,'project cursor pagination first page');
    $next=data(callApi('GET','/projects',null,$admin,['limit'=>'1','cursor'=>$page['next_cursor']]));
    verify(count($next['items'])===1&&$next['items'][0]['id']!==$page['items'][0]['id']&&$next['next_cursor']===null,'project cursor pagination second page');
    ok('GET','/projects/'.$p2,null,$operator,403); ok('GET','/users',null,$view,403);
    ok('POST','/projects/'.$p1.'/sources',['name'=>'App','slug'=>'app'],$view,403);
    $src=data(ok('POST','/projects/'.$p1.'/sources',['name'=>'App','slug'=>'app'],$operator,201))['id'];
    $src2=data(ok('POST','/projects/'.$p1.'/sources',['name'=>'Other','slug'=>'other'],$operator,201))['id'];
    $foreign=data(ok('POST','/projects/'.$p2.'/sources',['name'=>'Foreign','slug'=>'foreign'],$admin,201))['id'];
    ok('GET','/projects/'.$p1.'/sources/'.$src,null,$view);
    ok('POST','/projects/'.$p1.'/tokens',['name'=>'cross','scopes'=>['logs:read'],'source_id'=>$foreign],$operator,404);
    ok('POST','/projects/'.$p1.'/tokens',['name'=>'system','scopes'=>['system:read']],$operator,422);
    $t=data(ok('POST','/projects/'.$p1.'/tokens',['name'=>'reader','scopes'=>['logs:read'],'source_id'=>$src],$operator,201));
    $bearer=['Authorization'=>'Bearer '.$t['token']];
    $meta=data(ok('GET','/auth/token',null,$bearer)); verify($meta['token']['id']===$t['id']&&!isset($meta['token']['token_hash']),'token introspection without secret');
    ok('GET','/projects/'.$p1.'/sources/'.$src,null,$bearer);
    ok('GET','/projects/'.$p1.'/sources/'.$src2,null,$bearer,403);
    ok('GET','/projects/'.$p2.'/sources/'.$foreign,null,$bearer,403);
    ok('GET','/projects/'.$p1.'/sources',null,$bearer,403);
    ok('GET','/system/report',null,$bearer,403);
    ok('POST','/projects/'.$p1.'/sources',['name'=>'Denied','slug'=>'denied'],$bearer,403);
    $write=data(ok('POST','/projects/'.$p1.'/tokens',['name'=>'writer','scopes'=>['logs:write']],$operator,201));
    ok('GET','/auth/token',null,['Authorization'=>'Bearer '.$write['token']]);
    ok('GET','/projects/'.$p1.'/sources',null,['Authorization'=>'Bearer '.$write['token']],403);
    $list=data(ok('GET','/projects/'.$p1.'/tokens',null,$operator));
    verify(count($list['items'])===2&&!str_contains(json_encode($list),$t['token'])&&is_array($list['items'][0]['scopes']),'token list excludes secrets and returns arrays');
    $stmt=$pdo->prepare('SELECT token_hash,last_used_at FROM api_tokens WHERE id=:id'); $stmt->execute(['id'=>$t['id']]); $row=$stmt->fetch();
    verify($row['token_hash']===hash('sha256',$t['token'])&&$row['last_used_at']!==null,'hashed token and last use recorded');
    ok('DELETE','/projects/'.$p2.'/tokens/'.$t['id'],null,$admin,404);
    ok('DELETE','/projects/'.$p1.'/tokens/'.$t['id'],null,$operator,204);
    ok('GET','/auth/token',null,$bearer,401);
    $stmt=$pdo->prepare("UPDATE api_tokens SET expires_at=now()-interval '1 second' WHERE id=:id"); $stmt->execute(['id'=>$write['id']]);
    ok('GET','/auth/token',null,['Authorization'=>'Bearer '.$write['token']],401);
    $system=data(ok('POST','/system/tokens',['name'=>'monitor','scopes'=>['system:read']],$admin,201));
    ok('GET','/system/report',null,['Authorization'=>'Bearer '.$system['token']]);
    ok('GET','/users',null,['Authorization'=>'Bearer '.$system['token']],403);
    ok('DELETE','/system/tokens/'.$system['id'],null,$admin,204);
    ok('DELETE','/projects/'.$p1.'/members/'.$viewer,null,$admin,204);
    ok('GET','/projects/'.$p1,null,$view,403);
    ok('PATCH','/users/'.$op,['blocked'=>true],$admin);
    ok('GET','/auth/me',null,$operator,401);
    ok('POST','/auth/login',['email'=>'operator@example.test','password'=>'operator-password-123'],[],401);
    ok('PATCH','/users/'.$op,['blocked'=>false],$admin);
    $operator=login('operator@example.test','operator-password-123');
    ok('POST','/auth/password',['current_password'=>'operator-password-123','password'=>'new-operator-password-123'],$operator,204);
    ok('GET','/auth/me',null,$operator,401);
    $operator=login('operator@example.test','new-operator-password-123');
    ok('POST','/auth/logout-all',null,$operator,204); ok('GET','/auth/me',null,$operator,401);
    $view=login('viewer@example.test','viewer-password-123');
    $stmt=$pdo->prepare("UPDATE auth_sessions SET expires_at=now()-interval '1 second' WHERE token_hash=:hash"); $stmt->execute(['hash'=>hash('sha256',explode('=',$view['Cookie'],2)[1])]);
    ok('GET','/auth/me',null,$view,401);
    $rate=new RateLimiter($pdo); verify($rate->hit('test-rate',2,60)&&$rate->hit('test-rate',2,60)&&!$rate->hit('test-rate',2,60),'atomic rate limit blocks excess');
    for ($i=0;$i<10;$i++) { $rate->hit('login-email:throttled@example.test',10,300); }
    $limited=callApi('POST','/auth/login',['email'=>'throttled@example.test','password'=>'wrong'],[],[],'192.0.2.52');
    verify($limited->status===429&&$limited->header('Retry-After')==='300','login rate limit through runtime');
    $pdo->exec("INSERT INTO auth_rate_limits(bucket_key,window_start,hits) VALUES (repeat('a',64),1,1)");
    verify((new AuthCleanupCommand($app))->handle()===0 && !$pdo->query("SELECT EXISTS(SELECT 1 FROM auth_rate_limits WHERE window_start=1)")->fetchColumn() && !$pdo->query("SELECT EXISTS(SELECT 1 FROM auth_sessions WHERE expires_at<=now())")->fetchColumn(),'expired session and rate bucket cleanup');
    verify((int)$pdo->query("SELECT count(*) FROM audit_log WHERE action='token.created'")->fetchColumn()===3,'audit writes persisted');
    verify(!str_contains((string)$pdo->query("SELECT string_agg(context::text,' ') FROM audit_log")->fetchColumn(),$t['token']),'audit contains no token secret');
    ok('POST','/auth/logout',null,$admin,204); ok('GET','/auth/me',null,$admin,401);
    // Real network smoke test across PHP request lifecycles, using the same isolated database.
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);
    if ($socket===false) { throw new RuntimeException('Cannot allocate HTTP test port.'); }
    $address=stream_socket_get_name($socket,false); fclose($socket);
    $log=$tmp.'/http.log';
    $process=proc_open([PHP_BINARY,'-S',$address,'-t','public','public/router.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$root,array_replace(getenv(),['APP_ENV'=>'test','APP_DEBUG'=>'false','APP_URL'=>'http://'.$address,'DB_SCHEMA'=>$schema]));
    if (!is_resource($process)) { throw new RuntimeException('Cannot start HTTP test server.'); }
    fclose($pipes[0]);
    try {
        $ready=false;
        for ($i=0;$i<100;$i++) { $probe=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.1); if ($probe!==false) { fclose($probe); $ready=true; break; } usleep(50000); }
        verify($ready,'network HTTP server running');
        $context=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\n",'content'=>json_encode(['email'=>'admin@example.test','password'=>'admin-password-12345']),'ignore_errors'=>true,'timeout'=>10]]);
        $result=file_get_contents('http://'.$address.'/api/v1/auth/login',false,$context);
        $headers=$http_response_header??[];
        $cookie=''; foreach($headers as $header) { if (str_starts_with(strtolower($header),'set-cookie:')) { $cookie=explode(';',trim(substr($header,11)),2)[0]; } }
        verify($result!==false&&str_contains($headers[0]??'',' 200 ')&&str_starts_with($cookie,'cajeerlogs_session='),'real HTTP login returns cookie');
        $context=stream_context_create(['http'=>['header'=>'Cookie: '.$cookie."\r\n",'ignore_errors'=>true,'timeout'=>10]]);
        $result=file_get_contents('http://'.$address.'/api/v1/auth/me',false,$context);
        verify($result!==false&&str_contains($http_response_header[0]??'',' 200 ')&&json_decode($result,true)['user']['id']===$adminId,'real HTTP second request resolves persisted session');
    } finally { proc_terminate($process); proc_close($process); if (is_file($log)) { unlink($log); } }
    echo "Identity PostgreSQL: $count checks passed\n";
} finally {
    if (isset($pdo)&&$pdo->inTransaction()) { $pdo->rollBack(); }
    $base->exec('DROP SCHEMA "'.$schema.'" CASCADE');
    foreach(glob($tmp.'/*.sql') as $f) { unlink($f); } rmdir($tmp);
}
