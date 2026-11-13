<?php
declare(strict_types=1);
namespace Cajeer\Logs\Console\Command;
use Cajeer\Logs\Database\DatabaseStatus;
use Cajeer\Logs\Kernel\Application;
final class DbDoctorCommand
{
    public function __construct(private readonly Application $app) {}
    public function handle(): int
    {
        $status = $this->app->container()->get(DatabaseStatus::class)->check();
        echo json_encode($status, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        return $status['status'] === 'ok' ? 0 : 1;
    }
}
