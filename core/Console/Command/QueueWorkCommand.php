<?php
declare(strict_types=1);

namespace Cajeer\Logs\Console\Command;

final class QueueWorkCommand
{
    public function handle(): int
    {
        fwrite(STDERR, "Queue worker is not implemented in this release (planned for 0.0.8).\n");
        return 69;
    }
}
