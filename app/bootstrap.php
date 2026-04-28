<?php
declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'CajeerLogs\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use CajeerLogs\Env;
use CajeerLogs\Logger;

$envPath = dirname(__DIR__) . '/.env';
$envExists = is_file($envPath) && is_readable($envPath);
Env::load($envPath);
$defaultAppEnv = PHP_SAPI === 'cli' ? 'local' : 'production';
if (!$envExists && Env::get('APP_ENV', $defaultAppEnv) === 'production') {
    throw new RuntimeException('Файл .env обязателен для production. Восстановите его из storage/backups/updates/*/env.snapshot или создайте на основе .env.example.');
}
date_default_timezone_set(Env::get('APP_TIMEZONE', 'UTC'));

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function (Throwable $e): void {
    Logger::error('Необработанное исключение', ['exception' => $e]);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Необработанное исключение: {$e->getMessage()}\n");
        exit(1);
    }

    $debug = Env::bool('APP_DEBUG', false);
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Cajeer-Logs-Error: unhandled-exception');
    echo json_encode([
        'ok' => false,
        'error' => 'internal_server_error',
        'message' => $debug ? $e->getMessage() : 'Внутренняя ошибка сервера',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

register_shutdown_function(function (): void {
    $error = error_get_last();
    if (!$error) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (!in_array((int)$error['type'], $fatalTypes, true)) {
        return;
    }

    Logger::error('Критическая ошибка при завершении', [
        'type' => $error['type'] ?? null,
        'message' => $error['message'] ?? null,
        'file' => $error['file'] ?? null,
        'line' => $error['line'] ?? null,
    ]);
});
