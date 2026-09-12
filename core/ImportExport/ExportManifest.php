<?php
declare(strict_types=1);

namespace Cajeer\Logs\ImportExport;

final class ExportManifest
{
    public function __construct(public readonly string $type, public readonly string $createdAt) {}
}
