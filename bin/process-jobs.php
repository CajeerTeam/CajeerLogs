#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use CajeerLogs\AaPanelLogImporter;
use CajeerLogs\Database;
use CajeerLogs\Env;
use CajeerLogs\Repository;

$started = microtime(true);
$repo = new Repository(Database::pdo());
$processed = 0;
try {
    while ($job = $repo->nextQueuedJob()) {
        $processed++;
        $repo->markJobStarted((int)$job['id']);
        $payload = json_decode((string)($job['payload'] ?? '{}'), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        try {
            if ((string)$job['type'] === 'aapanel_import') {
                $summary = (new AaPanelLogImporter($repo))->importAll(
                    (string)($payload['dir'] ?? Env::get('AAPANEL_LOG_DIR', '/www/wwwlogs')),
                    isset($payload['site']) && $payload['site'] !== '' ? (string)$payload['site'] : null,
                    max(1, min(10000, (int)($payload['max_lines'] ?? 1000)))
                );
                $repo->finishJob((int)$job['id'], 'done', $summary);
                echo '[done] job #' . $job['id'] . ' aapanel_import inserted=' . ($summary['inserted'] ?? 0) . PHP_EOL;
            } else {
                $repo->finishJob((int)$job['id'], 'failed', [], 'unknown job type');
                echo '[failed] job #' . $job['id'] . ' unknown type' . PHP_EOL;
            }
        } catch (Throwable $e) {
            $repo->finishJob((int)$job['id'], 'failed', [], $e->getMessage());
            echo '[failed] job #' . $job['id'] . ' ' . $e->getMessage() . PHP_EOL;
        }
    }
    $repo->recordCronRun('process-jobs', 'ok', 'processed=' . $processed, ['processed' => $processed], $started);
} catch (Throwable $e) {
    $repo->recordCronRun('process-jobs', 'failed', $e->getMessage(), [], $started);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
