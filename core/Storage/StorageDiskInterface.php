<?php
declare(strict_types=1);

namespace Cajeer\Logs\Storage;

interface StorageDiskInterface
{
    public function path(string $path): string;
}
