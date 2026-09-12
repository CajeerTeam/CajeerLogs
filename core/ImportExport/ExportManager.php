<?php
declare(strict_types=1);

namespace Cajeer\Logs\ImportExport;

final class ExportManager
{
    public function supported(): array
{
    return ['logs', 'users', 'settings', 'themes_config'];
}
}
