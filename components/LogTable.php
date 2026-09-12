<?php
declare(strict_types=1);

namespace Cajeer\Logs\Resources\Components;

final class LogTable
{
    public function render(array $logs = []): string
    {
        return '<section data-component="log-table"></section>';
    }
}
