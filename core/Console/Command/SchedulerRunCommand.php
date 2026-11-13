<?php
declare(strict_types=1);

namespace Cajeer\Logs\Console\Command;

final class SchedulerRunCommand
{
    public function handle(): int
    {
        fwrite(STDERR, "Scheduler is not implemented in this release (planned for 0.0.8).\n");
        return 69;
    }
}
