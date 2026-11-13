<?php
declare(strict_types=1);

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $process = getenv($key);
        $value = $process !== false ? $process : ($_ENV[$key] ?? $GLOBALS['cajeer_environment'][$key] ?? $default);
        if (!is_string($value)) {
            return $value;
        }
        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            default => $value,
        };
    }
}
