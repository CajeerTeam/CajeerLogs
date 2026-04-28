#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use CajeerLogs\Database;
use CajeerLogs\Migrator;
use CajeerLogs\Repository;

$started = microtime(true);
$repo = null;
try {
    $migrator = new Migrator(Database::pdo());
    $migrator->run();
    $repo = new Repository(Database::pdo());
    $deliveries = $repo->evaluateAlertRules();
    foreach ($deliveries as $delivery) {
        echo '[' . $delivery['status'] . '] ' . $delivery['rule'] . ' count=' . $delivery['count'] . PHP_EOL;
    }
    if (!$deliveries) {
        echo "Правила оповещений не сработали.\n";
    }
    $repo->recordCronRun('alert-dispatch', 'ok', 'processed=' . count($deliveries), ['deliveries' => $deliveries], $started);
} catch (Throwable $e) {
    if ($repo) {
        $repo->recordCronRun('alert-dispatch', 'failed', $e->getMessage(), [], $started);
    }
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
