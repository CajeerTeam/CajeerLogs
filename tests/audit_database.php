<?php
declare(strict_types=1);
use Cajeer\Logs\Audit\AuditLogger;
use Cajeer\Logs\Database\{MigrationRunner,ConnectionFactory};
use Cajeer\Logs\Database\Repository\UserRepository;
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Http\{Request,Response};
if (getenv('CAJEER_TEST_DATABASE')!=='1'||getenv('APP_ENV')!=='test') throw new RuntimeException('Use a disposable PostgreSQL database.');
$root=dirname(__DIR__);$baseApp=require $root.'/core/bootstrap.php';$baseApp->boot();$base=$baseApp->container()->get(PDO::class);$schema='cl_audit_'.bin2hex(random_bytes(8));$base->exec('CREATE SCHEMA "'.$schema.'"');$tmp=sys_get_temp_dir().'/'.$schema;mkdir($tmp,0700);$n=0;
function check(bool $ok,string $label):void {global $n;if(!$ok)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
function call(string $method,string $path,array $headers=[],?array $body=null,int $status=200,array $query=[]):Response {global $app;$r=$app->handle(new Request($method,'/api/v1'.$path,['Content-Type'=>'application/json']+$headers,$query,$body===null?'':json_encode($body,JSON_THROW_ON_ERROR),'192.0.2.90'));check($r->status===$status,"$method $path expected $status got {$r->status}");return $r;}
function data(Response $r):array {return json_decode($r->body,true,64,JSON_THROW_ON_ERROR);}
function login(string $email,string $password):array {$r=call('POST','/auth/login',[],['email'=>$email,'password'=>$password]);return ['Cookie'=>explode(';',$r->header('Set-Cookie'),2)[0],'X-CSRF-Token'=>data($r)['csrf_token']];}
try {
 $app=new Application($root);$app->boot();$cfg=$app->config();$cfg->set('database.schema',$schema);$cfg->set('app.env','test');$cfg->set('security.require_admin_totp',false);$cfg->set('security.required_totp_roles','');$cfg->set('security.required_totp_permissions','');$cfg->set('security.reauth_seconds',0);$cfg->set('api.rate_limit',600);$pdo=$app->container()->get(PDO::class);
 foreach(glob($root.'/migrations/pgsql/*.sql') as $f) if((int)substr(basename($f),0,4)<19)copy($f,$tmp.'/'.basename($f));
 (new MigrationRunner($pdo,$tmp,$schema))->migrate();$legacy=$pdo->query("INSERT INTO audit_log(action,target,context) VALUES('project.created','11111111-1111-4111-8111-111111111111','{}') RETURNING id")->fetchColumn();
 $pdo->exec("INSERT INTO audit_log(action,context) VALUES('auth.login_failed','{}')");
 $app->container()->get(MigrationRunner::class)->migrate();$audit=new AuditLogger($pdo);$head=$audit->verify();check($head['valid']&&$head['entries']===2,'historical rows migrated into verified chain');
 check($pdo->query("SELECT project_id::text FROM audit_log WHERE id='$legacy'")->fetchColumn()==='11111111-1111-4111-8111-111111111111','legacy scope backfilled');
 $audit->record('test.append');check($audit->verify($head['head_position'],$head['head_hash'])['valid'],'old checkpoint remains valid after append');
 $pdo->beginTransaction();$audit->record('test.rollback');$pdo->rollBack();check($audit->verify()['entries']===3,'rollback leaves no gap');
 foreach(["UPDATE audit_log SET action='tampered'","DELETE FROM audit_log","TRUNCATE audit_log"] as $sql){try{$pdo->exec($sql);throw new RuntimeException('mutation unexpectedly allowed');}catch(PDOException $e){check($e->getCode()==='42501','audit mutation rejected');}}
 check(!$audit->verify(3,str_repeat('a',64))['valid'],'wrong checkpoint fails');
 // Simulate a privileged DBA bypass; the original checkpoint detects tail deletion.
 $pdo->exec('ALTER TABLE audit_log DISABLE TRIGGER audit_reject_mutation');$checkpoint=$audit->verify();$pdo->exec('DELETE FROM audit_log WHERE chain_position=3');$pdo->exec('ALTER TABLE audit_log ENABLE ALWAYS TRIGGER audit_reject_mutation');check($audit->verify()['valid']&&!$audit->verify($checkpoint['head_position'],$checkpoint['head_hash'])['valid'],'trusted checkpoint detects removed tail');
 $pdo->exec('ALTER TABLE audit_log DISABLE TRIGGER audit_reject_mutation');$pdo->exec("UPDATE audit_log SET action='tampered' WHERE chain_position=1");check(!$audit->verify()['valid'],'hash detects modified metadata');$pdo->exec("UPDATE audit_log SET action='project.created' WHERE chain_position=1");$pdo->exec('ALTER TABLE audit_log ENABLE ALWAYS TRIGGER audit_reject_mutation');
 // Security attributes were not part of legacy schema fingerprints. They must now fail the same guard.
 $runner=$app->container()->get(MigrationRunner::class);
 $pdo->exec('ALTER FUNCTION audit_scope(text,text,jsonb) SECURITY DEFINER');
 check($runner->status(false)['integrity']==='drift','security definer drift detected on audit function');
 check($app->handle(new Request('GET','/ready'))->status===503,'readiness rejects elevated audit function');
 $pdo->exec('ALTER FUNCTION audit_scope(text,text,jsonb) SECURITY INVOKER');
 $pdo->exec('ALTER FUNCTION audit_scope(text,text,jsonb) RESET search_path');
 check($runner->status(false)['integrity']==='drift','audit search_path drift detected');
 $pdo->exec('ALTER FUNCTION audit_scope(text,text,jsonb) SET search_path TO pg_catalog');
 $pdo->exec('ALTER FUNCTION api_rate_charge(text,integer,integer) STABLE');
 check($runner->status(false)['integrity']==='drift','rate limiter volatility drift detected');
 $pdo->exec('ALTER FUNCTION api_rate_charge(text,integer,integer) VOLATILE');
 check($runner->status(false)['integrity']==='verified','restored function attributes preserve original migration history');
 $users=new UserRepository($pdo);$password='audit-test-password-123';$admin=$users->create('admin@audit.test',$password,'administrator');$op=$users->create('operator@audit.test',$password,'operator');$viewer=$users->create('viewer@audit.test',$password,'viewer');$a=login('admin@audit.test',$password);$o=login('operator@audit.test',$password);$v=login('viewer@audit.test',$password);
 $p=data(call('POST','/projects',$a,['name'=>'Audit one','slug'=>'audit-one'],201))['id'];$other=data(call('POST','/projects',$a,['name'=>'Audit two','slug'=>'audit-two'],201))['id'];call('PUT',"/projects/$p/members/$op",$a,null,204);call('PUT',"/projects/$p/members/$viewer",$a,null,204);
 $path="/projects/$p/security/audit";$page=data(call('GET',$path,$o,null,200,['result'=>'success','actor_type'=>'user','action'=>'project.created','from'=>'2000-01-01T00:00:00Z','to'=>'2099-01-01T00:00:00Z']));check(count($page['items'])===1&&$page['items'][0]['project_id']===$p&&!array_key_exists('chain_position',$page['items'][0]),'scope and filters enforced without global sequence disclosure');
 $r=call('GET',$path,$o,null,200,['format'=>'ndjson','limit'=>1]);check(str_contains($r->header('Content-Type'),'application/x-ndjson')&&is_string($r->header('X-Next-Cursor'))&&json_decode(trim($r->body),true,64,JSON_THROW_ON_ERROR)['project_id']===$p,'bounded NDJSON page and cursor');
 call('GET',$path,$v,null,403,['format'=>'ndjson']);call('GET',"/projects/$other/security/audit",$o,null,403);call('GET','/system/security/audit',$o,null,403);call('GET',$path,$o,null,422,['from'=>'2026-02-30T00:00:00Z']);
 $global=data(call('GET','/system/security/audit',$a,null,200,['result'=>'denied']));check(count($global['items'])>=3&&$global['items'][0]['project_id']===null,'denials globally audited after failed operations');check($audit->verify()['valid'],'API events maintain chain integrity');
 echo "Audit PostgreSQL: $n checks passed\n";
} finally {if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();$base->exec('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');foreach(glob($tmp.'/*') as $f)unlink($f);rmdir($tmp);}
