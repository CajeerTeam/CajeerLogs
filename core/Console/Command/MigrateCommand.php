<?php
declare(strict_types=1);
namespace Cajeer\Logs\Console\Command;

use Cajeer\Logs\Database\MigrationRunner;
use Cajeer\Logs\Kernel\Application;

final class MigrateCommand
{
    public function __construct(private readonly Application $app) {}
    public function handle(bool $statusOnly = false): int
    {
        $runner = $this->app->container()->get(MigrationRunner::class);
        if ($statusOnly) {
            $status = $runner->status();
            foreach ($status['applied'] as $name) { echo "[APPLIED] $name\n"; }
            foreach ($status['pending'] as $name) { echo "[PENDING] $name\n"; }
            return $status['pending'] === [] ? 0 : 1;
        }
        $applied = $runner->migrate();
        foreach ($applied as $name) { echo "Applied $name\n"; }
        if ($applied === []) { echo "No pending migrations.\n"; }
        return 0;
    }
}
