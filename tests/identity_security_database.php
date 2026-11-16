<?php
declare(strict_types=1);
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Database\{MigrationRunner,DatabaseException};
use Cajeer\Logs\Database\Repository\UserRepository;
use Cajeer\Logs\Security\TwoFactorManager as T;
use Cajeer\Logs\Http\{Request,Response};
if (getenv('CAJEER_TEST_DATABASE')!=='1'||getenv('APP_ENV')!=='test') throw new RuntimeException('Use a disposable PostgreSQL database.');
$root=dirname(__DIR__); $app=require $root.'/core/bootstrap.php'; $app->boot();
$base=$app->container()->get(PDO::class);$schema='cl_identity_sec_'.bin2hex(random_bytes(8));$base->exec('CREATE SCHEMA "'.$schema.'"');
$count=0;
function check(bool $ok,string $label):void {global $count;if(!$ok)throw new RuntimeException('FAIL '.$label);++$count;echo "PASS $label\n";}
function call(string $method,string $path,array $headers=[],?array $body=null,int $status=200,array $query=[]):Response {global $app;$r=$app->handle(new Request($method,'/api/v1'.$path,['Content-Type'=>'application/json']+$headers,$query,$body===null?'':json_encode($body,JSON_THROW_ON_ERROR),'192.0.2.88'));check($r->status===$status,"$method $path => $status (got {$r->status})");return $r;}
function data(Response $r):array{return json_decode($r->body,true,64,JSON_THROW_ON_ERROR);}
function credentials(Response $r):array{return ['Cookie'=>explode(';',$r->header('Set-Cookie'),2)[0],'X-CSRF-Token'=>data($r)['csrf_token']];}
function hashOf(array $headers):string{return hash('sha256',explode('=',$headers['Cookie'],2)[1]);}
try {
 $app=new Application($root);$app->boot();$cfg=$app->config();$cfg->set('database.schema',$schema);$cfg->set('app.env','test');$cfg->set('security.require_admin_totp',false);$cfg->set('security.totp_key',bin2hex(random_bytes(32)));$cfg->set('security.session_idle_seconds',120);$cfg->set('security.reauth_seconds',300);$cfg->set('security.required_totp_roles','operator');$cfg->set('security.required_totp_permissions','');$cfg->set('api.rate_limit',600);
 $pdo=$app->container()->get(PDO::class);$app->container()->get(MigrationRunner::class)->migrate();$users=new UserRepository($pdo);
 $password='identity-security-test-123';$id=$users->create('operator@identity.test',$password,'operator');$admin=$users->create('admin@identity.test',$password,'administrator');$viewer=$users->create('viewer@identity.test',$password,'viewer');
 $login=['email'=>'operator@identity.test','password'=>$password];$a=credentials(call('POST','/auth/login',[],['email'=>'admin@identity.test','password'=>$password]));$v=credentials(call('POST','/auth/login',[],['email'=>'viewer@identity.test','password'=>$password]));
 $project=data(call('POST','/projects',$a,['name'=>'Identity','slug'=>'identity'],201))['id'];call('PUT',"/projects/$project/members/$id",$a,null,204);call('PUT',"/projects/$project/members/$viewer",$a,null,204);
 $r=call('POST','/auth/login',[],$login);$o=credentials($r);check(data($r)['enrollment_required']===true,'operator policy restricted enrollment');
 call('GET','/projects',$o,null,403);call('GET','/logs',$o,null,403,['project_id'=>$project]);call('GET','/auth/me',$o);call('POST','/auth/reauthenticate',$o,['password'=>$password],403);
 $cfg->set('security.required_totp_permissions','logs:export');check(data(call('GET','/auth/me',$v))['enrollment_required']===true,'existing Viewer session restricted by permission policy');call('GET','/logs',$v,null,403,['project_id'=>$project]);$cfg->set('security.required_totp_permissions','');
 call('POST','/auth/2fa/setup',$o,['password'=>$password]);$stmt=$pdo->prepare('SELECT secret_ciphertext FROM user_totp WHERE user_id=:id');$stmt->execute(['id'=>$id]);$legacy=$stmt->fetchColumn();$secret=T::fromConfig($pdo,$cfg)->open($id,$legacy);$stmt->closeCursor();
 $cfg->set('security.totp_active_key',bin2hex(random_bytes(32)));$t=T::fromConfig($pdo,$cfg);check($t->rotateBatch(1)['remaining']===0,'pending enrollment rotated');check($t->verifyKeys()===['verified'=>1,'remaining'=>0],'all encrypted rows verified');
 $r=call('POST','/auth/2fa/enable',$o,['otp'=>T::code($secret,intdiv(time(),30))]);$o=credentials($r);$codes=data($r)['recovery_codes'];call('GET','/logs',$o,null,200,['project_id'=>$project]);
 call('POST','/auth/2fa/disable',$o,['password'=>$password,'otp'=>$codes[0]],403);call('POST','/auth/password',$o,['current_password'=>$password,'password'=>'new-identity-password-123'],403);
 // Freshness is server-side, not a supplied header, and a stale request must not write.
 $q=$pdo->prepare("UPDATE auth_sessions SET reauthenticated_at=clock_timestamp()-interval '10 minutes' WHERE token_hash=:hash");$q->execute(['hash'=>hashOf($a)]);
 $r=call('POST','/roles',$a,['name'=>'Step up role','scope'=>'global','permissions'=>['projects:read']],403);check(data($r)['error']==='reauthentication_required','stale session cannot mutate roles');
 call('POST','/auth/reauthenticate',$a,['password'=>'wrong'],403);call('POST','/auth/reauthenticate',$a,['password'=>$password]);call('POST','/roles',$a,['name'=>'Step up role','scope'=>'global','permissions'=>['projects:read']],201);
 $pdo->exec('DELETE FROM auth_rate_limits'); $pdo->exec('DELETE FROM api_rate_buckets');
 $q->execute(['hash'=>hashOf($o)]);call('POST','/auth/reauthenticate',$o,['password'=>$password,'otp'=>'00000000000000000000000000000000'],403);call('POST','/auth/reauthenticate',$o,['password'=>$password,'otp'=>$codes[0]]);call('POST','/auth/reauthenticate',$o,['password'=>$password,'otp'=>$codes[0]],403);
 // Rotation to a second key preserves the enrolled secret, replay fence and recovery code set.
 $previous=$cfg->get('security.totp_active_key');$cfg->set('security.totp_previous_key',$previous);$cfg->set('security.totp_active_key',bin2hex(random_bytes(32)));$t=T::fromConfig($pdo,$cfg);check($t->rotateBatch(1)['rotated']===1,'second rotation migrates enrolled factor');check($t->rotateBatch(1)['rotated']===0,'rotation is idempotent');$cfg->set('security.totp_previous_key','');$stmt->execute(['id'=>$id]);check(hash_equals($secret,T::fromConfig($pdo,$cfg)->open($id,$stmt->fetchColumn())),'new key decrypts same enrollment without previous key');$stmt->closeCursor();
 // Me/background requests check expiry but do not extend idle time.
 $q=$pdo->prepare("UPDATE auth_sessions SET last_seen_at=clock_timestamp()-interval '90 seconds' WHERE token_hash=:hash RETURNING last_seen_at");$q->execute(['hash'=>hashOf($v)]);$before=$q->fetchColumn();$q->closeCursor();call('GET','/auth/me',$v);call('GET','/projects',$v+['X-Cajeer-Background'=>'1']);$read=$pdo->prepare('SELECT last_seen_at FROM auth_sessions WHERE token_hash=:hash');$read->execute(['hash'=>hashOf($v)]);check($read->fetchColumn()===$before,'polling does not extend idle timeout');$read->closeCursor();call('GET','/projects',$v);$read->execute(['hash'=>hashOf($v)]);check($read->fetchColumn()!==$before,'interactive request refreshes activity');$read->closeCursor();$pdo->prepare("UPDATE auth_sessions SET last_seen_at=clock_timestamp()-interval '121 seconds' WHERE token_hash=:hash")->execute(['hash'=>hashOf($v)]);call('GET','/auth/me',$v,null,401);call('GET','/logs',$v,null,401,['project_id'=>$project]);
 check((new \Cajeer\Logs\Lifecycle\Maintenance($pdo,120))->authCleanup()['sessions']>=1,'idle session cleanup');
 check((int)$pdo->query("SELECT count(*) FROM audit_log WHERE action='auth.reauthenticated'")->fetchColumn()===2,'successful confirmations audited');
 check((int)$pdo->query("SELECT count(*) FROM audit_log WHERE action='auth.reauthentication_failed'")->fetchColumn()>=2,'failed confirmations survive rollback');
 echo "Identity security PostgreSQL: $count checks passed\n";
} finally {if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();$base->exec('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');}
