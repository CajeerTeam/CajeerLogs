<?php
declare(strict_types=1);

use Cajeer\Logs\Database\Repository\UserRepository;
use Cajeer\Logs\Database\MigrationRunner;
use Cajeer\Logs\Kernel\Application;

// Disposable schema fixture for the browser E2E. Never point at a live installation.
if (getenv('CAJEER_TEST_DATABASE') !== '1' || $argc !== 3 || !in_array($argv[1], ['setup','cleanup'], true) || !preg_match('/^cl_admin_[a-f0-9]{16}$/D', $argv[2])) {
    fwrite(STDERR, "Set CAJEER_TEST_DATABASE=1 and isolated DB_*; usage: setup|cleanup cl_admin_<16 hex>\n");
    exit(1);
}
$root = dirname(__DIR__);
$app = require $root . '/core/bootstrap.php';
$app->boot();
$schema = $argv[2];
$base = $app->container()->get(PDO::class);
if ($argv[1] === 'cleanup') {
    $base->exec('DROP SCHEMA IF EXISTS "' . $schema . '" CASCADE');
    exit(0);
}
$base->exec('CREATE SCHEMA "' . $schema . '"');
try {
    $fixture = new Application($root);
    $fixture->boot();
    $fixture->config()->set('database.schema', $schema);
    $fixture->container()->get(MigrationRunner::class)->migrate();
    $fixture->container()->get(UserRepository::class)->create('admin@example.test', 'admin-password-12345', 'administrator');
    echo "Admin browser fixture ready\n";
} catch (Throwable $e) {
    $base->exec('DROP SCHEMA IF EXISTS "' . $schema . '" CASCADE');
    throw $e;
}
