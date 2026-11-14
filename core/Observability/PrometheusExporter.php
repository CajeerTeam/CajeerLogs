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
        $output = "# HELP cajeerlogs_up Runtime diagnostic status (not database readiness).\n"
            . "# TYPE cajeerlogs_up gauge\ncajeerlogs_up $up\n"
            . "# HELP cajeerlogs_php_memory_bytes Memory allocated by the current PHP request.\n"
            . "# TYPE cajeerlogs_php_memory_bytes gauge\ncajeerlogs_php_memory_bytes " . memory_get_usage(true) . "\n";
        // Runtime liveness remains independent of PostgreSQL; omit unavailable queue gauges.
        try {
            $counts=$this->app->container()->get(\Cajeer\Logs\Queue\PostgresQueueDriver::class)->counts();
            $output.="# HELP cajeerlogs_queue_jobs Current ingest jobs by status.\n# TYPE cajeerlogs_queue_jobs gauge\n";
            foreach ($counts as $status=>$count) { $output.='cajeerlogs_queue_jobs{status="'.$status.'"} '.$count."\n"; }
            $output.="# HELP cajeerlogs_queue_available Queue database query successful.\n# TYPE cajeerlogs_queue_available gauge\ncajeerlogs_queue_available 1\n";
        } catch (\Throwable) {
            $output.="# HELP cajeerlogs_queue_available Queue database query successful.\n# TYPE cajeerlogs_queue_available gauge\ncajeerlogs_queue_available 0\n";
        }
        return $output.(new OperationsMetrics($this->app))->render();
    }
}
