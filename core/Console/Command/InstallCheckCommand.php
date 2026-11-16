<?php
declare(strict_types=1);

namespace Cajeer\Logs\Console\Command;

use Cajeer\Logs\Kernel\Application;

final class InstallCheckCommand
{
    public function __construct(private readonly Application $app) {}

    public function handle(): int
    {
        $report=(new \Cajeer\Logs\Installer\InstallPreflight($this->app))->report();
        echo json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
        return $report['status']==='ok'?0:1;
    }
}
