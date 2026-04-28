#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use CajeerLogs\Database;
use CajeerLogs\Migrator;
use CajeerLogs\Repository;

(new Migrator(Database::pdo()))->run();
$repo = new Repository(Database::pdo());
$ruleId = $repo->createAlertRule([
    'name' => 'Smoke alert pipeline',
    'channel' => 'telegram',
    'webhook_url' => 'https://example.com/webhook',
    'project' => 'ExampleProject',
    'bot' => 'ExampleBot',
    'environment' => 'production',
    'levels' => 'ERROR',
    'threshold_count' => 1,
    'window_seconds' => 60,
    'cooldown_seconds' => 60,
]);
$token = $repo->createBotToken('ExampleProject', 'ExampleBot', 'production', 'smoke', [
    'require_signature' => true,
    'max_batch_size' => 10,
]);
$botToken = $repo->findBotToken($token['raw_token']);
$repo->insertBatch($botToken, [[
    'ts' => gmdate('c'),
    'level' => 'ERROR',
    'project' => 'ExampleProject',
    'bot' => 'ExampleBot',
    'environment' => 'production',
    'message' => 'Smoke pipeline error',
]], ['smoke' => true, 'signed' => true, 'body_bytes' => 128]);
$result = $repo->evaluateAlertRules();
if (!$result || (string)($result[0]['status'] ?? '') !== 'queued') {
    fwrite(STDERR, "Оповещение не поставлено в очередь\n");
    exit(1);
}
$job = $repo->claimNextQueuedJob('alert_webhook');
if (!$job) {
    fwrite(STDERR, "Задача alert_webhook не найдена\n");
    exit(1);
}
$repo->failOrRetryJob((int)$job['id'], $job, ['ok' => false, 'smoke' => true], 'smoke retry check');
$jobs = $repo->jobs(10);
$status = (string)($jobs[0]['status'] ?? '');
if (!in_array($status, ['retry', 'dead'], true)) {
    fwrite(STDERR, "Неожиданный статус задачи: {$status}\n");
    exit(1);
}
echo "Alert pipeline smoke OK: rule={$ruleId}, job=" . $job['id'] . ", status={$status}\n";
