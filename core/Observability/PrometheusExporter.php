<?php
declare(strict_types=1);

namespace Cajeer\Logs\Observability;

final class PrometheusExporter
{
    public function render(): string
{
    return "cajeerlogs_up 1\n";
}
}
