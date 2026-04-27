<?php
declare(strict_types=1);

namespace CajeerLogs;

final class Env
{
    private static array $values = [];

    public static function load(string $path): void
    {
        self::$values = array_merge(is_array($_SERVER ?? null) ? $_SERVER : [], is_array($_ENV ?? null) ? $_ENV : []);

        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            if ($key === '') {
                continue;
            }

            $value = trim(substr($line, $pos + 1));
            if (
                strlen($value) >= 2
                && (
                    ($value[0] === '"' && substr($value, -1) === '"')
                    || ($value[0] === "'" && substr($value, -1) === "'")
                )
            ) {
                $value = substr($value, 1, -1);
            }

            self::set($key, $value);
        }
    }

    public static function set(string $key, string $value): void
    {
        self::$values[$key] = $value;
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;

        // Environment values stay in Env::$_values, $_ENV and $_SERVER.
        // This avoids disabled PHP environment mutation functions in aaPanel pools.
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$values)) {
            $value = self::$values[$key];
            return $value === '' ? $default : (string)$value;
        }

        if (array_key_exists($key, $_ENV)) {
            $value = $_ENV[$key];
            return ($value === null || $value === '') ? $default : (string)$value;
        }

        if (array_key_exists($key, $_SERVER)) {
            $value = $_SERVER[$key];
            return ($value === null || $value === '') ? $default : (string)$value;
        }

        return $default;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);
        if ($value === null || !is_numeric($value)) {
            return $default;
        }

        return (int)$value;
    }

    public static function bool(string $key, bool $default): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new \RuntimeException("Missing required env: {$key}");
        }

        return $value;
    }
}
