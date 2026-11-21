<?php
declare(strict_types=1);
/** Installer upgrade safety/unit tests. Database execution is tested in upgrade_release.py. */
use Cajeer\Logs\Database\DatabaseException;
use Cajeer\Logs\Installer\{Installer,UpgradePreflight};
use Cajeer\Logs\Kernel\Application;
require dirname(__DIR__).'/core/bootstrap.php';
$n=0;
function ok(bool $value,string $label): void { global $n; if (!$value) throw new RuntimeException('FAIL: '.$label); ++$n; echo 'PASS '.$label.PHP_EOL; }
function throws(callable $f): bool { try { $f(); } catch (DatabaseException) {return true;} return false; }
$full=['0001.sql','0002.sql','0003.sql'];
ok(UpgradePreflight::pendingMatches($full,$full,false),'pristine verified migration list');
ok(!UpgradePreflight::pendingMatches($full,['0002.sql','0003.sql'],false),'dry-run refuses partially migrated history');
ok(UpgradePreflight::pendingMatches($full,['0002.sql','0003.sql'],true),'resume permits only an applied prefix');
ok(UpgradePreflight::pendingMatches($full,['0003.sql'],true),'resume permits later checkpoint');
ok(UpgradePreflight::pendingMatches($full,[],true),'complete upgrade can be retried');
ok(!UpgradePreflight::pendingMatches($full,['0001.sql'],true),'resume rejects out-of-order migration history');
ok(!UpgradePreflight::pendingMatches($full,['0002.sql'],true),'resume rejects missing intermediate migration');
ok(!UpgradePreflight::pendingMatches($full,['wrong.sql'],true),'resume rejects unknown migration');
ok(!UpgradePreflight::pendingMatches([],['0003.sql'],true),'upgrade cannot introduce unexpected migration');
ok(UpgradePreflight::pendingMatches([],[],true),'schema-identical upgrade can be retried');
$tmp=sys_get_temp_dir().'/cl-upgrade-lock-'.bin2hex(random_bytes(8)); mkdir($tmp,0700);
try {
    $path=$tmp.'/.install.lock';
    $lock=Installer::lockFile($path);
    ok((fileperms($path)&0777)===0600,'shared installer lock created with private permissions');
    ok(throws(fn()=>Installer::lockFile($path)),'simultaneous local upgrade refused');
    flock($lock,LOCK_UN); fclose($lock);
    $lock=Installer::lockFile($path);
    ok(is_resource($lock),'upgrade lock safely reacquired after interruption');
    flock($lock,LOCK_UN); fclose($lock);
    unlink($path);
    symlink('/etc/passwd',$path);
    ok(throws(fn()=>Installer::lockFile($path)),'symlinked lock refused');
    unlink($path);
} finally { if (file_exists($tmp.'/.install.lock') || is_link($tmp.'/.install.lock')) unlink($tmp.'/.install.lock'); rmdir($tmp); }
$root=dirname(__DIR__);
$productionSmoke=file_get_contents($root.'/tests/production_smoke.py');
ok(is_string($productionSmoke)
    && substr_count($productionSmoke,'postgres_admin(')===4
    && str_contains($productionSmoke,'PGPASSWORD="$POSTGRES_PASSWORD" exec psql -X -w')
    && !str_contains($productionSmoke,"'db','psql'"),
    'production smoke always authenticates privileged PostgreSQL probes through SCRAM without exposing passwords');
$socketConfig=new \Cajeer\Logs\Config\ConfigRepository([
    'app'=>['env'=>'production','url'=>'https://logs.example.test','debug'=>false],
    'security'=>['require_admin_totp'=>true,'totp_key'=>str_repeat('a',64),
        'session_idle_seconds'=>1800,'reauth_seconds'=>300],
    'observability'=>['prometheus_enabled'=>true],
    'operations'=>['metrics_token'=>str_repeat('b',32)],
    'database'=>['host'=>'/var/run/postgresql','require_tls'=>false,
        'sslmode'=>'disable','sslrootcert'=>'']
]);
ok(\Cajeer\Logs\Security\SecurityPosture::configuration($socketConfig)['remote_database_tls']===true,
   'local PostgreSQL Unix socket meets private-database security posture');
$socketConfig->set('database.host','db');
ok(\Cajeer\Logs\Security\SecurityPosture::configuration($socketConfig)['remote_database_tls']===false,
   'unencrypted remote PostgreSQL transport remains blocked');
$productionCompose=file_get_contents($root.'/compose.production.yaml');
ok(is_string($productionCompose)
    && str_contains($productionCompose,'DB_HOST: /var/run/postgresql')
    && str_contains($productionCompose,'- pgsocket:/var/run/postgresql:ro')
    && str_contains($productionCompose,'- pgsocket:/var/run/postgresql')
    && str_contains($productionCompose,"POSTGRES_INITDB_ARGS: '--auth-local=scram-sha-256 --auth-host=scram-sha-256'"),
    'production Compose requires shared private socket and password-authenticated local PostgreSQL');
// A bad production origin is rejected before backup checks or database writes.
$settings=[];
foreach (['CAJEER_ENV_FILE','APP_ENV','APP_URL'] as $name) { $settings[$name]=getenv($name); }
$conf=sys_get_temp_dir().'/cl-upgrade-preflight-'.bin2hex(random_bytes(8)); mkdir($conf,0700);
try {
    putenv('CAJEER_ENV_FILE='.$conf.'/.env'); putenv('APP_ENV=production'); putenv('APP_URL=http://logs.example.test');
    $app=new Application($root); $app->boot();
    try { (new UpgradePreflight($app))->check('bad-backup-id'); $rejected=false; }
    catch (DatabaseException $error) { $rejected=str_contains($error->getMessage(),'https_origin'); }
    ok($rejected && !file_exists($conf.'/.env') && !file_exists($conf.'/.env.install.lock'),
       'unsafe production origin rejected without backup or configuration writes');
} finally {
    foreach ($settings as $name=>$value) putenv($value===false?$name:$name.'='.$value);
    rmdir($conf);
}
$command=static function(array $args) use ($root): array {
    $proc=proc_open([PHP_BINARY,$root.'/bin/cajeer',...$args],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($proc)) throw new RuntimeException('Cannot launch CLI');
    $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($proc),$stdout.$stderr];
};
[$code,$out]=$command(['upgrade']);
ok($code!==0 && str_contains($out,'Usage: upgrade'),'CLI requires backup ID and explicit stop acknowledgement');
[$code,$out]=$command(['upgrade','backup-20260101T000000Z-0123456789abcdef','--wrong']);
ok($code!==0 && str_contains($out,'Usage: upgrade'),'CLI rejects unapproved upgrade arguments before connecting');
echo "Production installer: $n checks passed\n";
