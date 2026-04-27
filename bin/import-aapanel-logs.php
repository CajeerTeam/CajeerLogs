#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use CajeerLogs\AaPanelLogImporter;
use CajeerLogs\Database;
use CajeerLogs\Env;
use CajeerLogs\Repository;

$options = getopt('', ['site::', 'max-lines::', 'dir::']);
$site = isset($options['site']) ? trim((string)$options['site']) : null;
$maxLines = isset($options['max-lines']) ? max(1, min(10000, (int)$options['max-lines'])) : Env::int('AAPANEL_LOG_IMPORT_MAX_LINES', 1000);
$dir = isset($options['dir']) ? (string)$options['dir'] : Env::get('AAPANEL_LOG_DIR', '/www/wwwlogs');

$started = microtime(true);
$repo = null;
try {
    $repo = new Repository(Database::pdo());
    $summary = (new AaPanelLogImporter($repo))->importAll($dir, $site !== '' ? $site : null, $maxLines);
    $repo->audit('aapanel_logs.imported_cli', 'aapanel_site', $site ?: 'all', 'Импортированы логи сайтов aaPanel через CLI', $summary);
    $repo->recordCronRun('import-aapanel-logs', 'ok', 'inserted=' . ($summary['inserted'] ?? 0), $summary, $started);
    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    if ($repo) {
        $repo->recordCronRun('import-aapanel-logs', 'failed', $e->getMessage(), [], $started);
    }
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
