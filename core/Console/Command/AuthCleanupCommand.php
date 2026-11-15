<?php
declare(strict_types=1);
namespace Cajeer\Logs\Console\Command;

use Cajeer\Logs\Kernel\Application;
use PDO;

final class AuthCleanupCommand
{
    public function __construct(private readonly Application $app) {}
    public function handle(): int
    {
        $pdo=$this->app->container()->get(PDO::class);
        $counts=(new \Cajeer\Logs\Lifecycle\Maintenance($pdo,$this->app->config()->get('security.session_idle_seconds')))->authCleanup();
        echo json_encode($counts,JSON_THROW_ON_ERROR).PHP_EOL;
        return 0;
    }
}
