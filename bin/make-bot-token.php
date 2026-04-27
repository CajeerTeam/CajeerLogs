#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use CajeerLogs\Database;
use CajeerLogs\Env;
use CajeerLogs\Migrator;
use CajeerLogs\Repository;

$args = $_SERVER['argv'];
array_shift($args);
$bot = $args[0] ?? null;
if (!$bot || str_starts_with($bot, '--')) {
    fwrite(STDERR, "Usage: php bin/make-bot-token.php <BOT_NAME> --project=NeverMine --environment=production [--description=...] [--rate-limit=120] [--max-batch=100] [--levels=INFO,WARNING,ERROR] [--require-signature]\n");
    exit(1);
}
$options = [
    'project' => 'Cajeer',
    'environment' => 'production',
    'description' => null,
    'rate_limit_per_minute' => 120,
    'max_batch_size' => 100,
    'allowed_levels' => null,
    'require_signature' => false,
];
foreach (array_slice($args, 1) as $arg) {
    if (str_starts_with($arg, '--project=')) {
        $options['project'] = substr($arg, 10);
    } elseif (str_starts_with($arg, '--environment=')) {
        $options['environment'] = substr($arg, 14);
    } elseif (str_starts_with($arg, '--description=')) {
        $options['description'] = substr($arg, 14);
    } elseif (str_starts_with($arg, '--rate-limit=')) {
        $options['rate_limit_per_minute'] = (int)substr($arg, 13);
    } elseif (str_starts_with($arg, '--max-batch=')) {
        $options['max_batch_size'] = (int)substr($arg, 12);
    } elseif (str_starts_with($arg, '--levels=')) {
        $options['allowed_levels'] = substr($arg, 9);
    } elseif ($arg === '--require-signature') {
        $options['require_signature'] = true;
    }
}

$migrator = new Migrator(Database::pdo());
$migrator->run();
$repo = new Repository(Database::pdo());
$result = $repo->createBotToken($bot, $options['project'], $options['environment'], $options['description'], $options);
$repo->audit('bot_token.created.cli', 'bot_token', (string)$result['id'], 'Создан токен бота через CLI', ['project' => $options['project'], 'bot' => $bot, 'environment' => $options['environment']]);

$url = rtrim(Env::get('APP_URL', 'https://logs.example.com'), '/') . '/api/v1/ingest';
echo "Bot token created. Raw token is shown once. Store it in the bot env.\n\n";
echo "BOT_TOKEN_ID={$result['id']}\n";
echo "REMOTE_LOGS_URL={$url}\n";
echo "REMOTE_LOGS_TOKEN={$result['raw_token']}\n";
echo "REMOTE_LOGS_PROJECT={$options['project']}\n";
echo "REMOTE_LOGS_BOT={$bot}\n";
echo "REMOTE_LOGS_ENVIRONMENT={$options['environment']}\n";
if ($options['require_signature']) {
    echo "REMOTE_LOGS_SIGN_REQUESTS=true\n";
}
