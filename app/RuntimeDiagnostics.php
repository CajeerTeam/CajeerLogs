<?php
declare(strict_types=1);

namespace CajeerLogs;

use PDO;
use Throwable;

final class RuntimeDiagnostics
{
    public static function envPath(): string { return dirname(__DIR__) . '/.env'; }
    public static function productionLockPath(): string { return dirname(__DIR__) . '/storage/production.lock'; }
    public static function envExists(): bool { return is_file(self::envPath()) && is_readable(self::envPath()); }
    public static function productionLocked(): bool { return is_file(self::productionLockPath()); }

    public static function productionLockViolations(): array
    {
        if (!self::productionLocked()) { return []; }
        $violations = [];
        if (!self::envExists()) { $violations[] = 'Файл .env отсутствует.'; }
        if (Env::get('DB_CONNECTION', 'sqlite') === 'sqlite') { $violations[] = 'DB_CONNECTION=sqlite запрещён при storage/production.lock.'; }
        if (Env::bool('APP_DEBUG', false)) { $violations[] = 'APP_DEBUG=true запрещён при storage/production.lock.'; }
        if (Env::bool('LOGS_ENV_FALLBACK_LOGIN', false)) { $violations[] = 'LOGS_ENV_FALLBACK_LOGIN=true запрещён при storage/production.lock.'; }
        if (!Env::bool('UPDATE_REQUIRE_TAG', true)) { $violations[] = 'UPDATE_REQUIRE_TAG=false запрещён при storage/production.lock.'; }
        if (Env::bool('UPDATE_ALLOW_WEB', false) && trim((string)Env::get('UPDATE_ALLOWED_REPO_FULL_NAME', '')) === '') { $violations[] = 'UPDATE_ALLOW_WEB=true требует UPDATE_ALLOWED_REPO_FULL_NAME при storage/production.lock.'; }
        return $violations;
    }

    public static function assertProductionLockSafe(): void
    {
        $violations = self::productionLockViolations();
        if ($violations) { throw new \RuntimeException('Production lock активен: ' . implode(' ', $violations)); }
    }

    public static function commandAvailable(string $cmd): bool
    {
        if ($cmd === '') { return false; }
        if (str_contains($cmd, '/')) { return is_file($cmd) && is_executable($cmd); }
        if (!function_exists('exec')) { return false; }
        $out = []; $code = 1;
        @exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null', $out, $code);
        return $code === 0 && !empty($out);
    }

    public static function phpRuntime(?string $configuredPhp = null): array
    {
        $disabled = (string)ini_get('disable_functions');
        $drivers = class_exists(PDO::class) ? PDO::getAvailableDrivers() : [];
        $opcache = self::opcacheStatus();
        return [
            'PHP SAPI' => PHP_SAPI,
            'PHP binary' => PHP_BINARY,
            'PHP CLI configured' => $configuredPhp ?: (string)Env::get('UPDATE_PHP_BIN', ''),
            'PHP version' => PHP_VERSION,
            'Пользователь процесса' => self::currentUser(),
            'PATH' => (string)(getenv('PATH') ?: ($_SERVER['PATH'] ?? '')),
            'open_basedir' => (string)(ini_get('open_basedir') ?: '—'),
            'disable_functions' => $disabled === '' ? '—' : $disabled,
            'proc_open' => function_exists('proc_open') ? 'доступен' : 'отключён',
            'exec' => function_exists('exec') ? 'доступен' : 'отключён',
            'shell_exec' => function_exists('shell_exec') ? 'доступен' : 'отключён',
            'PDO drivers' => $drivers ? implode(', ', $drivers) : 'нет',
            'pdo_pgsql' => extension_loaded('pdo_pgsql') ? 'загружен' : 'нет',
            'pdo_sqlite' => extension_loaded('pdo_sqlite') ? 'загружен' : 'нет',
            'OPcache enable' => $opcache['enable'],
            'OPcache CLI enable' => $opcache['enable_cli'],
            'OPcache validate_timestamps' => $opcache['validate_timestamps'],
            'OPcache revalidate_freq' => $opcache['revalidate_freq'],
            'opcache_reset' => function_exists('opcache_reset') ? 'доступен' : 'недоступен',
        ];
    }

    public static function opcacheStatus(): array
    {
        return [
            'enable' => (string)(ini_get('opcache.enable') ?: '0'),
            'enable_cli' => (string)(ini_get('opcache.enable_cli') ?: '0'),
            'validate_timestamps' => (string)(ini_get('opcache.validate_timestamps') ?: '0'),
            'revalidate_freq' => (string)(ini_get('opcache.revalidate_freq') ?: '0'),
        ];
    }

    public static function databaseProbe(): array
    {
        try {
            $pdo = Database::pdo();
            $pdo->query('SELECT 1');
            return ['ok' => true, 'driver' => Database::driver(), 'message' => 'OK'];
        } catch (Throwable $e) {
            return ['ok' => false, 'driver' => (string)Env::get('DB_CONNECTION', 'sqlite'), 'message' => $e->getMessage()];
        }
    }

    private static function currentUser(): string
    {
        if (function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid(posix_geteuid());
            if (is_array($info) && isset($info['name'])) { return (string)$info['name']; }
        }
        return function_exists('get_current_user') ? get_current_user() : '—';
    }
}
