<?php
declare(strict_types=1);

use Cajeer\Logs\Config\Environment;
use Cajeer\Logs\Database\ConnectionFactory;
use Cajeer\Logs\Database\DatabaseException;
use Cajeer\Logs\Database\DatabaseStatus;
use Cajeer\Logs\Database\MigrationRunner;
use Cajeer\Logs\Database\Repository\ProjectRepository;
use Cajeer\Logs\Database\Repository\SourceRepository;
use Cajeer\Logs\Database\Repository\TokenRepository;
use Cajeer\Logs\Database\Repository\UserRepository;
use Cajeer\Logs\Http\Request;
use Cajeer\Logs\Kernel\Application;

// Real PostgreSQL only. No mocks; isolate tests in a randomly named schema.
if (getenv('CAJEER_TEST_DATABASE') !== '1') { fwrite(STDERR, "Set CAJEER_TEST_DATABASE=1 and DB_* for a disposable PostgreSQL database.\n"); exit(1); }
$root = dirname(__DIR__);
$app = require $root . '/core/bootstrap.php';
$app->boot();
$base = $app->container()->get(PDO::class);
$schema = 'cl_test_' . bin2hex(random_bytes(8));
$base->exec('CREATE SCHEMA "' . $schema . '"');
$tmp = sys_get_temp_dir() . '/cl-db-test-' . bin2hex(random_bytes(8));
mkdir($tmp, 0700);
$count = 0;
function verify(bool $value, string $name): void {
    global $count;
    if (!$value) { throw new RuntimeException('FAIL ' . $name); }
    $count++; echo "PASS $name\n";
}
function fails(callable $call, string $class): bool {
    try { $call(); } catch (Throwable $e) { return $e instanceof $class; }
    return false;
}
function command(array $args, array $extra = []): array {
    global $tmp;
    $p = proc_open([PHP_BINARY, $tmp . '/bin/cajeer', ...$args], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, $tmp, array_merge(getenv(), $extra));
    if (!is_resource($p)) { throw new RuntimeException('Unable to run CLI'); }
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p), $out . $err];
}
function removeTree(string $directory): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($directory);
}
try {
    // Copy only runtime files to exercise installer without modifying checkout secrets.
    foreach (['core','config','bin','migrations','public'] as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        mkdir($tmp . '/' . $dir);
        foreach ($iterator as $item) {
            $dest = $tmp . substr($item->getPathname(), strlen($root));
            $item->isDir() ? mkdir($dest) : copy($item->getPathname(), $dest);
        }
    }
    mkdir($tmp . '/storage');
    foreach (['release.json','.env.example'] as $file) { copy($root . '/' . $file, $tmp . '/' . $file); }
    $config = $app->config();
    $config->set('database.schema', $schema);
    $factory = new ConnectionFactory($config);
    $pdo = $factory->make('pgsql');
    $runner = new MigrationRunner($pdo, $root . '/migrations/pgsql', $schema);
    verify(count($runner->status()['pending']) === 8, 'fresh schema reports pending migrations');
    verify($pdo->query("SELECT to_regclass('schema_migrations') IS NULL")->fetchColumn() === true, 'status is read-only');
    $env = ['DB_SCHEMA'=>$schema, 'CAJEER_ADMIN_EMAIL'=>'admin@example.test', 'CAJEER_ADMIN_PASSWORD'=>'test-only-admin-password-123', 'APP_ENV'=>'test', 'APP_DEBUG'=>'false'];
    $testApp = new Application($tmp);
    $testApp->boot();
    $testApp->config()->set('database.schema', $schema);
    $testApp->config()->set('app.env', 'test');
    verify($testApp->handle(new Request('GET','/ready'))->status === 503, 'readiness down before migrations');
    [$code, $output] = command(['install','--non-interactive'], $env);
    if ($code !== 0) { throw new RuntimeException('Installer failed: ' . $output); }
    verify($code === 0 && str_contains($output,'Administrator created'), 'CLI installer creates first administrator');
    $values = Environment::load($tmp . '/.env');
    verify(strlen($values['SECURITY_HMAC_SECRET']) >= 64 && $values['SECURITY_HMAC_SECRET'] !== $values['SECURITY_TOKEN_PEPPER'] && !isset($values['CAJEER_ADMIN_PASSWORD']), 'unique generated secrets, no admin password in dotenv');
    verify((fileperms($tmp . '/.env') & 0777) === 0600, 'dotenv private permissions');
    verify($values['DB_SCHEMA'] === $schema && $values['DB_PASSWORD'] === $config->get('database.password'), 'effective DB configuration persisted');
    verify($runner->status()['pending'] === [] && count($runner->status()['applied']) === 8, 'all migration checksums recorded');
    verify($runner->migrate() === [], 'migration rerun is a no-op');
    verify($testApp->handle(new Request('GET','/ready'))->status === 200, 'readiness healthy after installation');
    $users = new UserRepository($pdo);
    $admin = $users->findByEmail('ADMIN@example.test');
    verify($admin !== null && $admin['role'] === 'administrator' && password_verify($env['CAJEER_ADMIN_PASSWORD'], $admin['password_hash']) && password_get_info($admin['password_hash'])['algoName'] === 'argon2id', 'administrator stored with Argon2id');
    [$code, $output] = command(['install','--non-interactive'], $env);
    verify($code === 0 && str_contains($output,'already exists') && Environment::load($tmp . '/.env')['SECURITY_HMAC_SECRET'] === $values['SECURITY_HMAC_SECRET'], 'installer retry preserves admin and secrets');
    verify(command(['install','--non-interactive'], array_replace($env,['CAJEER_ADMIN_PASSWORD'=>'different-admin-password-456']))[0] !== 0, 'installer refuses password reset');
    verify(command(['install','--non-interactive'], array_replace($env,['CAJEER_ADMIN_EMAIL'=>'other@example.test']))[0] !== 0, 'installer refuses second initial admin');
    verify(command(['migrate:status'], $env)[0] === 0 && command(['db:doctor'],$env)[0] === 0 && command(['doctor'],$env)[0] === 0, 'CLI status and diagnostics');
    $projects = new ProjectRepository($pdo); $sources = new SourceRepository($pdo); $tokens = new TokenRepository($pdo);
    $p1 = $projects->create('First', 'first'); $p2 = $projects->create('Second','second');
    $source = $sources->create($p1,'Application','app');
    verify($projects->find($p1)['slug'] === 'first' && $sources->find($p1,$source)['slug'] === 'app' && $sources->find($p2,$source) === null, 'project and scoped source repository');
    $token = $tokens->create('writer',['logs:write'],$p1,$source);
    verify($tokens->findActive($token['token'])['id'] === $token['id'], 'token lookup by hash');
    $hash = $pdo->query('SELECT token_hash FROM api_tokens')->fetchColumn();
    verify($hash === hash('sha256',$token['token']) && $hash !== $token['token'], 'plaintext token not stored');
    verify(fails(fn() => $tokens->create('cross',['logs:write'],$p2,$source), PDOException::class), 'cross-project token FK rejection');
    verify($tokens->revoke($token['id']) && !$tokens->revoke($token['id']) && $tokens->findActive($token['token']) === null, 'token revocation');
    $expired = $tokens->create('expires',['logs:read'],$p1,null,new DateTimeImmutable('+1 hour'));
    $expire = $pdo->prepare("UPDATE api_tokens SET expires_at = now() - interval '1 second' WHERE id = :id"); $expire->execute(['id'=>$expired['id']]);
    verify($tokens->findActive($expired['token']) === null, 'expired token rejected');
    // Test actual rollback: first statement succeeds, second fails. Neither schema nor history may change.
    $dir = $tmp . '/migrations/pgsql';
    file_put_contents($dir . '/0009_failure.sql', 'CREATE TABLE rollback_probe(id integer); SELECT * FROM nonexistent_table;');
    $testRunner = new MigrationRunner($pdo,$dir,$schema);
    verify(fails(fn() => $testRunner->migrate(), DatabaseException::class), 'broken migration fails');
    verify($pdo->query("SELECT to_regclass('rollback_probe') IS NULL")->fetchColumn() === true && count($runner->status()['applied']) === 8, 'failed migration rolled back including history');
    unlink($dir . '/0009_failure.sql');
    file_put_contents($dir . '/0001_initial_schema.sql', "\n-- tampered\n", FILE_APPEND);
    verify(fails(fn() => $testRunner->status(), DatabaseException::class), 'checksum drift rejected');
    copy($root . '/migrations/pgsql/0001_initial_schema.sql',$dir . '/0001_initial_schema.sql');
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))'); $stmt->execute(['key'=>'cajeerlogs:migrations:' . $schema]);
    $second = $factory->make('pgsql'); $second->exec('SET lock_timeout = 100');
    $parallel = new MigrationRunner($second,$dir,$schema);
    verify(fails(fn() => $parallel->migrate(), DatabaseException::class), 'concurrent migration lock prevents overlap');
    $pdo->rollBack();
    verify($parallel->migrate() === [], 'migration lock released after transaction');
    $pdo->exec('DROP TABLE settings');
    verify($testApp->handle(new Request('GET','/ready'))->status === 503, 'readiness detects missing migrated table');
    echo "Database integration: $count checks passed\n";
} finally {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    $base->exec('DROP SCHEMA "' . $schema . '" CASCADE');
    removeTree($tmp);
}
