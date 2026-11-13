<?php
declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'CajeerLogs'),
    'env' => env('APP_ENV', 'production'),
    'debug' => env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost:8080'),
    'locale' => env('APP_LOCALE', 'ru'),
    'timezone' => env('APP_TIMEZONE', 'Europe/Moscow'),
    'max_body_bytes' => env('APP_MAX_BODY_BYTES', '1048576'),
];
