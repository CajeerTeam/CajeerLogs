<?php
declare(strict_types=1);

namespace Cajeer\Logs\Observability;

final class OpenTelemetryTracer
{
    public function span(string $name, callable $callback): mixed
{
    return $callback();
}
}
