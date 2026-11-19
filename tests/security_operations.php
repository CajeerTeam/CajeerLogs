<?php
declare(strict_types=1);
use Cajeer\Logs\Security\TwoFactorManager as T;
use Cajeer\Logs\Http\Request;
use Cajeer\Logs\Storage\LocalDisk;
use Cajeer\Logs\Operations\BackupManager;
use Cajeer\Logs\Database\DatabaseException;
$app=require dirname(__DIR__).'/core/bootstrap.php'; $app->boot(); $app->config()->set('app.env','test');
$count=0; function check(bool $ok,string $label): void { global $count; if (!$ok) throw new RuntimeException('FAIL '.$label); ++$count; echo "PASS $label\n"; }
function fails(callable $fn,string $type): bool { try { $fn(); } catch(Throwable $e) { return $e instanceof $type; } return false; }
$secret='12345678901234567890';
foreach ([59=>'94287082',1111111109=>'07081804',1111111111=>'14050471',1234567890=>'89005924',2000000000=>'69279037',20000000000=>'65353130'] as $t=>$code) { check(T::code($secret,intdiv($t,30),8)===$code,'RFC6238 SHA1 '.$t); }
check(T::base32('foobar')==='MZXW6YTBOI','RFC4648 base32');
$step=intdiv(1234567890,30); $code=T::code($secret,$step);
check(T::matchingStep($secret,$code,1234567890,-1)===$step,'valid TOTP');
check(T::matchingStep($secret,$code,1234567890,$step)===null,'TOTP replay rejected');
check(T::matchingStep($secret,T::code($secret,$step-1),1234567890,-1)===$step-1,'previous time window');
check(T::matchingStep($secret,T::code($secret,$step+1),1234567890,-1)===$step+1,'next time window');
check(T::matchingStep($secret,T::code($secret,$step+3),1234567890,-1)===null,'outside time window');
check(T::matchingStep($secret,'12345',1234567890,-1)===null,'malformed OTP');
$pdo=new class extends PDO { public function __construct() {} }; $key=bin2hex(random_bytes(32)); $t=new T($pdo,$key); $id='11111111-1111-4111-8111-111111111111';
$c=$t->seal($id,$secret); check($t->open($id,$c)===$secret && $c!==$t->seal($id,$secret),'authenticated encryption with random nonce');
check(fails(fn()=>$t->open('22222222-2222-4222-8222-222222222222',$c),DatabaseException::class),'ciphertext bound to user');
$raw=base64_decode($c); $raw[30]=chr(ord($raw[30])^1); check(fails(fn()=>$t->open($id,base64_encode($raw)),DatabaseException::class),'tamper detection');
check(fails(fn()=>(new T($pdo,bin2hex(random_bytes(32))))->open($id,$c),DatabaseException::class),'wrong encryption key rejected');
check(!T::keyValid('change_me')&&!T::keyValid(str_repeat('0',64))&&T::keyValid($key),'key validation');
$tmp=sys_get_temp_dir().'/cl09-'.bin2hex(random_bytes(8)); mkdir($tmp,0700);
try {
    mkdir($tmp.'/disk',0700); $disk=new LocalDisk($tmp.'/disk');
    check($disk->path('sub/file')===$tmp.'/disk/sub/file','valid local storage path');
    foreach (['../etc/passwd','/etc/passwd','a/../b','a//b','a/./b','a\\b',"a\0b",'a:b'] as $path) { check(fails(fn()=>$disk->path($path),InvalidArgumentException::class),'unsafe local path rejected'); }
    symlink('/tmp',$tmp.'/disk/link'); check(fails(fn()=>$disk->path('link/secret'),InvalidArgumentException::class),'symlink escape denied');
    $app->config()->set('operations.backup_dir',$tmp.'/backups'); $backup=new BackupManager($app);
    check($backup->list()===[]&&(fileperms($tmp.'/backups')&0777)===0700,'private backup directory');
    check(fails(fn()=>$backup->verify('../../etc/passwd'),DatabaseException::class),'backup traversal rejected');
    $app->config()->set('database.default','invalid'); check(fails(fn()=>$backup->create(),DatabaseException::class)&&$backup->list()===[]&&count(scandir($tmp.'/backups'))===2,'failed backup no published or partial file');
    $app->config()->set('operations.backup_dir',$tmp.'/disk'); chmod($tmp.'/disk',0755); check(fails(fn()=>$backup->list(),DatabaseException::class),'unsafe backup permissions rejected'); chmod($tmp.'/disk',0700);
    $app->config()->set('operations.metrics_token',$key);
    check($app->handle(new Request('GET','/metrics'))->status===401,'protected metrics reject no token');
    $r=$app->handle(new Request('GET','/metrics',['Authorization'=>'Bearer '.$key])); check($r->status===200&&str_contains($r->body,'cajeerlogs_operations_available 0')&&!str_contains($r->body,$key),'metrics authenticate, DB outage explicit');
    check(str_contains($r->body,'cajeerlogs_security_configuration_healthy ') && str_contains($r->body,'cajeerlogs_security_identity_audit_available 0') && !str_contains($r->body,'cajeerlogs_security_admins_without_totp'),'missing DB security metrics fail closed without fabricated counts');
    $r=$app->handle(new Request('GET','/health',[],[],'','127.0.0.1',true)); check($r->header('Strict-Transport-Security')==='max-age=31536000'&&$r->header('Permissions-Policy')!==null,'TLS security headers');
    check($app->handle(new Request('GET','/health'))->header('Strict-Transport-Security')===null,'HSTS not on plain HTTP');
    foreach (['setup','enable','disable'] as $action) { check($app->handle(new Request('POST','/api/v1/auth/2fa/'.$action))->status===401,'TOTP endpoint protected '.$action); }
    $app->config()->set('security.require_admin_totp',true); $app->config()->set('security.totp_key',''); check($app->handle(new Request('GET','/ready'))->status===503,'mandatory enrollment without key not ready');
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) { if ($file->isLink()||!$file->isDir()) unlink($file->getPathname()); else rmdir($file->getPathname()); } rmdir($tmp);
}
echo "Security & Operations: $count checks passed\n";
