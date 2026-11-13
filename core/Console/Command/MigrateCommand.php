<?php
declare(strict_types=1);

namespace Cajeer\Logs\Console\Command;

final class MigrateCommand
{
    public function handle(): int
    {
        fwrite(STDERR, "Database migrations is not implemented in 0.0.1 (planned for 0.0.2).\n");
        return 69;
    }
}
