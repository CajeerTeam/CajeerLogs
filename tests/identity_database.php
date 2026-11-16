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
    foreach (glob($root.'/migrations/pgsql/*.sql') as $file) { if (!in_array(basename($file),['0015_authentication_sessions.sql','0016_saved_log_views.sql','0017_rbac_roles_permissions.sql','0018_identity_security.sql','0019_audit_accountability.sql','0020_rate_limit_buckets.sql','0021_retention_execution_history.sql'],true)) copy($file,$tmp.'/'.basename($file)); }
    (new MigrationRunner($pdo,$tmp,$schema))->migrate();
    $legacySecret=bin2hex(random_bytes(32)); $legacyCsrf=bin2hex(random_bytes(32));
    $stmt=$pdo->prepare("INSERT INTO auth_sessions(token_hash,user_id,csrf_token,expires_at) VALUES (:hash,:user,:csrf,now()+interval '1 hour')");
    $stmt->execute(['hash'=>hash('sha256',$legacySecret),'user'=>$adminId,'csrf'=>$legacyCsrf]);
    $runner=$app->container()->get(MigrationRunner::class);
    verify($runner->migrate()===['0015_authentication_sessions.sql','0016_saved_log_views.sql','0017_rbac_roles_permissions.sql','0018_identity_security.sql','0019_audit_accountability.sql','0020_rate_limit_buckets.sql','0021_retention_execution_history.sql']&&$runner->migrate()===[],'0.3.1 session migration and idempotent replay');
    $legacy=['Cookie'=>'cajeerlogs_session='.$legacySecret,'X-CSRF-Token'=>$legacyCsrf];
    $legacyPage=data(ok('GET','/auth/sessions',null,$legacy));
    verify(count($legacyPage['items'])===1&&$legacyPage['items'][0]['current']===true&&$legacyPage['items'][0]['user_agent']===null&&!str_contains(json_encode($legacyPage),$legacySecret)&&!str_contains(json_encode($legacyPage),$legacyCsrf),'legacy session survives, public UUID, no credentials');
    ok('POST','/auth/logout',null,$legacy,204);
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
    // User directory and effective access are real PostgreSQL queries, not frontend filtering.
    $dir=data(callApi('GET','/users',null,$admin,['q'=>'operator@','role'=>'operator','status'=>'active','two_factor'=>'disabled']));
    verify(count($dir['items'])===1&&$dir['items'][0]['id']===$op&&isset($dir['items'][0]['display_name']),'directory filters and public metadata');
    verify(callApi('GET','/users',null,$admin,['role'=>'invalid'])->status===422,'invalid directory role rejected');
    $sorted=data(callApi('GET','/users',null,$admin,['sort'=>'email_asc','limit'=>'1'])); verify($sorted['items'][0]['email']==='admin@example.test'&&$sorted['next_cursor']!==null,'user email sort first page');
    $sortedNext=data(callApi('GET','/users',null,$admin,['sort'=>'email_asc','limit'=>'1','cursor'=>$sorted['next_cursor']])); verify($sortedNext['items'][0]['id']===$op,'user email keyset next page');
    verify(callApi('GET','/users',null,$admin,['sort'=>'invalid'])->status===422,'invalid user sort rejected');
    $detail=data(ok('GET','/users/'.$op,null,$admin))['user']; verify($detail['id']===$op&&!isset($detail['password_hash']),'admin user detail excludes credentials');
    ok('GET','/users/'.$op,null,$operator,403); ok('GET','/users/'.$op.'/projects',null,$view,403); ok('GET','/users/'.$op.'/audit',null,$view,403);
    $access=data(ok('GET','/users/'.$op.'/projects',null,$admin))['items']; verify(count($access)===1&&$access[0]['member']===true&&$access[0]['effective_role']==='operator','explicit operator access');
    $adminAccess=data(ok('GET','/users/'.$adminId.'/projects',null,$admin))['items']; verify(count($adminAccess)===2&&$adminAccess[0]['effective_role']==='administrator','implicit administrator access');
    ok('PUT','/projects/'.$p1.'/members/'.$op,['role'=>'viewer'],$admin,204);
    verify(data(ok('GET','/users/'.$op.'/projects',null,$admin))['items'][0]['effective_role']==='viewer','project role narrows global operator');
    ok('POST','/projects/'.$p1.'/sources',['name'=>'Denied','slug'=>'denied'],$operator,403);
    ok('PUT','/projects/'.$p1.'/members/'.$op,['role'=>null],$admin,204);
    ok('PUT','/projects/'.$p1.'/members/'.$viewer,['role'=>'operator'],$admin,204);
    verify(data(ok('GET','/users/'.$viewer.'/projects',null,$admin))['items'][0]['effective_role']==='viewer','project role cannot elevate viewer');
    $audit=data(callApi('GET','/users/'.$op.'/audit',null,$admin,['limit'=>'1'])); verify(count($audit['items'])===1&&$audit['next_cursor']!==null&&!isset($audit['items'][0]['context']),'audit first page excludes arbitrary context');
    $auditNext=data(callApi('GET','/users/'.$op.'/audit',null,$admin,['limit'=>'1','cursor'=>$audit['next_cursor']])); verify($auditNext['items'][0]['id']!==$audit['items'][0]['id'],'audit chronological cursor');
    ok('PATCH','/users/'.$op,['role'=>'viewer','expected_updated_at'=>'stale'],$admin,409);
    ok('POST','/users/'.$op.'/2fa/reset',['password'=>'wrong'],$admin,403);
    ok('POST','/users/'.$op.'/2fa/reset',['password'=>'admin-password-12345'],$operator,403);
    ok('POST','/users/'.$adminId.'/2fa/reset',['password'=>'admin-password-12345'],$admin,422);
    // Reset another account (even without enrollment) must revoke its live sessions.
    $resetUser=data(ok('POST','/users',['email'=>'reset@example.test','password'=>'reset-password-12345','role'=>'viewer'],$admin,201))['id'];
    $resetSession=login('reset@example.test','reset-password-12345');
    ok('POST','/users/'.$resetUser.'/2fa/reset',['password'=>'admin-password-12345'],['Cookie'=>$admin['Cookie']],403);
    ok('POST','/users/'.$resetUser.'/2fa/reset',['password'=>'admin-password-12345'],$admin,204); ok('GET','/auth/me',null,$resetSession,401);
    verify(data(ok('GET','/users/'.$resetUser,null,$admin))['user']['two_factor_enabled']===false,'reset disables second factor');
    $pdo->exec('DELETE FROM auth_rate_limits'); $pdo->exec('DELETE FROM api_rate_buckets');
    $views='/projects/'.$p1.'/saved-views';
    $layout=['order'=>\Cajeer\Logs\Log\SavedView::COLUMNS,'visible'=>['timestamp','message'],'widths'=>(object)['message'=>500],'density'=>'compact','wrap'=>true,'zone'=>'utc'];
    $payload=['name'=>'Errors','filters'=>['levels'=>'["error","warning"]','limit'=>'25','consistency'=>'snapshot'],'layout'=>$layout];
    $saved=data(ok('POST',$views,$payload,$operator,201)); $vid=$saved['id'];
    verify($saved['revision']===1&&$saved['filters']['levels']==='["error","warning"]'&&$saved['layout']['widths']['message']===500,'saved filters and layout persist');
    verify(count(data(ok('GET',$views,null,$operator))['items'])===1&&data(ok('GET',$views,null,$view))['items']===[],'private saved view listing');
    ok('GET',$views.'/'.$vid,null,$admin,404); ok('GET',$views.'/'.$vid,null,$view,404);ok('GET','/projects/'.$p2.'/saved-views',null,$operator,403);
    ok('POST',$views,$payload,['Cookie'=>$operator['Cookie']],403);
    ok('POST',$views,$payload,$operator,409);
    ok('POST',$views,array_replace($payload,['filters'=>['cursor'=>'forbidden']]),$operator,422);
    ok('POST',$views,array_replace($payload,['filters'=>['context_path'=>'["a"]']]),$operator,422);
    $updated=data(ok('PATCH',$views.'/'.$vid,array_replace($payload,['name'=>'Updated','revision'=>1]),$operator));
    verify($updated['revision']===2&&data(ok('GET',$views.'/'.$vid,null,$operator))['name']==='Updated','view updates and revision persisted');
    ok('PATCH',$views.'/'.$vid,$payload+['revision'=>1],$operator,409);
    ok('DELETE',$views.'/'.$vid,null,$view,404); ok('DELETE',$views.'/'.$vid,null,$operator,204);ok('GET',$views.'/'.$vid,null,$operator,404);
    $device=login('operator@example.test','operator-password-123',['User-Agent'=>'Test Browser 123']);
    $own=data(ok('GET','/auth/sessions',null,$operator));
    verify(count($own['items'])===2&&count(array_filter($own['items'],fn($s)=>$s['current']))===1,'own sessions and unique current marker');
    $other=array_values(array_filter($own['items'],fn($s)=>!$s['current']))[0];
    verify($other['user_agent']==='Test Browser 123'&&$other['remote_address']==='127.0.0.1'&&!isset($other['token_hash'])&&!isset($other['csrf_token']),'session device metadata safe');
    $foreign=data(ok('GET','/auth/sessions',null,$view))['items'][0]['id'];
    ok('DELETE','/auth/sessions/'.$foreign,null,$operator,404);
    ok('GET','/users/'.$viewer.'/sessions',null,$operator,403);
    ok('DELETE','/auth/sessions/'.$other['id'],null,['Cookie'=>$operator['Cookie']],403);
    ok('DELETE','/auth/sessions/'.$other['id'],null,$operator,204);
    ok('GET','/auth/me',null,$device,401); ok('GET','/auth/me',null,$operator);
    $device=login('operator@example.test','operator-password-123');
    ok('POST','/auth/logout-others',null,$operator,204); ok('GET','/auth/me',null,$device,401);
    $profile=data(ok('PATCH','/auth/profile',['display_name'=>'Operator <test>'],$operator));
    verify($profile['user']['display_name']==='Operator <test>'&&$profile['user']['last_login_at']!==null,'profile persisted and login timestamp');
    verify(data(ok('GET','/auth/me',null,$operator))['user']['display_name']==='Operator <test>','profile survives request');
    ok('PATCH','/auth/profile',['role'=>'administrator'],$operator,422);
    ok('PATCH','/auth/profile',['display_name'=>str_repeat('x',121)],$operator,422);
    $device=login('operator@example.test','operator-password-123');
    $adminPage=data(ok('GET','/users/'.$op.'/sessions',null,$admin));
    verify(count($adminPage['items'])===2&&!array_filter($adminPage['items'],fn($s)=>$s['current']),'admin scoped list');
    $deviceId=data(ok('GET','/auth/sessions',null,$device))['items'];
    $deviceId=array_values(array_filter($deviceId,fn($s)=>$s['current']))[0]['id'];
    ok('DELETE','/users/'.$viewer.'/sessions/'.$deviceId,null,$admin,404);
    ok('DELETE','/users/'.$op.'/sessions/'.$deviceId,null,$admin,204); ok('GET','/auth/me',null,$device,401);
    $device=login('operator@example.test','operator-password-123');
    ok('POST','/users/'.$op.'/sessions/revoke-all',null,$admin,204); ok('GET','/auth/me',null,$operator,401); ok('GET','/auth/me',null,$device,401);
    // Reset the isolated rate bucket before the remaining existing scenarios.
    $pdo->exec('DELETE FROM auth_rate_limits'); $pdo->exec('DELETE FROM api_rate_buckets');
    $operator=login('operator@example.test','operator-password-123');

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
    verify($limited->status===429&&(int)$limited->header('Retry-After')>=1&&(int)$limited->header('Retry-After')<=300,'login rate limit through runtime');
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
