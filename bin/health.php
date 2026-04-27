#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use CajeerLogs\Database;
use CajeerLogs\Logger;

$payload = [
    'ok' => true,
    'time' => gmdate('c'),
    'diagnostics' => Database::diagnostics(),
];

try {
    Database::pdo()->query('SELECT 1');
    $payload['driver'] = Database::driver();
    $payload['database'] = 'available';
} catch (Throwable $e) {
    Logger::error('CLI health database failure', ['exception' => $e]);
    $payload['ok'] = false;
    $payload['database'] = 'unavailable';
    $payload['error'] = $e->getMessage();
}

echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit($payload['ok'] ? 0 : 1);
