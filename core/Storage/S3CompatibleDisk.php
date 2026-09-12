<?php
declare(strict_types=1);

namespace Cajeer\Logs\Storage;

final class S3CompatibleDisk
{
    public function configured(): bool
{
    return (bool) getenv('S3_BUCKET');
}
}
