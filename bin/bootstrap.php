#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use CajeerLogs\Auth;
use CajeerLogs\Database;
use CajeerLogs\Env;
use CajeerLogs\Migrator;
use CajeerLogs\Repository;

function out(string $message): void { fwrite(STDOUT, $message . PHP_EOL); }

$root = dirname(__DIR__);
out('[подготовка] Cajeer Logs: production bootstrap');
out('[подготовка] корень проекта: ' . $root);

if (!is_file($root . '/.env')) {
    out('[подготовка] .env не найден. Создайте файл перед запуском: архив не генерирует секреты автоматически.');
    exit(2);
}

foreach (['storage/logs', 'storage/cache', 'storage/archives'] as $dir) {
    $path = $root . '/' . $dir;
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
    out('[подготовка] ' . $dir . ': ' . (is_writable($path) ? 'доступен на запись' : 'нет записи'));
}

out('[подготовка] подключение БД: ' . Env::get('DB_CONNECTION', 'sqlite'));
out('[подготовка] PDO-драйверы: ' . implode(', ', PDO::getAvailableDrivers()));
out('[подготовка] документация: ' . Env::get('DOCS_URL', 'https://github.com/CajeerTeam/CajeerLogs/wiki'));

$pdo = Database::pdo();
(new Migrator($pdo))->run();
out('[подготовка] миграции: выполнены');

Auth::seedAdminIfEmpty($pdo);
if (Auth::weakBootstrapPassword((string)Env::get('LOGS_WEB_BASIC_PASSWORD', ''))) {
    out('[подготовка] администратор: автоматическое создание пропущено, пароль в .env похож на шаблон. Создайте пользователя командой: php bin/make-user.php admin <strong-password> admin');
} else {
    out('[подготовка] администратор: проверен');
}

$repo = new Repository($pdo);
$report = $repo->dbOwnershipReport();
$bad = array_filter($report, static fn(array $row): bool => isset($row['ok']) && !$row['ok']);
out('[подготовка] владельцы объектов БД: ' . ($bad ? 'требуется исправление: php bin/db-doctor.php --sql' : 'норма'));

out('[подготовка] cron-команды:');
out('cd ' . $root . ' && ' . PHP_BINARY . ' bin/process-jobs.php >/dev/null 2>&1');
out('cd ' . $root . ' && ' . PHP_BINARY . ' bin/alert-dispatch.php >/dev/null 2>&1');
out('cd ' . $root . ' && ' . PHP_BINARY . ' bin/import-aapanel-logs.php --max-lines=1000 >/dev/null 2>&1');
out('cd ' . $root . ' && ' . PHP_BINARY . ' bin/retention.php >/dev/null 2>&1');

out('[подготовка] готово');
