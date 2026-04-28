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
        $payload = json_decode((string)($job['payload'] ?? '{}'), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        try {
            if ((string)$job['type'] === 'alert_webhook') {
                $result = $repo->dispatchAlertWebhookJob($payload);
                if (!empty($result['ok'])) {
                    $repo->finishJob((int)$job['id'], 'done', $result);
                    echo '[готово] задача #' . $job['id'] . ' доставка оповещения ' . ((string)($result['status'] ?? '')) . PHP_EOL;
                } else {
                    $message = (string)($result['message'] ?? 'ошибка доставки');
                    $repo->failOrRetryJob((int)$job['id'], $job, $result, $message);
                    echo '[повтор] задача #' . $job['id'] . ' доставка оповещения: ' . $message . PHP_EOL;
                }
            } elseif ((string)$job['type'] === 'aapanel_import') {
                $summary = (new AaPanelLogImporter($repo))->importAll(
                    (string)($payload['dir'] ?? Env::get('NGINX_LOG_DIR', Env::get('AAPANEL_LOG_DIR', '/www/wwwlogs'))),
                    isset($payload['site']) && $payload['site'] !== '' ? (string)$payload['site'] : null,
                    max(1, min(10000, (int)($payload['max_lines'] ?? 1000)))
                );
                $repo->finishJob((int)$job['id'], 'done', $summary);
                echo '[готово] задача #' . $job['id'] . ' импорт логов сайтов добавлено=' . ($summary['inserted'] ?? 0) . PHP_EOL;
            } else {
                $repo->finishJob((int)$job['id'], 'dead', [], 'unknown job type');
                echo '[сбой] задача #' . $job['id'] . ' неизвестный тип' . PHP_EOL;
            }
        } catch (Throwable $e) {
            $repo->failOrRetryJob((int)$job['id'], $job, [], $e->getMessage());
            echo '[повтор] задача #' . $job['id'] . ' ' . $e->getMessage() . PHP_EOL;
        }
    }
    $repo->recordCronRun('process-jobs', 'ok', 'processed=' . $processed, ['processed' => $processed], $started);
} catch (Throwable $e) {
    $repo->recordCronRun('process-jobs', 'failed', $e->getMessage(), [], $started);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
