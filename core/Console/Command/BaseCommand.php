<?php
declare(strict_types=1);

namespace Cajeer\Logs\Console\Command;

final class BaseCommand
{
    public function description(): string
{
    return 'Базовая CLI-команда CajeerLogs.';
}
}
