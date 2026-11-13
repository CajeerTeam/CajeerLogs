<?php
declare(strict_types=1);

namespace Cajeer\Logs\Observability;

use Cajeer\Logs\Kernel\Application;

final class PrometheusExporter
{
    public function __construct(private readonly Application $app) {}

    public function render(): string
    {
        $up = $this->app->container()->get(Diagnostics::class)->ok() ? 1 : 0;
        return "# HELP cajeerlogs_up Runtime diagnostic status (not database readiness).\n"
            . "# TYPE cajeerlogs_up gauge\ncajeerlogs_up $up\n"
            . "# HELP cajeerlogs_php_memory_bytes Memory allocated by the current PHP request.\n"
            . "# TYPE cajeerlogs_php_memory_bytes gauge\ncajeerlogs_php_memory_bytes " . memory_get_usage(true) . "\n";
    }
}
