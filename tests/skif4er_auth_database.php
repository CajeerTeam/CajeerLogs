<?php
declare(strict_types=1);
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Database\MigrationRunner;
use Cajeer\Logs\Database\Repository\UserRepository;
use Cajeer\Logs\Http\Request;
use Cajeer\Logs\Security\Skif4erOidc;
if(getenv('CAJEER_TEST_DATABASE')!=='1'||getenv('APP_ENV')!=='test') throw new RuntimeException('Use a disposable PostgreSQL database.');
$root=dirname(__DIR__);$app=require $root.'/core/bootstrap.php';$app->boot();$base=$app->container()->get(PDO::class);$schema='cl_skif4er_'.bin2hex(random_bytes(8));$base->exec('CREATE SCHEMA "'.$schema.'"');$n=0;
function check(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
try {
 $app=new Application($root);$app->boot();$cfg=$app->config();$cfg->set('database.schema',$schema);$cfg->set('app.env','test');$cfg->set('app.url','https://logs.example.test');$cfg->set('security.require_admin_totp',false);$cfg->set('security.required_totp_roles','');$cfg->set('security.required_totp_permissions','');$cfg->set('security.reauth_seconds',300);$cfg->set('skif4er.enabled',true);$cfg->set('skif4er.client_id','fixture-client');$cfg->set('skif4er.client_secret','fixture-secret');
 $pdo=$app->container()->get(PDO::class);$app->container()->get(MigrationRunner::class)->migrate();$user=(new UserRepository($pdo))->create('viewer@skif4er.test','fixture-local-password-123','viewer');
 $pdo->prepare('INSERT INTO skif4er_identities(subject,user_id) VALUES (:subject,:user)')->execute(['subject'=>'verified-subject','user'=>$user]);
 // Inject only the already-validated callback's persisted completion state. No live provider credentials are required for local-session tests.
 $pending=function(int $seconds=300,string $subject='verified-subject')use($pdo,$user){$secret=Skif4erOidc::random();$pdo->prepare("INSERT INTO skif4er_login_flows(browser_hash,subject,user_id,expires_at) VALUES (:browser,:subject,:user,clock_timestamp()+CAST(:ttl AS integer)*interval '1 second')")->execute(['browser'=>hash('sha256',$secret),'subject'=>$subject,'user'=>$user,'ttl'=>$seconds]);return '__Host-cajeerlogs_oidc='.$secret;};
 $finish=fn(string $cookie,string $origin='https://logs.example.test',array $body=[])=>$app->handle(new Request('POST','/api/v1/auth/skif4er/finish',['Cookie'=>$cookie,'Origin'=>$origin,'Content-Type'=>'application/json'],[],json_encode((object)$body,JSON_THROW_ON_ERROR),'192.0.2.1',true));
 $cookie=$pending();check($finish($cookie,'https://foreign.test')->status===403,'foreign-origin completion rejected');
 $result=$finish($cookie);check($result->status===200,'valid external completion issues local session');$data=json_decode($result->body,true,32,JSON_THROW_ON_ERROR);check($data['user']['id']===$user&&$data['user']['role']==='viewer'&&$data['security']['reauthenticated_until']===null,'local role and password reauthentication policy preserved');
 $local=explode(';',$result->header('Set-Cookie'),2)[0];check(str_contains($result->header('Set-Cookie'),'SameSite=Strict')&&str_contains($result->header('Set-Cookie'),'Secure'),'local session cookie security');
 check($app->handle(new Request('GET','/api/v1/auth/me',['Cookie'=>$local],secureTransport:true))->status===200,'existing auth/me accepts external local session');
 check($finish($cookie)->status===401,'completion replay rejected');check($finish($pending(-1))->status===401,'expired completion rejected');check($finish($pending(300,'unbound-subject'))->status===401,'unbound external subject rejected');
 $pdo->prepare('UPDATE users SET two_factor_enabled=true WHERE id=:id')->execute(['id'=>$user]);check($finish($pending())->status===401,'enrolled local second factor not bypassed');$pdo->prepare('UPDATE users SET two_factor_enabled=false,blocked_at=now() WHERE id=:id')->execute(['id'=>$user]);check($finish($pending())->status===401,'blocked local account denied');
 check((int)$pdo->query('SELECT count(*) FROM auth_sessions')->fetchColumn()===1,'no extra sessions on failure');
 check((new \Cajeer\Logs\Lifecycle\Maintenance($pdo))->authCleanup()['skif4er_flows']>=1,'expired login flows cleaned');
 // Real local password login, cookie/CSRF enforcement and session-bound linking.
 $pdo->prepare('UPDATE users SET blocked_at=NULL WHERE id=:id')->execute(['id'=>$user]);
 $login=function(string $email,string $password)use($app){
     $res=$app->handle(new Request('POST','/api/v1/auth/login',['Origin'=>'https://logs.example.test','Content-Type'=>'application/json'],[],json_encode(['email'=>$email,'password'=>$password],JSON_THROW_ON_ERROR),'192.0.2.2',true));
     check($res->status===200,'local password login');return [explode(';',$res->header('Set-Cookie'),2)[0],json_decode($res->body,true,32,JSON_THROW_ON_ERROR)['csrf_token']];
 };
 [$passwordCookie,$csrf]=$login('viewer@skif4er.test','fixture-local-password-123');
 $call=function(string $method,string $path,string $cookie,string $csrf='',array $body=[],string $origin='https://logs.example.test')use($app){
     return $app->handle(new Request($method,'/api/v1'.$path,['Cookie'=>$cookie,'Origin'=>$origin,'Content-Type'=>'application/json','X-CSRF-Token'=>$csrf],[],json_encode((object)$body,JSON_THROW_ON_ERROR),'192.0.2.3',true));
 };
 $account=json_decode($call('GET','/auth/skif4er/account',$passwordCookie)->body,true,32,JSON_THROW_ON_ERROR);
 check($account['linked']===true&&$account['auth_method']==='password','linked account and local login provenance');
 $methods=$pdo->query('SELECT auth_method FROM auth_sessions ORDER BY auth_method')->fetchAll(PDO::FETCH_COLUMN);
 check($methods===['password','skif4er'],'external session is classified independently');
 $password=['password'=>'fixture-local-password-123'];
 check($call('POST','/auth/skif4er/unlink',$passwordCookie,'wrong',$password)->status===403,'unlink requires CSRF');
 check($call('POST','/auth/skif4er/revoke',$passwordCookie,$csrf,$password,'https://evil.test')->status===403,'external session revocation requires same origin');
 check($call('POST','/auth/skif4er/revoke',$passwordCookie,$csrf,['password'=>'wrong'])->status===403,'revocation requires correct password');
 $pdo->prepare('UPDATE users SET two_factor_enabled=true WHERE id=:id')->execute(['id'=>$user]);
 check($call('POST','/auth/skif4er/unlink',$passwordCookie,$csrf,$password)->status===403,'enrolled 2FA cannot be skipped on unlink');
 $pdo->prepare('UPDATE users SET two_factor_enabled=false WHERE id=:id')->execute(['id'=>$user]);
 $revoked=$call('POST','/auth/skif4er/revoke',$passwordCookie,$csrf,$password);
 check($revoked->status===200&&json_decode($revoked->body,true)['sessions_revoked']===1,'bulk revokes only external sessions');
 check($call('GET','/auth/me',$local)->status===401&&$call('GET','/auth/me',$passwordCookie)->status===200,'password session survives revocation');
 $again=$finish($pending());check($again->status===200,'new external login remains possible when linked');$againCookie=explode(';',$again->header('Set-Cookie'),2)[0];$againCsrf=json_decode($again->body,true)['csrf_token'];
 $unlink=$call('POST','/auth/skif4er/unlink',$againCookie,$againCsrf,$password);
 check($unlink->status===200&&json_decode($unlink->body,true)['current_session_revoked']===true,'unlink current external session ends it');
 check($call('GET','/auth/me',$againCookie)->status===401&&$call('GET','/auth/me',$passwordCookie)->status===200,'unlink preserves password access');
 check($finish($pending())->status===401,'unlinked identity cannot sign in');
 $sessionId=$pdo->prepare('SELECT id FROM auth_sessions WHERE token_hash=:hash');$sessionId->execute(['hash'=>hash('sha256',explode('=',$passwordCookie,2)[1])]);$sid=$sessionId->fetchColumn();
 // Persist the exact result of a verified provider callback (the provider itself
 // is covered by signed-token transport tests in skif4er_auth.php).
 $pendingLink=function(string $subject,string $email='verified@example.test')use($pdo,$user,$sid){$browser=Skif4erOidc::random();$pdo->prepare("INSERT INTO skif4er_login_flows(browser_hash,subject,user_id,link_session_id,verified_email,expires_at) VALUES (:browser,:subject,:user,:session,:email,clock_timestamp()+interval '5 minutes')")->execute(['browser'=>hash('sha256',$browser),'subject'=>$subject,'user'=>$user,'session'=>$sid,'email'=>$email]);return '__Host-cajeerlogs_oidc='.$browser;};
 $other=(new UserRepository($pdo))->create('other@skif4er.test','fixture-other-password-123','viewer');
 [$otherCookie,$otherCsrf]=$login('other@skif4er.test','fixture-other-password-123');
 $flow=$pendingLink('relinked-subject');
 check($call('POST','/auth/skif4er/link/finish',$otherCookie.'; '.$flow,$otherCsrf)->status===409,'another local session cannot consume link');
 check($finish($flow)->status===401,'link flow cannot be used as login completion');
 $review=json_decode($call('GET','/auth/skif4er/account',$passwordCookie.'; '.$flow)->body,true);
 check($review['pending_email']==='verified@example.test'&&!$review['linked'],'review verified identity before linking');
 check($call('POST','/auth/skif4er/link/finish',$passwordCookie.'; '.$flow,$csrf)->status===200,'same local session confirms verified identity');
 check($call('POST','/auth/skif4er/link/finish',$passwordCookie.'; '.$flow,$csrf)->status===409,'link confirmation cannot be replayed');
 check($pdo->query("SELECT user_id FROM skif4er_identities WHERE subject='relinked-subject'")->fetchColumn()===$user,'subject binding persists');
 $conflicting=$pendingLink('another-subject');
 check($call('POST','/auth/skif4er/link/finish',$passwordCookie.'; '.$conflicting,$csrf)->status===409,'existing identity cannot be silently replaced');
 $cfg->set('skif4er.enabled',false);
 check($call('GET','/auth/skif4er/account',$passwordCookie)->status===200,'external management available when provider disabled');
 check($call('POST','/auth/skif4er/unlink',$passwordCookie,$csrf,$password)->status===200,'unlink works when provider disabled');
 echo "SkiF4er PostgreSQL: $n checks passed\n";
} finally {if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();$base->exec('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');}
