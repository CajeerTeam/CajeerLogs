<?php
declare(strict_types=1);

namespace Cajeer\Logs\Console\Command;

use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Observability\Diagnostics;

final class InstallCheckCommand
{
    public function __construct(private readonly Application $app) {}

    public function handle(): int
    {
        $checks = $this->app->container()->get(Diagnostics::class)->checks();
        echo 'CajeerLogs ' . $this->app->version() . " runtime checks\n";
        foreach ($checks as $name => $passed) {
            echo ($passed ? '[OK] ' : '[FAIL] ') . $name . PHP_EOL;
        }
        echo "Database connectivity and migrations: not enabled in 0.0.1.\n";
        return in_array(false, $checks, true) ? 1 : 0;
    }
}
