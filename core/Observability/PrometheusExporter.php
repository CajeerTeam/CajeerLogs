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
        $readiness=new Readiness($this->app); $factory=$readiness->factory(); $start=hrtime(true);
        try { $pdo=$factory->make('pgsql'); } catch (\Throwable) { $pdo=null; }
        $connectSeconds=(hrtime(true)-$start)/1e9;
        $ready=$pdo!==null&&$readiness->check($pdo)['status']==='ok'?1:0;
        $output.="# TYPE cajeerlogs_ready gauge\ncajeerlogs_ready $ready\n";
        $verified=$ready;
        if ($pdo!==null && !$verified) { try { (new \Cajeer\Logs\Database\MigrationRunner($pdo,$this->app->basePath('migrations/pgsql'),$factory->schema()))->assertCurrent(); $verified=1; } catch (\Throwable) {} }
        $output.="# HELP cajeerlogs_schema_verified Applied migration history and catalog match this release.\n# TYPE cajeerlogs_schema_verified gauge\ncajeerlogs_schema_verified $verified\n";
        // Runtime liveness remains independent of PostgreSQL; omit unavailable queue gauges.
        try {
            if ($pdo===null) { throw new \RuntimeException('Database unavailable.'); }
            $counts=(new \Cajeer\Logs\Queue\PostgresQueueDriver($pdo))->counts();
            $output.="# HELP cajeerlogs_queue_jobs Current ingest jobs by status.\n# TYPE cajeerlogs_queue_jobs gauge\n";
            foreach ($counts as $status=>$count) { $output.='cajeerlogs_queue_jobs{status="'.$status.'"} '.$count."\n"; }
            $output.="# HELP cajeerlogs_queue_available Queue database query successful.\n# TYPE cajeerlogs_queue_available gauge\ncajeerlogs_queue_available 1\n";
        } catch (\Throwable) {
            $output.="# HELP cajeerlogs_queue_available Queue database query successful.\n# TYPE cajeerlogs_queue_available gauge\ncajeerlogs_queue_available 0\n";
        }
        $security=\Cajeer\Logs\Security\SecurityPosture::configuration($this->app->config());
        $configurationOk=!in_array(false,$security,true)?1:0;
        $output.="# TYPE cajeerlogs_security_configuration_healthy gauge\ncajeerlogs_security_configuration_healthy $configurationOk\n";
        try {
            if ($pdo===null) throw new \RuntimeException('Database unavailable.');
            $identityCounts=\Cajeer\Logs\Security\SecurityPosture::database($pdo);
            $output.="# TYPE cajeerlogs_security_identity_audit_available gauge\ncajeerlogs_security_identity_audit_available 1\n";
            foreach ($identityCounts as $name=>$value) {
                $output.="# TYPE cajeerlogs_security_$name gauge\ncajeerlogs_security_$name $value\n";
            }
        } catch (\Throwable) {
            $output.="# TYPE cajeerlogs_security_identity_audit_available gauge\ncajeerlogs_security_identity_audit_available 0\n";
        }
        $output.=(new IngestMetrics($this->app->basePath('storage/cache')))->render();
        $output.=(new IngestMetrics($this->app->basePath('storage/cache'),'search'))->render();
        $output.=(new IngestMetrics($this->app->basePath('storage/cache'),'identity'))->render();
        return $output.($pdo===null ? "# TYPE cajeerlogs_operations_available gauge\ncajeerlogs_operations_available 0\n" : (new OperationsMetrics($this->app))->render($pdo)).(new DatabaseRuntimeMetrics($this->app))->render($pdo,$factory,$connectSeconds).(new WorkerMetrics($this->app))->render().(new AuditFailureMetrics($this->app->basePath('storage/cache')))->render();
    }
}
