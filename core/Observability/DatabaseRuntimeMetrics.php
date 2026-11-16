<?php
declare(strict_types=1);
namespace Cajeer\Logs\Observability;

use Cajeer\Logs\Database\{ConnectionFactory,ConnectionManager};
use Cajeer\Logs\Kernel\Application;
use PDO;

/** Database-wide cumulative stats, plus live probe/connect durations for the scrape. */
final class DatabaseRuntimeMetrics
{
    public function __construct(private readonly Application $app) {}

    public function render(?PDO $pdo, ?ConnectionFactory $factory=null, ?float $connectSeconds=null): string
    {
        $manager=$this->app->container()->get(ConnectionManager::class);
        try {
            if ($pdo===null) { throw new \RuntimeException('Database unavailable.'); }
            $start=hrtime(true); $pdo->query('SELECT 1')->closeCursor(); $probe=(hrtime(true)-$start)/1e9;
            $row=$pdo->query("SELECT numbackends, sessions, sessions_abandoned, sessions_fatal, sessions_killed, xact_commit, xact_rollback, deadlocks, active_time/1000.0 AS active_seconds, extract(epoch FROM stats_reset) AS stats_reset FROM pg_catalog.pg_stat_database WHERE datname=current_database()") ->fetch();
            if (!$row) { throw new \RuntimeException('Database statistics unavailable.'); }
            $out="# TYPE cajeerlogs_postgres_available gauge\ncajeerlogs_postgres_available 1\n";
            $out.="# TYPE cajeerlogs_postgres_probe_seconds gauge\ncajeerlogs_postgres_probe_seconds ".$probe."\n";
            $out.="# TYPE cajeerlogs_postgres_connections gauge\ncajeerlogs_postgres_connections ".(int)$row['numbackends']."\n";
            foreach (['sessions','sessions_abandoned','sessions_fatal','sessions_killed','xact_commit','xact_rollback','deadlocks','active_seconds'] as $name) {
                $out.="# TYPE cajeerlogs_postgres_{$name}_total counter\ncajeerlogs_postgres_{$name}_total ".(float)$row[$name]."\n";
            }
            if ($row['stats_reset']!==null) { $out.="# TYPE cajeerlogs_postgres_stats_reset_timestamp_seconds gauge\ncajeerlogs_postgres_stats_reset_timestamp_seconds ".(float)$row['stats_reset']."\n"; }
        } catch (\Throwable) {
            $out="# TYPE cajeerlogs_postgres_available gauge\ncajeerlogs_postgres_available 0\n";
        }
        $metrics=$manager->metrics();
        $attempts=($factory??$this->app->container()->get(ConnectionFactory::class))->attempts();
        // FPM creates a new instance per request, so these are scrape-local gauges, never counters.
        foreach (['connect_attempts'=>$attempts['attempts'],'connect_failed_attempts'=>$attempts['failed_attempts'],'connect_seconds'=>$connectSeconds??$metrics['connect_seconds']] as $name=>$value) {
            if ($value!==null) { $out.="# TYPE cajeerlogs_postgres_{$name} gauge\ncajeerlogs_postgres_{$name} ".$value."\n"; }
        }
        return $out;
    }
}
