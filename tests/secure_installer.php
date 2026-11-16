<?php
declare(strict_types=1);
use Cajeer\Logs\Config\{Environment,SecretFile};
use Cajeer\Logs\Database\DatabaseException;
use Cajeer\Logs\Installer\{EnvironmentWriter,InstallPreflight,SecureFile};
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Operations\BackupManager;
$root=dirname(__DIR__); require $root.'/core/bootstrap.php';
$n=0;
function check(bool $ok,string $label): void { global $n; if (!$ok) throw new RuntimeException('FAIL '.$label); ++$n; echo "PASS $label\n"; }
function rejects(callable $f,string $fragment): bool { try {$f();} catch (Throwable $e) {return str_contains($e->getMessage(),$fragment);} return false; }
$tmp=sys_get_temp_dir().'/cl-secure-install-'.bin2hex(random_bytes(8)); mkdir($tmp,0700);
$names=['CAJEER_ENV_FILE','APP_ENV','APP_URL','DB_PASSWORD','DB_PASSWORD_FILE','SECURITY_HMAC_SECRET','SECURITY_HMAC_SECRET_FILE','SECURITY_TOTP_KEY','SECURITY_TOTP_KEY_FILE','SECURITY_TOTP_ACTIVE_KEY','SECURITY_TOTP_ACTIVE_KEY_FILE','SECURITY_TOTP_PREVIOUS_KEY','SECURITY_TOTP_PREVIOUS_KEY_FILE','SECURITY_TOKEN_PEPPER','SECURITY_TOKEN_PEPPER_FILE','SECURITY_PRIVACY_HASH_PEPPER','SECURITY_PRIVACY_HASH_PEPPER_FILE','WEBHOOK_SIGNING_SECRET','WEBHOOK_SIGNING_SECRET_FILE','PROMETHEUS_TOKEN','PROMETHEUS_TOKEN_FILE','DB_MIGRATION_PASSWORD_FILE'];
$old=[]; foreach($names as $name){$old[$name]=getenv($name);putenv($name);}
try {
    $file=$tmp.'/db'; $secret='test-db-secret-'.bin2hex(random_bytes(16)); file_put_contents($file,$secret."\r\n"); chmod($file,0600);
    $hmac=$tmp.'/hmac'; $key=bin2hex(random_bytes(32)); file_put_contents($hmac,$key."\n"); chmod($hmac,0600);
    check(SecretFile::read($file)===$secret,'secret file strips one line ending');
    chmod($file,0644); check(rejects(fn()=>SecretFile::read($file),'private regular file'),'reject public file'); chmod($file,0600);
    symlink($file,$tmp.'/symlink'); check(rejects(fn()=>SecretFile::read($tmp.'/symlink'),'private regular file'),'reject symlink'); unlink($tmp.'/symlink');
    link($file,$tmp.'/hardlink'); check(rejects(fn()=>SecretFile::read($file),'hard link'),'reject hardlink'); unlink($tmp.'/hardlink');
    check(rejects(fn()=>SecretFile::read('db'),'absolute'),'reject relative path');
    file_put_contents($tmp.'/empty',''); chmod($tmp.'/empty',0600); check(rejects(fn()=>SecretFile::read($tmp.'/empty'),'empty'),'reject empty secret');
    file_put_contents($tmp.'/empty',str_repeat('a',4097)); check(rejects(fn()=>SecretFile::read($tmp.'/empty'),'4096'),'reject oversize secret');
    file_put_contents($tmp.'/empty',"bad\0value"); check(rejects(fn()=>SecretFile::read($tmp.'/empty'),'Invalid secret'),'reject NUL');
    putenv('DB_PASSWORD_FILE='.$file); putenv('DB_PASSWORD=conflict'); check(rejects(fn()=>SecretFile::raw('DB_PASSWORD',[]),'not both'),'reject ambiguous source'); putenv('DB_PASSWORD');
    putenv('CAJEER_ENV_FILE='.$tmp.'/.env'); putenv('APP_ENV=local'); putenv('APP_URL=http://localhost:8080'); putenv('SECURITY_HMAC_SECRET_FILE='.$hmac);
    $app=new Application($root); $app->boot();
    check($app->config()->get('database.password')===$secret && $app->config()->get('security.totp_key')===$key,'actual runtime resolves file-backed DB and TOTP fallback');
    (new EnvironmentWriter($app))->write(); $values=Environment::load($tmp.'/.env'); $body=file_get_contents($tmp.'/.env');
    check(($values['DB_PASSWORD_FILE']??null)===$file && ($values['SECURITY_HMAC_SECRET_FILE']??null)===$hmac && !isset($values['DB_PASSWORD'],$values['SECURITY_HMAC_SECRET']) && !str_contains($body,$secret) && !str_contains($body,$key),'installer persists references without secret values');
    putenv('DB_PASSWORD_FILE'); putenv('SECURITY_HMAC_SECRET_FILE'); $fresh=new Application($root); $fresh->boot();
    check($fresh->config()->get('database.password')===$secret && $fresh->config()->get('security.totp_key')===$key,'fresh boot resolves persisted refs without injected env');
    file_put_contents($tmp.'/.env',"\n# retained comment\n",FILE_APPEND); $body=file_get_contents($tmp.'/.env'); $inode=fileinode($tmp.'/.env');
    (new EnvironmentWriter($fresh))->write(); clearstatcache(true,$tmp.'/.env'); check(file_get_contents($tmp.'/.env')===$body && fileinode($tmp.'/.env')===$inode,'unchanged retry preserves original file and comments');
    chmod($tmp.'/.env',0644); check(rejects(fn()=>(new EnvironmentWriter($fresh))->write(),'private'),'reject insecure existing dotenv'); chmod($tmp.'/.env',0600);
    chmod($tmp,0777); check(!SecureFile::directory($tmp),'reject writable-by-others configuration directory'); chmod($tmp,0700);
    putenv('DB_PASSWORD_FILE='.$hmac); check(rejects(fn()=>(new EnvironmentWriter($fresh))->write(),'differs') && file_get_contents($tmp.'/.env')===$body,'refuse retargeted password file before write'); putenv('DB_PASSWORD_FILE');
    $fresh->config()->set('database.password','change_me'); $pre=(new InstallPreflight($fresh))->report();
    check($pre['status']==='failed' && $pre['checks']['database_ready']===false && file_get_contents($tmp.'/.env')===$body && !file_exists($tmp.'/.env.install.lock'),'failed dry-run leaves config and locks unchanged');
    check(!str_contains(json_encode($pre),$secret) && !str_contains(json_encode($pre),$key),'preflight does not expose secrets');
    $fresh->config()->set('operations.backup_dir',$tmp.'/missing-backups');
    check(rejects(fn()=>(new BackupManager($fresh))->verify('backup-20260101T000000Z-0123456789abcdef'),'backup directory')&&!file_exists($tmp.'/missing-backups'),'backup verification does not create missing directory');
} finally { foreach($old as $name=>$value) putenv($value===false?$name:$name.'='.$value); foreach(scandir($tmp) as $name) if($name!=='.'&&$name!=='..') unlink($tmp.'/'.$name); rmdir($tmp); }
echo "Secure installer: $n checks passed\n";
