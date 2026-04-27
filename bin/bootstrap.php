<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use CajeerLogs\Auth;
use CajeerLogs\Database;
use CajeerLogs\Env;
use CajeerLogs\Migrator;
use CajeerLogs\Repository;

function out(string $message): void { fwrite(STDOUT, $message . PHP_EOL); }

$root = dirname(__DIR__);
out('[bootstrap] Cajeer Logs production bootstrap');
out('[bootstrap] root: ' . $root);

if (!is_file($root . '/.env')) {
    out('[bootstrap] .env not found. Create it before running bootstrap. This production archive does not generate secrets automatically.');
    exit(2);
}

foreach (['storage/logs', 'storage/cache', 'storage/archives'] as $dir) {
    $path = $root . '/' . $dir;
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
    out('[bootstrap] ' . $dir . ': ' . (is_writable($path) ? 'writable' : 'not writable'));
}

out('[bootstrap] DB connection: ' . Env::get('DB_CONNECTION', 'sqlite'));
out('[bootstrap] PDO drivers: ' . implode(', ', PDO::getAvailableDrivers()));

$pdo = Database::pdo();
(new Migrator($pdo))->run();
out('[bootstrap] migrations: ok');

Auth::seedAdminIfEmpty($pdo);
out('[bootstrap] admin seed: checked');

$repo = new Repository($pdo);
$report = $repo->dbOwnershipReport();
$bad = array_filter($report, static fn(array $row): bool => isset($row['ok']) && !$row['ok']);
out('[bootstrap] DB ownership: ' . ($bad ? 'needs fix, open /system/database' : 'ok'));

out('[bootstrap] cron commands:');
out('cd ' . $root . ' && ' . PHP_BINARY . ' bin/process-jobs.php >/dev/null 2>&1');
out('cd ' . $root . ' && ' . PHP_BINARY . ' bin/alert-dispatch.php >/dev/null 2>&1');
out('cd ' . $root . ' && ' . PHP_BINARY . ' bin/import-aapanel-logs.php --max-lines=1000 >/dev/null 2>&1');
out('cd ' . $root . ' && ' . PHP_BINARY . ' bin/retention.php >/dev/null 2>&1');

out('[bootstrap] done');
