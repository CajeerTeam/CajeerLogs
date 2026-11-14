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
        try { $pdo=$this->app->container()->get(\PDO::class); } catch (\Throwable) { $pdo=null; }
        $verified=0;
        if ($pdo!==null) { try { $this->app->container()->get(\Cajeer\Logs\Database\MigrationRunner::class)->assertCurrent(); $verified=1; } catch (\Throwable) {} }
        $output.="# HELP cajeerlogs_schema_verified Applied migration history and catalog match this release.\n# TYPE cajeerlogs_schema_verified gauge\ncajeerlogs_schema_verified $verified\n";
        // Runtime liveness remains independent of PostgreSQL; omit unavailable queue gauges.
        try {
            if ($pdo===null) { throw new \RuntimeException('Database unavailable.'); }
            $counts=$this->app->container()->get(\Cajeer\Logs\Queue\PostgresQueueDriver::class)->counts();
            $output.="# HELP cajeerlogs_queue_jobs Current ingest jobs by status.\n# TYPE cajeerlogs_queue_jobs gauge\n";
            foreach ($counts as $status=>$count) { $output.='cajeerlogs_queue_jobs{status="'.$status.'"} '.$count."\n"; }
            $output.="# HELP cajeerlogs_queue_available Queue database query successful.\n# TYPE cajeerlogs_queue_available gauge\ncajeerlogs_queue_available 1\n";
        } catch (\Throwable) {
            $output.="# HELP cajeerlogs_queue_available Queue database query successful.\n# TYPE cajeerlogs_queue_available gauge\ncajeerlogs_queue_available 0\n";
        }
        return $output.($pdo===null ? "# TYPE cajeerlogs_operations_available gauge\ncajeerlogs_operations_available 0\n" : (new OperationsMetrics($this->app))->render()).(new DatabaseRuntimeMetrics($this->app))->render($pdo);
    }
}
