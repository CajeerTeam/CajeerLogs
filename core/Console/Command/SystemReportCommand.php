<?php
declare(strict_types=1);

namespace Cajeer\Logs\Console\Command;

final class SystemReportCommand
{
    public function handle(): int
{
    echo json_encode(['service' => 'CajeerLogs', 'php' => PHP_VERSION], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    return 0;
}
}
