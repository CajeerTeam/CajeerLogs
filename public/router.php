<?php
declare(strict_types=1);

// Router for `php -S ... -t public public/router.php` (development only).
$path = explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0];
if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)
    && preg_match('~^/(?:assets/|admin/assets/)[A-Za-z0-9_./-]+\.(?:css|js|png|jpg|jpeg|svg|ico|webp|woff2?)$~D', $path)
    && !str_contains($path, '..')) {
    $file = realpath(__DIR__ . $path);
    if ($file !== false && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR) && is_file($file)) {
        return false;
    }
}
require __DIR__ . '/index.php';
