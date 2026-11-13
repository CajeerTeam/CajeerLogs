<?php
declare(strict_types=1);

namespace Cajeer\Logs\Console\Command;

use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Observability\SystemReport;

final class SystemReportCommand
{
    public function __construct(private readonly Application $app) {}

    public function handle(): int
    {
        $report = $this->app->container()->get(SystemReport::class)->build();
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        return $report['status'] === 'ok' ? 0 : 1;
    }
}
