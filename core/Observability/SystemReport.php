<?php
declare(strict_types=1);

namespace Cajeer\Logs\Observability;

final class SystemReport
{
    public function build(): array
{
    return ['service' => 'CajeerLogs', 'php' => PHP_VERSION];
}
}
