<?php
declare(strict_types=1);

namespace Cajeer\Logs\Console\Command;

final class InstallCheckCommand
{
    public function handle(): int
{
    echo "Install check: PHP " . PHP_VERSION . "\n";
    return 0;
}
}
