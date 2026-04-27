<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use CajeerLogs\Database;
use CajeerLogs\Repository;

$days = 30;
$source = 'all';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--days=')) {
        $days = max(1, (int)substr($arg, 7));
    }
    if (str_starts_with($arg, '--source=')) {
        $source = substr($arg, 9) ?: 'all';
    }
}

$repo = new Repository(Database::pdo());
$result = $repo->archiveLogsOlderThan($days, $source);
$repo->recordCronRun('archive-retention', 'ok', 'archive-retention finished', $result, microtime(true));
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
