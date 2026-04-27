#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use CajeerLogs\Database;
use CajeerLogs\Env;

$root = dirname(__DIR__);
$appUrl = Env::get('APP_URL', 'https://logs.example.com');
$host = parse_url((string)$appUrl, PHP_URL_HOST) ?: 'logs.example.com';
$checks = [];
$add = static function (string $name, bool $ok, string $message = '') use (&$checks): void {
    $checks[] = [$name, $ok, $message];
};

$add('PHP >= 8.2', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION);
$add('PDO загружен', extension_loaded('pdo'), '');
$drivers = class_exists(PDO::class) ? PDO::getAvailableDrivers() : [];
$add('PDO-драйвер настроен', in_array(Env::get('DB_CONNECTION', 'sqlite') === 'pgsql' ? 'pgsql' : 'sqlite', $drivers, true), implode(',', $drivers));
$add('storage/logs доступен на запись', is_writable($root . '/storage/logs'), $root . '/storage/logs');
$add('storage/cache доступен на запись', is_writable($root . '/storage/cache'), $root . '/storage/cache');
$add('storage/archives доступен на запись', is_writable($root . '/storage/archives'), $root . '/storage/archives');
$add('APP_DEBUG отключён', !Env::bool('APP_DEBUG', false), 'APP_DEBUG=' . Env::get('APP_DEBUG', ''));
$add('LOGS_TOKEN_PEPPER изменён', !str_contains((string)Env::get('LOGS_TOKEN_PEPPER', ''), 'change_me'), '');
$add('PRIVACY_HASH_PEPPER изменён', !str_contains((string)Env::get('PRIVACY_HASH_PEPPER', ''), 'change_me'), '');
$add('Аварийный вход отключён', !Env::bool('LOGS_ENV_FALLBACK_LOGIN', false), 'LOGS_ENV_FALLBACK_LOGIN=' . Env::get('LOGS_ENV_FALLBACK_LOGIN', ''));
$add('Web-обновление отключено', !Env::bool('UPDATE_ALLOW_WEB', false), 'UPDATE_ALLOW_WEB=' . Env::get('UPDATE_ALLOW_WEB', ''));
$add('DOCS_URL задан', filter_var((string)Env::get('DOCS_URL', ''), FILTER_VALIDATE_URL) !== false, (string)Env::get('DOCS_URL', ''));
$add('.env не опубликован', !is_file($root . '/public/.env'), '');

$aaPanelLogDir = Env::get('AAPANEL_LOG_DIR', '/www/wwwlogs');
$add('Каталог локальных Nginx-журналов читается', is_dir($aaPanelLogDir) && is_readable($aaPanelLogDir), $aaPanelLogDir);
$grep = trim((string)shell_exec('grep -R "putenv[[:space:]]*(" -n ' . escapeshellarg($root . '/app') . ' 2>/dev/null'));
$add('putenv не используется', $grep === '', $grep);

try {
    Database::pdo()->query('SELECT 1');
    $add('Подключение к базе данных', true, Database::driver());
    foreach (['bot_tokens','log_events','incidents','alert_rules','alert_deliveries','users','aapanel_log_offsets','login_attempts','cron_runs','saved_views','jobs','update_runs','schema_migrations'] as $table) {
        Database::pdo()->query('SELECT COUNT(*) FROM ' . $table);
        $add('Таблица ' . $table, true, '');
    }
} catch (Throwable $e) {
    $add('База данных/схема', false, $e->getMessage());
}

$nginxCandidates = [
    '/www/server/panel/vhost/nginx/' . $host . '.conf',
    '/etc/nginx/sites-enabled/' . $host,
    '/etc/nginx/conf.d/' . $host . '.conf',
];
$nginxConfig = null;
foreach ($nginxCandidates as $candidate) {
    if (is_file($candidate)) {
        $nginxConfig = $candidate;
        break;
    }
}
if ($nginxConfig !== null) {
    $content = file_get_contents($nginxConfig) ?: '';
    $add('Nginx root указывает на public', str_contains($content, 'root ' . $root . '/public'), $nginxConfig);
} else {
    $add('Конфиг Nginx найден', false, implode(', ', $nginxCandidates));
}

$failed = 0;
foreach ($checks as [$name, $ok, $message]) {
    echo ($ok ? '[ОК]   ' : '[СБОЙ] ') . $name . ($message !== '' ? ' — ' . $message : '') . PHP_EOL;
    if (!$ok) { $failed++; }
}
exit($failed > 0 ? 1 : 0);
