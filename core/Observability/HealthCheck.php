<?php
declare(strict_types=1);

namespace Cajeer\Logs\Observability;

final class HealthCheck
{
    public function run(): array
{
    return ['status' => 'ok'];
}
}
