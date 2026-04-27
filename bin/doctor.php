#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use CajeerLogs\Database;
use CajeerLogs\Env;
use CajeerLogs\Migrator;

$root = dirname(__DIR__);
$appUrl = Env::get('APP_URL', 'https://logs.example.com');
$host = parse_url((string)$appUrl, PHP_URL_HOST) ?: 'logs.example.com';
$checks = [];
$add = static function (string $name, bool $ok, string $message = '') use (&$checks): void {
    $checks[] = [$name, $ok, $message];
};

$add('PHP >= 8.2', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION);
$add('PDO loaded', extension_loaded('pdo'), '');
$drivers = class_exists(PDO::class) ? PDO::getAvailableDrivers() : [];
$add('PDO driver configured', in_array(Env::get('DB_CONNECTION', 'sqlite') === 'pgsql' ? 'pgsql' : 'sqlite', $drivers, true), implode(',', $drivers));
$add('storage/logs writable', is_writable($root . '/storage/logs'), $root . '/storage/logs');
$add('storage/cache writable', is_writable($root . '/storage/cache'), $root . '/storage/cache');
$add('storage/archives writable', is_writable($root . '/storage/archives'), $root . '/storage/archives');
$add('APP_DEBUG disabled', !Env::bool('APP_DEBUG', false), 'APP_DEBUG=' . Env::get('APP_DEBUG', ''));
$add('LOGS_TOKEN_PEPPER changed', !str_contains((string)Env::get('LOGS_TOKEN_PEPPER', ''), 'change_me'), '');
$add('PRIVACY_HASH_PEPPER changed', !str_contains((string)Env::get('PRIVACY_HASH_PEPPER', ''), 'change_me'), '');
$add('Fallback login disabled', !Env::bool('LOGS_ENV_FALLBACK_LOGIN', false), 'LOGS_ENV_FALLBACK_LOGIN=' . Env::get('LOGS_ENV_FALLBACK_LOGIN', ''));
$add('.env not public', !is_file($root . '/public/.env'), '');

$aaPanelLogDir = Env::get('AAPANEL_LOG_DIR', '/www/wwwlogs');
$add('aaPanel logs directory readable', is_dir($aaPanelLogDir) && is_readable($aaPanelLogDir), $aaPanelLogDir);
$grep = trim((string)shell_exec('grep -R "putenv[[:space:]]*(" -n ' . escapeshellarg($root . '/app') . ' 2>/dev/null'));
$add('No putenv usage', $grep === '', $grep);

try {
    $migrator = new Migrator(Database::pdo());
    $migrator->run();
    Database::pdo()->query('SELECT 1');
    $add('Database connection', true, Database::driver());
    foreach (['bot_tokens','log_events','incidents','alert_rules','users','aapanel_log_offsets','login_attempts','cron_runs','saved_views','jobs','schema_migrations'] as $table) {
        Database::pdo()->query('SELECT COUNT(*) FROM ' . $table);
        $add('Table ' . $table, true, '');
    }
} catch (Throwable $e) {
    $add('Database/schema', false, $e->getMessage());
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
    $add('Nginx root points to public', str_contains($content, 'root ' . $root . '/public'), $nginxConfig);
} else {
    $add('Nginx config found', false, implode(', ', $nginxCandidates));
}

$failed = 0;
foreach ($checks as [$name, $ok, $message]) {
    echo ($ok ? '[OK]   ' : '[FAIL] ') . $name . ($message !== '' ? ' — ' . $message : '') . PHP_EOL;
    if (!$ok) { $failed++; }
}
exit($failed > 0 ? 1 : 0);
