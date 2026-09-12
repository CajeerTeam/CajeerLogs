<?php
declare(strict_types=1);

namespace Cajeer\Logs\Observability;

final class Diagnostics
{
    public function targets(): array
{
    return ['queue', 'cache', 'storage', 'database', 'analytics'];
}
}
