<?php
declare(strict_types=1);
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Database\{MigrationRunner,ConnectionFactory,DatabaseException};
use Cajeer\Logs\Database\Repository\{UserRepository,ProjectRepository,SourceRepository,TokenRepository};
use Cajeer\Logs\Security\TwoFactorManager as T;
use Cajeer\Logs\Http\{Request,Response};
use Cajeer\Logs\Operations\BackupManager;
use Cajeer\Logs\Console\Command\OperationsCommand;
if (getenv('CAJEER_TEST_DATABASE')!=='1') { fwrite(STDERR,"Set CAJEER_TEST_DATABASE=1 for disposable PostgreSQL.\n"); exit(1); }
$root=dirname(__DIR__); $baseApp=require $root.'/core/bootstrap.php'; $baseApp->boot(); $base=$baseApp->container()->get(PDO::class);
$schema='cl_sec_'.bin2hex(random_bytes(8)); $base->exec('CREATE SCHEMA "'.$schema.'"');
$tmp=sys_get_temp_dir().'/cl-sec-db-'.bin2hex(random_bytes(8)); mkdir($tmp,0700); mkdir($tmp.'/migrations',0700);
$count=0;
function verify(bool $ok,string $label):void { global $count; if (!$ok) throw new RuntimeException('FAIL '.$label); ++$count; echo "PASS $label\n"; }
function call(string $method,string $path,?array $body=null,array $headers=[]): Response { global $app; return $app->handle(new Request($method,'/api/v1'.$path,['Content-Type'=>'application/json']+$headers,[], $body===null?'':json_encode($body,JSON_THROW_ON_ERROR),'192.0.2.12')); }
function data(Response $r,int $status=200): array { verify($r->status===$status,'HTTP '.$status.' (got '.$r->status.')'); return $r->body===''?[]:json_decode($r->body,true,64,JSON_THROW_ON_ERROR); }
function credentials(Response $r): array { $d=data($r); return ['Cookie'=>explode(';',$r->header('Set-Cookie'),2)[0],'X-CSRF-Token'=>$d['csrf_token']]; }
function rejected(callable $f): bool { try {$f();} catch(DatabaseException) { return true; } return false; }
try {
    $app=new Application($root); $app->boot(); $cfg=$app->config(); $cfg->set('app.env','test'); $cfg->set('database.schema',$schema); $cfg->set('security.totp_key',bin2hex(random_bytes(32))); $cfg->set('security.require_admin_totp',true); $cfg->set('operations.backup_dir',$tmp.'/backups');
    $pdo=$app->container()->get(PDO::class);
    foreach (glob($root.'/migrations/pgsql/000[1-7]_*.sql') as $f) copy($f,$tmp.'/migrations/'.basename($f));
    (new MigrationRunner($pdo,$tmp.'/migrations',$schema))->migrate();
    $id=(new UserRepository($pdo))->create('security@example.test','admin-sec-password-123','administrator');
    $runner=$app->container()->get(MigrationRunner::class); verify($runner->migrate()===['0008_security_operations.sql','0009_project_management.sql','0010_source_management.sql']&&$runner->migrate()===[],'0.0.8 upgrade and migration replay');
    $password=['email'=>'security@example.test','password'=>'admin-sec-password-123'];
    $login=call('POST','/auth/login',$password); $session=credentials($login); verify(json_decode($login->body,true)['enrollment_required']===true,'mandatory administrator restricted login');
    data(call('GET','/projects',null,$session),403); data(call('GET','/auth/me',null,$session));
    data(call('POST','/auth/2fa/setup',['password'=>'wrong'],$session),403);
    $setup=data(call('POST','/auth/2fa/setup',['password'=>$password['password']],$session)); verify(strlen($setup['secret'])===32&&str_starts_with($setup['otpauth_uri'],'otpauth://totp/'),'enrollment returns key once');
    $row=$pdo->query('SELECT secret_ciphertext FROM user_totp')->fetchColumn(); $t=new T($pdo,$cfg->get('security.totp_key')); $secret=$t->open($id,$row); verify(T::base32($secret)===$setup['secret']&&!str_contains($row,$setup['secret']),'encrypted enrollment persisted');
    $step=intdiv(time(),30); $code=T::code($secret,$step); $enabled=call('POST','/auth/2fa/enable',['otp'=>$code],$session); $session2=credentials($enabled); $codes=json_decode($enabled->body,true)['recovery_codes']; verify(count($codes)===10&&count(array_unique($codes))===10,'ten recovery codes');
    verify((int)$pdo->query('SELECT count(*) FROM user_recovery_codes')->fetchColumn()===10&&!in_array($codes[0],$pdo->query('SELECT code_hash FROM user_recovery_codes')->fetchAll(PDO::FETCH_COLUMN),true),'recovery codes hashed');
    $correctKey=$cfg->get('security.totp_key'); $cfg->set('security.totp_key',bin2hex(random_bytes(32)));
    verify($app->handle(new Request('GET','/ready'))->status===503,'wrong but valid TOTP key fails readiness');
    $cfg->set('security.totp_key',$correctKey); verify($app->handle(new Request('GET','/ready'))->status===200,'original TOTP key restores readiness');
    data(call('GET','/auth/me',null,$session),401); data(call('GET','/projects',null,$session2));
    data(call('POST','/auth/login',$password),401); data(call('POST','/auth/login',$password+['otp'=>$code]),401);
    $new=credentials(call('POST','/auth/login',$password+['otp'=>T::code($secret,$step+1)]));
    data(call('GET','/auth/me',null,$new)); data(call('POST','/auth/login',$password+['otp'=>T::code($secret,$step+1)]),401);
    $rec=credentials(call('POST','/auth/login',$password+['otp'=>$codes[0]])); data(call('POST','/auth/login',$password+['otp'=>$codes[0]]),401);
    verify((int)$pdo->query('SELECT count(*) FROM user_recovery_codes')->fetchColumn()===9,'recovery code consumed atomically');
    data(call('POST','/auth/2fa/disable',['password'=>$password['password'],'otp'=>$codes[1]],$rec),403);
    $cfg->set('security.require_admin_totp',false);
    data(call('POST','/auth/2fa/disable',['password'=>$password['password'],'otp'=>$codes[1]],$rec),204); data(call('GET','/auth/me',null,$rec),401);
    verify((int)$pdo->query('SELECT count(*) FROM user_totp')->fetchColumn()===0&&(int)$pdo->query('SELECT count(*) FROM user_recovery_codes')->fetchColumn()===0,'disable removes secret and codes');
    $new=credentials(call('POST','/auth/login',$password));
    $p=(new ProjectRepository($pdo))->create('Backup project','backup-project'); $source=(new SourceRepository($pdo))->create($p,'App','app'); $token=(new TokenRepository($pdo))->create('backup writer',['logs:write'],$p,$source);
    $event=$app->handle(new Request('POST','/api/v1/ingest',['Content-Type'=>'application/json','Authorization'=>'Bearer '.$token['token']],[],json_encode(['source'=>'app','level'=>'info','message'=>'backup proof','timestamp'=>'2026-11-14T00:00:00Z'],JSON_THROW_ON_ERROR),'192.0.2.12'));
    verify($event->status===201,'real event before backup');
    $report=data(call('GET','/system/report',null,$new)); verify($report['operations']['database']['log_storage_bytes']>0&&$report['security']['totp_key_ready'],'database monitoring reports actual storage');
    $metrics=$app->handle(new Request('GET','/metrics')); verify(str_contains($metrics->body,'cajeerlogs_operations_available 1')&&str_contains($metrics->body,'cajeerlogs_log_storage_bytes '),'real operations Prometheus gauges');
    $backup=new BackupManager($app); $m=$backup->create(); verify($m['bytes']>0&&$backup->verify($m['id'])===$m&&$backup->list()===[$m['id']],'actual pg_dump + checksum + pg_restore list');
    $archive=$tmp.'/backups/'.$m['id'].'/database.dump'; $original=file_get_contents($archive); file_put_contents($archive,$original.'tamper'); clearstatcache(true,$archive); verify(rejected(fn()=>$backup->verify($m['id'])),'corrupted backup rejected'); file_put_contents($archive,$original); clearstatcache(true,$archive);
    verify(rejected(fn()=>$backup->restore($m['id'],$cfg->get('database.database'))),'cannot restore over active database');
    $target=getenv('CAJEER_TEST_RESTORE_DB'); if (!$target) throw new RuntimeException('Provision a separate EMPTY CAJEER_TEST_RESTORE_DB owned by the test role.');
    // 0.0.9 through 0.1.2 have identical numbered SQL migration checksums.
    $manifestPath=$tmp.'/backups/'.$m['id'].'/manifest.json'; $previous=$m; $previous['version']='0.0.9'; file_put_contents($manifestPath,json_encode($previous,JSON_THROW_ON_ERROR));
    $result=$backup->restore($m['id'],$target); verify($result['status']==='restored'&&$result['api_tokens_revoked'],'restore into isolated database');
    $targetConfig=clone $cfg; $targetConfig->set('database.database',$target); $restored=(new ConnectionFactory($targetConfig))->make('pgsql');
    verify($restored->query('SELECT message FROM log_events')->fetchColumn()==='backup proof'&&(int)$restored->query('SELECT count(*) FROM users')->fetchColumn()===1,'restored real event and user');
    verify((int)$restored->query('SELECT count(*) FROM auth_sessions')->fetchColumn()===0&&$restored->query('SELECT revoked_at IS NOT NULL FROM api_tokens')->fetchColumn()===true,'restore invalidates credentials');
    verify($pdo->query('SELECT revoked_at IS NULL FROM api_tokens')->fetchColumn()===true,'source untouched by restore');
    verify(rejected(fn()=>$backup->restore($m['id'],$target)),'connected restore target rejected');
    $restored=null; verify(rejected(fn()=>$backup->restore($m['id'],$target)),'nonempty restore target rejected');
    $restored=(new ConnectionFactory($targetConfig))->make('pgsql'); $restored->exec('DROP SCHEMA "'.$schema.'" CASCADE');
    echo "Security PostgreSQL & backup: $count checks passed\n";
} finally {
    if (isset($pdo)&&$pdo->inTransaction()) $pdo->rollBack(); $base->exec('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f) { if ($f->isDir()) rmdir($f->getPathname()); else unlink($f->getPathname()); } rmdir($tmp);
}
