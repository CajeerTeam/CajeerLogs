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
 echo "SkiF4er PostgreSQL: $n checks passed\n";
} finally {if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();$base->exec('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');}
