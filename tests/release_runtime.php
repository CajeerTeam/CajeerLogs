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
$expectedRuntime=getenv('CAJEER_RELEASE_VERSION');
putenv('CAJEER_RELEASE_VERSION=0.9.7');
$n=0; function check(bool $ok,string $label): void { global $n; if (!$ok) throw new RuntimeException('FAIL '.$label); ++$n; echo "PASS $label\n"; }
function rejects(callable $f,string $message): bool { try { $f(); } catch (Throwable $e) { return str_contains($e->getMessage(),$message); } return false; }
check(\Cajeer\Logs\Kernel\Version::read($root)==='0.9.7','expected running release accepted');
putenv('CAJEER_RELEASE_VERSION=0.8.9');
check(rejects(fn()=>\Cajeer\Logs\Kernel\Version::read($root),'CAJEER_RELEASE_VERSION'),'mixed running binary refused');
if ($expectedRuntime===false) putenv('CAJEER_RELEASE_VERSION'); else putenv('CAJEER_RELEASE_VERSION='.$expectedRuntime);

$tmp=sys_get_temp_dir().'/cl-release-'.bin2hex(random_bytes(8)); mkdir($tmp,0700); mkdir($tmp.'/config',0700);
$old=getenv('CAJEER_ENV_FILE'); $env=getenv('APP_ENV'); $url=getenv('APP_URL');
try {
    putenv('APP_ENV=production'); putenv('APP_URL=https://logs.example.test');
    $file=$tmp.'/config/.env'; putenv('CAJEER_ENV_FILE='.$file);
    file_put_contents($file,"APP_NAME=ExternalConfig\n"); chmod($file,0600);
    chmod($file,0644); check(rejects(fn()=>(new Application($root))->boot(),'private'),'production runtime rejects public dotenv'); chmod($file,0600);
    link($file,$tmp.'/config/linked'); check(rejects(fn()=>(new Application($root))->boot(),'unlinked'),'runtime rejects hardlinked dotenv'); unlink($tmp.'/config/linked');
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
    check(BackupManager::compatibleRelease('0.0.9','0.3.0')&&BackupManager::compatibleRelease('0.1.0','0.3.0')&&BackupManager::compatibleRelease('0.1.1','0.3.0')&&BackupManager::compatibleRelease('0.1.2','0.3.0')&&BackupManager::compatibleRelease('0.1.3','0.3.0'),'restore supported from previous compatible releases');
    check(BackupManager::compatibleRelease('0.1.4','0.3.0')&&BackupManager::compatibleRelease('0.1.5','0.3.0'),'previous release restore');
    check(BackupManager::compatibleRelease('0.1.6','0.3.0'),'0.1.6 backup accepted');
    check(BackupManager::compatibleRelease('0.1.7','0.3.0'),'0.1.7 backup accepted');
    check(BackupManager::compatibleRelease('0.1.8','0.3.0')&&BackupManager::compatibleRelease('0.1.9','0.3.0')&&BackupManager::compatibleRelease('0.2.0','0.3.0')&&BackupManager::compatibleRelease('0.2.1','0.3.0')&&BackupManager::compatibleRelease('0.2.2','0.3.0')&&BackupManager::compatibleRelease('0.2.3','0.3.0')&&BackupManager::compatibleRelease('0.2.4','0.3.0')&&BackupManager::compatibleRelease('0.2.5','0.3.0')&&BackupManager::compatibleRelease('0.2.6','0.3.0')&&BackupManager::compatibleRelease('0.2.7','0.3.0')&&BackupManager::compatibleRelease('0.2.8','0.3.0')&&BackupManager::compatibleRelease('0.2.9','0.3.0')&&BackupManager::compatibleRelease('0.3.0','0.3.0')&&BackupManager::compatibleRelease('0.3.0','0.3.1')&&BackupManager::compatibleRelease('0.3.1','0.3.1'),'previous and same release restore');
    check(BackupManager::compatibleRelease('0.3.2','0.3.5')&&BackupManager::compatibleRelease('0.3.3','0.3.5')&&BackupManager::compatibleRelease('0.3.4','0.3.5')&&BackupManager::compatibleRelease('0.3.5','0.3.5')&&BackupManager::compatibleRelease('0.3.5','0.3.6')&&BackupManager::compatibleRelease('0.3.6','0.3.6')&&BackupManager::compatibleRelease('0.3.6','0.3.7')&&BackupManager::compatibleRelease('0.3.7','0.3.7')&&BackupManager::compatibleRelease('0.3.7','0.3.8')&&BackupManager::compatibleRelease('0.3.8','0.3.8')&&BackupManager::compatibleRelease('0.3.8','0.3.9')&&BackupManager::compatibleRelease('0.3.9','0.3.9')&&BackupManager::compatibleRelease('0.3.9','0.4.0')&&BackupManager::compatibleRelease('0.4.0','0.4.0')&&BackupManager::compatibleRelease('0.4.0','0.4.1')&&BackupManager::compatibleRelease('0.4.1','0.4.1')&&BackupManager::compatibleRelease('0.4.1','0.4.2')&&BackupManager::compatibleRelease('0.4.2','0.4.2')&&BackupManager::compatibleRelease('0.4.2','0.4.3')&&BackupManager::compatibleRelease('0.4.3','0.4.3')&&BackupManager::compatibleRelease('0.4.3','0.4.4')&&BackupManager::compatibleRelease('0.4.4','0.4.4')&&BackupManager::compatibleRelease('0.4.4','0.4.5')&&BackupManager::compatibleRelease('0.4.5','0.4.6')&&BackupManager::compatibleRelease('0.4.6','0.4.6')&&BackupManager::compatibleRelease('0.4.6','0.4.7')&&BackupManager::compatibleRelease('0.4.7','0.4.7')&&BackupManager::compatibleRelease('0.4.7','0.4.8')&&BackupManager::compatibleRelease('0.4.8','0.4.8')&&BackupManager::compatibleRelease('0.4.8','0.4.9')&&BackupManager::compatibleRelease('0.4.9','0.4.9'),'0.4.9 backup compatibility');
    check(BackupManager::compatibleRelease('0.4.9','0.4.10')&&BackupManager::compatibleRelease('0.4.10','0.4.10'),'0.4.10 backup compatibility');
    check(BackupManager::compatibleRelease('0.5.9','0.6.0')&&BackupManager::compatibleRelease('0.5.10','0.6.0')&&BackupManager::compatibleRelease('0.6.0','0.6.0')&&BackupManager::compatibleRelease('0.6.0','0.6.1')&&BackupManager::compatibleRelease('0.6.1','0.6.1')&&BackupManager::compatibleRelease('0.6.1','0.6.2')&&BackupManager::compatibleRelease('0.6.2','0.6.2')&&BackupManager::compatibleRelease('0.6.2','0.6.3')&&BackupManager::compatibleRelease('0.6.3','0.6.4')&&BackupManager::compatibleRelease('0.6.2','0.6.4')&&BackupManager::compatibleRelease('0.6.4','0.6.4')&&BackupManager::compatibleRelease('0.6.4','0.6.6')&&BackupManager::compatibleRelease('0.6.5','0.6.6')&&BackupManager::compatibleRelease('0.6.6','0.6.6')&&BackupManager::compatibleRelease('0.6.6','0.6.7')&&BackupManager::compatibleRelease('0.6.7','0.6.7')&&BackupManager::compatibleRelease('0.6.7','0.7.1')&&BackupManager::compatibleRelease('0.6.9','0.7.1')&&BackupManager::compatibleRelease('0.7.1','0.7.1')&&BackupManager::compatibleRelease('0.7.1','0.7.3')&&BackupManager::compatibleRelease('0.7.3','0.7.3')&&BackupManager::compatibleRelease('0.7.3','0.7.4')&&BackupManager::compatibleRelease('0.7.4','0.7.6')&&BackupManager::compatibleRelease('0.7.6','0.7.6')&&BackupManager::compatibleRelease('0.7.6','0.7.7')&&BackupManager::compatibleRelease('0.7.7','0.7.8')&&BackupManager::compatibleRelease('0.7.8','0.8.3')&&BackupManager::compatibleRelease('0.7.9','0.8.3')&&BackupManager::compatibleRelease('0.8.0','0.8.3')&&BackupManager::compatibleRelease('0.8.1','0.8.3')&&BackupManager::compatibleRelease('0.8.2','0.8.3')&&BackupManager::compatibleRelease('0.8.3','0.8.4')&&BackupManager::compatibleRelease('0.8.4','0.8.5')&&BackupManager::compatibleRelease('0.8.5','0.8.7')&&BackupManager::compatibleRelease('0.8.7','0.8.8')&&!BackupManager::compatibleRelease('0.7.3','0.7.1')&&!BackupManager::compatibleRelease('0.7.1','0.6.7')&&!BackupManager::compatibleRelease('0.6.7','0.6.6')&&!BackupManager::compatibleRelease('0.6.6','0.6.4')&&!BackupManager::compatibleRelease('0.6.4','0.6.3')&&!BackupManager::compatibleRelease('0.6.3','0.6.2')&&!BackupManager::compatibleRelease('0.6.2','0.6.1')&&!BackupManager::compatibleRelease('0.6.1','0.6.0'),'0.8.7 backup compatibility');
    check(BackupManager::compatibleRelease('0.5.7','0.5.8')&&BackupManager::compatibleRelease('0.5.8','0.5.8')&&!BackupManager::compatibleRelease('0.5.8','0.5.7'),'0.5.8 backup compatibility');
    check(BackupManager::compatibleRelease('0.5.6','0.5.7')&&BackupManager::compatibleRelease('0.5.7','0.5.7')&&!BackupManager::compatibleRelease('0.5.7','0.5.6'),'0.5.7 backup compatibility');
    check(BackupManager::compatibleRelease('0.5.5','0.5.6')&&BackupManager::compatibleRelease('0.5.6','0.5.6')&&!BackupManager::compatibleRelease('0.5.6','0.5.5'),'0.5.6 backup compatibility');
    check(BackupManager::compatibleRelease('0.5.4','0.5.5')&&BackupManager::compatibleRelease('0.5.5','0.5.5')&&!BackupManager::compatibleRelease('0.5.5','0.5.4'),'0.5.5 backup compatibility');
    check(BackupManager::compatibleRelease('0.5.3','0.5.4')&&BackupManager::compatibleRelease('0.5.4','0.5.4')&&!BackupManager::compatibleRelease('0.5.4','0.5.3'),'0.5.4 backup compatibility');
    check(BackupManager::compatibleRelease('0.5.2','0.5.3')&&BackupManager::compatibleRelease('0.5.3','0.5.3')&&!BackupManager::compatibleRelease('0.5.3','0.5.2'),'0.5.3 backup compatibility and downgrade refusal');
    check(BackupManager::compatibleRelease('0.5.1','0.5.2')&&BackupManager::compatibleRelease('0.5.2','0.5.2')&&!BackupManager::compatibleRelease('0.5.2','0.5.1'),'0.5.2 backup compatibility and downgrade refusal');
    check(BackupManager::compatibleRelease('0.5.0','0.5.1')&&BackupManager::compatibleRelease('0.5.1','0.5.1')&&!BackupManager::compatibleRelease('0.5.1','0.5.0'),'0.5.1 backup compatibility and downgrade refusal');
    check(BackupManager::compatibleRelease('0.4.10','0.5.0')&&BackupManager::compatibleRelease('0.5.0','0.5.0')&&!BackupManager::compatibleRelease('0.5.0','0.4.10'),'0.5.0 backup compatibility and downgrade refusal');
    check(!BackupManager::compatibleRelease('0.0.8','0.3.0')&&!BackupManager::compatibleRelease('0.3.3','0.3.2')&&!BackupManager::compatibleRelease(null,'0.3.0'),'incompatible restore rejected');
} finally {
    foreach (['CAJEER_ENV_FILE'=>$old,'APP_ENV'=>$env,'APP_URL'=>$url] as $name=>$value) putenv($value===false?$name:$name.'='.$value);
    if (file_exists($tmp.'/config/.env')||is_link($tmp.'/config/.env')) unlink($tmp.'/config/.env'); rmdir($tmp.'/config'); rmdir($tmp);
}
check(BackupManager::compatibleRelease('0.9.0','0.9.7') && BackupManager::compatibleRelease('0.9.1','0.9.7') && BackupManager::compatibleRelease('0.9.2','0.9.7') && BackupManager::expectedPending('0.9.2')===[], 'pre-0.9.7 backup compatibility covers all schema-identical releases');
check(BackupManager::compatibleRelease('0.8.7','0.9.7') && BackupManager::compatibleRelease('0.8.8','0.9.7') && BackupManager::compatibleRelease('0.8.9','0.9.7') && !BackupManager::compatibleRelease('0.8.9','0.8.8'), '0.9.7 backup compatibility and downgrade refusal');
check(BackupManager::compatibleRelease('0.7.2','0.7.3')&&BackupManager::compatibleRelease('0.7.3','0.7.3')&&BackupManager::compatibleRelease('0.7.3','0.7.4')&&BackupManager::compatibleRelease('0.7.4','0.7.6')&&BackupManager::compatibleRelease('0.7.6','0.7.6')&&BackupManager::compatibleRelease('0.7.6','0.7.7')&&BackupManager::compatibleRelease('0.7.7','0.7.8')&&BackupManager::compatibleRelease('0.7.8','0.8.3')&&BackupManager::compatibleRelease('0.7.9','0.8.3')&&BackupManager::compatibleRelease('0.8.0','0.8.3')&&BackupManager::compatibleRelease('0.8.1','0.8.3')&&BackupManager::compatibleRelease('0.8.2','0.8.3')&&BackupManager::compatibleRelease('0.8.3','0.8.4')&&BackupManager::compatibleRelease('0.8.4','0.8.5')&&BackupManager::compatibleRelease('0.8.5','0.8.7')&&BackupManager::compatibleRelease('0.8.7','0.8.8')&&!BackupManager::compatibleRelease('0.7.3','0.7.2'),'0.7.3–0.8.8 upgrade supported and downgrade rejected');
echo "Release runtime: $n checks passed\n";
