<?php
declare(strict_types=1);
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Installer\EnvironmentWriter;
use Cajeer\Logs\Installer\Installer;
use Cajeer\Logs\Database\DatabaseException;
use Cajeer\Logs\Config\Environment;
use Cajeer\Logs\Operations\BackupManager;
use Cajeer\Logs\Http\Request;
$root=dirname(__DIR__); require $root.'/core/bootstrap.php';
$n=0; function check(bool $ok,string $label): void { global $n; if (!$ok) throw new RuntimeException('FAIL '.$label); ++$n; echo "PASS $label\n"; }
function rejects(callable $f,string $message): bool { try { $f(); } catch (Throwable $e) { return str_contains($e->getMessage(),$message); } return false; }
$tmp=sys_get_temp_dir().'/cl-release-'.bin2hex(random_bytes(8)); mkdir($tmp,0700); mkdir($tmp.'/config',0700);
$old=getenv('CAJEER_ENV_FILE'); $env=getenv('APP_ENV'); $url=getenv('APP_URL');
try {
    putenv('APP_ENV=production'); putenv('APP_URL=https://logs.example.test');
    $file=$tmp.'/config/.env'; putenv('CAJEER_ENV_FILE='.$file);
    file_put_contents($file,"APP_NAME=ExternalConfig\n"); chmod($file,0600);
    $app=new Application($root); $app->boot();
    check($app->environmentPath()===$file && $app->config()->get('app.name')==='ExternalConfig','external config loaded from private volume');
    $app->config()->set('database.password','runtime-test-password-12345');
    (new EnvironmentWriter($app))->write(); $values=Environment::load($file);
    check($values['DB_PASSWORD']==='runtime-test-password-12345'&&$values['APP_URL']==='https://logs.example.test'&&(fileperms($file)&0777)===0600,'installer writes external config atomically');
    $key=$values['SECURITY_HMAC_SECRET']; (new EnvironmentWriter($app))->write();
    check(Environment::load($file)['SECURITY_HMAC_SECRET']===$key,'repeat install preserves external secrets');
    putenv('CAJEER_ENV_FILE=relative/.env'); check(rejects(fn()=>(new Application($root))->boot(),'CAJEER_ENV_FILE'),'relative config rejected');
    putenv('CAJEER_ENV_FILE='.$root.'/public/.env'); check(rejects(fn()=>(new Application($root))->boot(),'CAJEER_ENV_FILE'),'public config rejected');
    unlink($file); symlink($root.'/.env.example',$file); putenv('CAJEER_ENV_FILE='.$file);
    check(rejects(fn()=>(new Application($root))->boot(),'CAJEER_ENV_FILE'),'external config symlink rejected'); unlink($file);
    putenv('CAJEER_ENV_FILE='.$file); putenv('APP_URL=https://logs.example.test/subdir');
    check(rejects(fn()=>(new Application($root))->boot(),'Invalid APP_URL'),'unsupported subpath rejected');
    putenv('APP_URL=http://logs.example.test'); $app=new Application($root); $app->boot();
    check(rejects(fn()=>(new Installer($app))->run('admin@example.test','test-only-password-123'),'https_origin'),'production installer rejects HTTP before touching DB');
    check($app->handle(new Request('GET','/ready'))->status===503,'production HTTP origin never ready');
    check(BackupManager::compatibleRelease('0.0.9','0.2.5')&&BackupManager::compatibleRelease('0.1.0','0.2.5')&&BackupManager::compatibleRelease('0.1.1','0.2.5')&&BackupManager::compatibleRelease('0.1.2','0.2.5')&&BackupManager::compatibleRelease('0.1.3','0.2.5'),'restore supported from previous compatible releases');
    check(BackupManager::compatibleRelease('0.1.4','0.2.5')&&BackupManager::compatibleRelease('0.1.5','0.2.5'),'previous release restore');
    check(BackupManager::compatibleRelease('0.1.6','0.2.5'),'0.1.6 backup accepted');
    check(BackupManager::compatibleRelease('0.1.7','0.2.5'),'0.1.7 backup accepted');
    check(BackupManager::compatibleRelease('0.1.8','0.2.5')&&BackupManager::compatibleRelease('0.1.9','0.2.5')&&BackupManager::compatibleRelease('0.2.0','0.2.5')&&BackupManager::compatibleRelease('0.2.1','0.2.5')&&BackupManager::compatibleRelease('0.2.2','0.2.5')&&BackupManager::compatibleRelease('0.2.3','0.2.5')&&BackupManager::compatibleRelease('0.2.4','0.2.5')&&BackupManager::compatibleRelease('0.2.5','0.2.5'),'previous and same release restore');
    check(!BackupManager::compatibleRelease('0.0.8','0.2.5')&&!BackupManager::compatibleRelease('0.2.6','0.2.5')&&!BackupManager::compatibleRelease(null,'0.2.5'),'incompatible restore rejected');
} finally {
    foreach (['CAJEER_ENV_FILE'=>$old,'APP_ENV'=>$env,'APP_URL'=>$url] as $name=>$value) putenv($value===false?$name:$name.'='.$value);
    if (file_exists($tmp.'/config/.env')||is_link($tmp.'/config/.env')) unlink($tmp.'/config/.env'); rmdir($tmp.'/config'); rmdir($tmp);
}
echo "Release runtime: $n checks passed\n";
