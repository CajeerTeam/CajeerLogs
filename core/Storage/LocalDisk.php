<?php
declare(strict_types=1);

namespace Cajeer\Logs\Storage;

final class LocalDisk
{
    public function path(string $path): string
{
    return dirname(__DIR__, 2) . '/storage/app/' . ltrim($path, '/');
}
}
