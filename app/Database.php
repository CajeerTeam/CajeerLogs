<?php
declare(strict_types=1);

namespace CajeerLogs;

use PDO;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $connection = Env::get('DB_CONNECTION', 'sqlite');
        if (!extension_loaded('pdo')) {
            throw new RuntimeException('PHP extension pdo is not loaded. Enable PDO in aaPanel PHP settings.');
        }

        if ($connection === 'pgsql') {
            self::requirePdoDriver('pgsql', 'pdo_pgsql');
            $host = Env::get('DB_HOST', '127.0.0.1');
            $port = Env::get('DB_PORT', '5432');
            $db = Env::require('DB_DATABASE');
            $sslmode = Env::get('DB_SSLMODE', 'disable');
            $dsn = "pgsql:host={$host};port={$port};dbname={$db};sslmode={$sslmode}";
            self::$pdo = new PDO($dsn, Env::get('DB_USERNAME', ''), Env::get('DB_PASSWORD', ''), self::options());
            return self::$pdo;
        }

        self::requirePdoDriver('sqlite', 'pdo_sqlite');
        $path = Env::get('DB_DATABASE', 'storage/logs.sqlite');
        if (!str_starts_with($path, '/')) {
            $path = dirname(__DIR__) . '/' . $path;
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create SQLite directory: ' . $dir);
        }
        if (!is_writable($dir)) {
            throw new RuntimeException('SQLite directory is not writable: ' . $dir);
        }
        self::$pdo = new PDO('sqlite:' . $path, null, null, self::options());
        self::$pdo->exec('PRAGMA foreign_keys = ON');
        self::$pdo->exec('PRAGMA journal_mode = WAL');
        return self::$pdo;
    }

    public static function driver(): string
    {
        return self::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public static function diagnostics(): array
    {
        $drivers = class_exists(PDO::class) ? PDO::getAvailableDrivers() : [];
        return [
            'connection' => Env::get('DB_CONNECTION', 'sqlite'),
            'pdo_loaded' => extension_loaded('pdo'),
            'pdo_drivers' => $drivers,
            'pdo_pgsql_loaded' => extension_loaded('pdo_pgsql'),
            'pdo_sqlite_loaded' => extension_loaded('pdo_sqlite'),
            'storage_logs_writable' => is_writable(dirname(__DIR__) . '/storage/logs'),
            'php_version' => PHP_VERSION,
        ];
    }

    private static function requirePdoDriver(string $driver, string $extension): void
    {
        $drivers = PDO::getAvailableDrivers();
        if (!in_array($driver, $drivers, true)) {
            throw new RuntimeException(
                "PDO driver '{$driver}' is not available. Enable PHP extension {$extension} for the PHP version used by aaPanel/Nginx. Available drivers: " .
                ($drivers ? implode(', ', $drivers) : 'none')
            );
        }
    }

    private static function options(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
    }
}
