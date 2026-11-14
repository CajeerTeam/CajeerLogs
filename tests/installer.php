<?php
declare(strict_types=1);
use Cajeer\Logs\Installer\{AdministratorPassword,EnvironmentWriter,InstallPreflight,InstallationStatus,SchemaPreparation};
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Config\Environment;
use Cajeer\Logs\Database\DatabaseException;
$root=dirname(__DIR__); require $root.'/core/bootstrap.php';
$n=0;
function check(bool $ok,string $label): void { global $n; if (!$ok) throw new RuntimeException('FAIL '.$label); ++$n; echo "PASS $label\n"; }
function rejects(callable $f,string $message): bool { try {$f();} catch (DatabaseException $e) {return str_contains($e->getMessage(),$message);} return false; }
$tmp=sys_get_temp_dir().'/cl-install-'.bin2hex(random_bytes(8)); mkdir($tmp,0700);
$names=['CAJEER_ENV_FILE','CAJEER_ADMIN_PASSWORD','CAJEER_ADMIN_PASSWORD_FILE','CAJEER_ADMIN_NEW_PASSWORD','CAJEER_ADMIN_NEW_PASSWORD_FILE','SECURITY_HMAC_SECRET','SECURITY_TOTP_KEY','APP_ENV','APP_URL'];
$previous=[]; foreach($names as $name) { $previous[$name]=getenv($name); putenv($name); }
try {
    $file=$tmp.'/password'; file_put_contents($file,"a private password \r\n"); chmod($file,0600);
    putenv('CAJEER_ADMIN_PASSWORD_FILE='.$file);
    check(AdministratorPassword::supplied('CAJEER_ADMIN_PASSWORD','CAJEER_ADMIN_PASSWORD_FILE')==='a private password ','private file trims one terminal newline only');
    putenv('CAJEER_ADMIN_PASSWORD=another-password');
    check(rejects(fn()=>AdministratorPassword::supplied('CAJEER_ADMIN_PASSWORD','CAJEER_ADMIN_PASSWORD_FILE'),'not both'),'ambiguous secret input rejected');
    putenv('CAJEER_ADMIN_PASSWORD'); chmod($file,0644); clearstatcache(true,$file);
    check(rejects(fn()=>AdministratorPassword::supplied('CAJEER_ADMIN_PASSWORD','CAJEER_ADMIN_PASSWORD_FILE'),'private'),'world-readable password file rejected');
    chmod($file,0600); symlink($file,$tmp.'/link'); putenv('CAJEER_ADMIN_PASSWORD_FILE='.$tmp.'/link');
    check(rejects(fn()=>AdministratorPassword::supplied('CAJEER_ADMIN_PASSWORD','CAJEER_ADMIN_PASSWORD_FILE'),'not a symlink'),'password symlink rejected');
    putenv('CAJEER_ADMIN_PASSWORD_FILE='.$file); file_put_contents($file,str_repeat('x',1027)); clearstatcache(true,$file);
    check(rejects(fn()=>AdministratorPassword::supplied('CAJEER_ADMIN_PASSWORD','CAJEER_ADMIN_PASSWORD_FILE'),'1026'),'oversize file rejected');
    putenv('CAJEER_ADMIN_PASSWORD_FILE');
    check(rejects(fn()=>AdministratorPassword::validate('short'),'12–1024'),'short password rejected');
    check(rejects(fn()=>AdministratorPassword::validate("password\0enough"),'12–1024'),'NUL password rejected');
    AdministratorPassword::validate('valid-password-123'); check(true,'valid password accepted');
    putenv('CAJEER_ENV_FILE='.$tmp.'/.env'); putenv('APP_ENV=local'); putenv('APP_URL=http://localhost:8080');
    $app=new Application($root); $app->boot(); $app->config()->set('database.password','installer-test-db-password');
    $app->config()->set('database.schema','public');
    $writer=new EnvironmentWriter($app); $writer->write();
    $original=file_get_contents($tmp.'/.env'); $values=Environment::load($tmp.'/.env');
    check(!array_intersect(['CAJEER_ADMIN_PASSWORD_FILE','CAJEER_ADMIN_PASSWORD','CAJEER_ADMIN_NEW_PASSWORD'],array_keys($values)),'password and file path not persisted');
    $app->config()->set('database.database','other_database');
    check(rejects(fn()=>$writer->write(),'existing database target')&&file_get_contents($tmp.'/.env')===$original,'installer refuses retarget without touching file');
    $app->config()->set('database.database',$values['DB_DATABASE']);
    putenv('SECURITY_HMAC_SECRET='.bin2hex(random_bytes(32)));
    check(rejects(fn()=>$writer->write(),'rotate an existing secret')&&file_get_contents($tmp.'/.env')===$original,'secret override rejected atomically');
    putenv('SECURITY_HMAC_SECRET');
    putenv('SECURITY_TOTP_KEY=invalid');
    check(rejects(fn()=>$writer->write(),'Invalid TOTP')&&file_get_contents($tmp.'/.env')===$original,'invalid TOTP key rejected before write');
    putenv('SECURITY_TOTP_KEY');
    check(rejects(fn()=>$writer->write(fn()=>throw new DatabaseException('validation failure')),'validation failure')&&file_get_contents($tmp.'/.env')===$original,'candidate validation failure preserves config');
    $writer->write(); check(Environment::load($tmp.'/.env')['SECURITY_HMAC_SECRET']===$values['SECURITY_HMAC_SECRET'],'retry preserves secret');
    check(rejects(fn()=>(new SchemaPreparation($app))->run(),'non-public'),'schema preparation rejects public schema');
    $migrationUser=getenv('DB_MIGRATION_USERNAME'); $migrationPassword=getenv('DB_MIGRATION_PASSWORD');
    putenv('DB_MIGRATION_USERNAME'); putenv('DB_MIGRATION_PASSWORD');
    $app->config()->set('database.schema','dedicated_test');
    try { check(rejects(fn()=>(new SchemaPreparation($app))->run(),'explicit migration credentials'),'schema preparation requires explicit migration role'); }
    finally { putenv($migrationUser===false?'DB_MIGRATION_USERNAME':'DB_MIGRATION_USERNAME='.$migrationUser); putenv($migrationPassword===false?'DB_MIGRATION_PASSWORD':'DB_MIGRATION_PASSWORD='.$migrationPassword); $app->config()->set('database.schema','public'); }
    $checks=(new InstallPreflight($app))->checks();
    check($checks['configuration_directory_writable'] && $checks['configuration_disk_space'] && $checks['argon2id'],'installer preflight evaluates real filesystem and PHP');
    $app->config()->set('app.env','production'); $app->config()->set('app.url','http://localhost:8080');
    check(!(new InstallPreflight($app))->checks()['https_origin'],'production HTTP rejected in preflight');
    // Even on CI with a real driver, invalid configuration must produce a non-ready report without secrets.
    $app->config()->set('database.password','change_me');
    $report=(new InstallationStatus($app))->report();
    check($report['state']==='database_unavailable' && !str_contains(json_encode($report),'change_me'),'status fails closed without leaking configuration');
} finally {
    foreach($previous as $name=>$value) putenv($value===false?$name:$name.'='.$value);
    foreach([$tmp.'/link',$tmp.'/password',$tmp.'/.env'] as $f) if(is_link($f)||is_file($f)) unlink($f); rmdir($tmp);
}
echo "Installer: $n checks passed\n";
