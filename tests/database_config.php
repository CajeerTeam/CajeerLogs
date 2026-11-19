<?php
declare(strict_types=1);

use Cajeer\Logs\Config\Environment;
use Cajeer\Logs\Database\ConnectionFactory;
use Cajeer\Logs\Database\DatabaseException;
use Cajeer\Logs\Installer\EnvironmentWriter;
use Cajeer\Logs\Kernel\Application;

$root = dirname(__DIR__);
$app = require $root . '/core/bootstrap.php';
$app->boot();
$count = 0;
function assertConfig(bool $ok, string $label): void {
    global $count;
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
    $count++; echo "PASS $label\n";
}
function rejects(callable $call, string $fragment): bool {
    try { $call(); } catch (DatabaseException $e) { return str_contains($e->getMessage(), $fragment); }
    return false;
}
$config = $app->config();
$factory = new ConnectionFactory($config);
$config->set('database.default','pgsql');
$config->set('database.host','127.0.0.1');
$config->set('database.database','cajeer_logs');
$config->set('database.username','cajeer_logs');
$config->set('database.port','5432');
$config->set('database.password','test-password-only');
$config->set('database.sslmode','disable');
$config->set('database.sslrootcert','');
$config->set('database.connect_timeout','5');
$config->set('database.statement_timeout_ms','30000');
$config->set('database.lock_timeout_ms','10000');
$config->set('database.schema','public');
assertConfig($factory->schema() === 'public', 'valid schema');
assertConfig(rejects(fn()=>$factory->make('mysql'), 'Only PostgreSQL'), 'unsupported database rejected');
$config->set('database.schema','pg_catalog');
assertConfig(rejects(fn()=>$factory->schema(),'Invalid DB_SCHEMA'), 'system schema rejected');
$config->set('database.schema','a"; DROP TABLE users;');
assertConfig(rejects(fn()=>$factory->schema(),'Invalid DB_SCHEMA'), 'schema injection rejected');
$config->set('database.schema','public');
$config->set('database.host','localhost;dbname=other');
assertConfig(rejects(fn()=>$factory->make('pgsql'),'Invalid DB_HOST'), 'DSN option injection rejected');
$config->set('database.host','127.0.0.1');
$config->set('database.port','65536');
assertConfig(rejects(fn()=>$factory->make('pgsql'),'port'), 'invalid port rejected');
$config->set('database.port','5432');
$config->set('database.password','change_me');
assertConfig(rejects(fn()=>$factory->make('pgsql'),'DB_PASSWORD'), 'placeholder password rejected');
$config->set('database.password','test-password-only');
$config->set('database.sslmode','unsafe');
assertConfig(rejects(fn()=>$factory->make('pgsql'),'DB_SSLMODE'), 'invalid SSL mode rejected');
$config->set('database.sslmode','disable');
$config->set('database.connect_timeout','0');
assertConfig(rejects(fn()=>$factory->make('pgsql'),'connect_timeout'), 'invalid connection timeout rejected');
$config->set('database.connect_timeout','5');
if (!in_array('pgsql',PDO::getAvailableDrivers(),true)) {
    assertConfig(rejects(fn()=>$factory->make('pgsql'),'pdo_pgsql'), 'missing PostgreSQL driver fails explicitly');
}
putenv('CAJEER_TEST_RAW=true');
assertConfig(env_raw('CAJEER_TEST_RAW') === 'true' && env('CAJEER_TEST_RAW') === true, 'raw env preserves literal password values');
putenv('CAJEER_TEST_RAW');
$tmp = sys_get_temp_dir() . '/cl-env-test-' . bin2hex(random_bytes(8));
mkdir($tmp,0700); mkdir($tmp . '/config');
try {
    foreach (glob($root . '/config/*.php') as $file) { copy($file,$tmp . '/config/' . basename($file)); }
    copy($root . '/release.json',$tmp . '/release.json');
    copy($root . '/.env.example',$tmp . '/.env.example');
    $test = new Application($tmp); $test->boot();
    $password = 'a\\n"b$#;c' . "\n" . 'end';
    $test->config()->set('database.password',$password);
    $test->config()->set('database.schema','custom_schema');
    $writer = new EnvironmentWriter($test);
    $writer->write();
    $values = Environment::load($tmp . '/.env');
    assertConfig($values['DB_PASSWORD'] === $password && $values['DB_SCHEMA'] === 'custom_schema','dotenv round-trip of escaped credentials');
    assertConfig((fileperms($tmp . '/.env') & 0777) === 0600,'dotenv mode 0600');
    assertConfig(strlen($values['SECURITY_HMAC_SECRET']) >= 64 && $values['SECURITY_HMAC_SECRET'] !== $values['SECURITY_TOKEN_PEPPER'],'independent generated secrets');
    file_put_contents($tmp . '/.env', "\nCUSTOM_SETTING=preserved\n", FILE_APPEND);
    $test2 = new Application($tmp); $test2->boot();
    // Keep the persisted database target on retry despite inherited CI DB_SCHEMA.
    $test2->config()->set('database.schema', $values['DB_SCHEMA']);
    (new EnvironmentWriter($test2))->write();
    $retry = Environment::load($tmp . '/.env');
    assertConfig($retry['SECURITY_HMAC_SECRET'] === $values['SECURITY_HMAC_SECRET'] && $retry['CUSTOM_SETTING'] === 'preserved','retry preserves existing secrets and custom settings');
    unlink($tmp . '/.env');
    symlink($tmp . '/.env.example',$tmp . '/.env');
    assertConfig(rejects(fn()=>(new EnvironmentWriter($test2))->write(),'symlinked'),'symlinked environment rejected');
} finally {
    if (is_file($tmp . '/.env') || is_link($tmp . '/.env')) { unlink($tmp . '/.env'); }
    foreach (glob($tmp . '/config/*.php') as $file) { unlink($file); }
    rmdir($tmp . '/config'); unlink($tmp . '/release.json'); unlink($tmp . '/.env.example'); rmdir($tmp);
}
echo "Database configuration: $count checks passed\n";
