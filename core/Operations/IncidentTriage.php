<?php
declare(strict_types=1);

namespace Cajeer\Logs\Operations;

use Cajeer\Logs\Kernel\Application;

/** Read-only incident classification based on live, existing operations checks. */
final class IncidentTriage
{
    /** Ordered rules. A missing/failed critical dependency cannot be downgraded to a warning. */
    private const RULES = [
        'runtime' => ['critical','runtime','Runtime or local storage is unhealthy'],
        'database_readiness' => ['critical','database','PostgreSQL is not ready'],
        'production_https' => ['critical','security','Production HTTPS requirement failed'],
        'production_metrics_protected' => ['critical','security','Metrics exposure policy failed'],
        'production_admin_totp' => ['critical','security','Admin MFA policy failed'],
        'production_idle_timeout' => ['critical','security','Session timeout policy failed'],
        'production_recent_authentication' => ['critical','security','Reauthentication policy failed'],
        'audit_integrity' => ['critical','security','Audit chain verification failed'],
        'queue_accounting' => ['critical','queue','Durable queue accounting is inconsistent or unavailable'],
        'storage_headroom' => ['critical','runtime','Local storage headroom is insufficient'],
        'retention_progress' => ['warning','retention','Retention did not complete in its configured window'],
        'backup_fresh' => ['critical','backup','Verified backup is missing, stale or replication is pending'],
        'restore_rehearsal_fresh' => ['warning','backup','Last recorded restore rehearsal is stale'],
        'backup_status_readable' => ['critical','backup','Backup state is unavailable'],
        'backup_archive_verified' => ['critical','backup','Requested backup archive is invalid or incompatible'],
        'backup_offsite_verified' => ['critical','backup','Requested offsite copy is invalid or unavailable'],
    ];

    /** Links point to shipped, executable runbooks, never to automatically executed repairs. */
    private const RUNBOOKS = [
        'runtime' => 'docs/runbooks/runtime-outage.md',
        'database' => 'docs/runbooks/database-outage.md',
        'queue' => 'docs/runbooks/queue-backlog.md',
        'backup' => 'docs/runbooks/backup-disaster.md',
        'security' => 'docs/runbooks/security-incident.md',
        'retention' => 'docs/runbooks/retention-storage.md',
    ];

    public function __construct(private readonly Application $app) {}

    public function collect(?string $backupId = null): array
    {
        // Never convert an unavailable check into success, or expose a raw exception (may contain credentials).
        try {
            $raw = (new OperationsCheck($this->app))->run($backupId);
            return self::classify($raw, gmdate('Y-m-d\TH:i:s\Z'));
        } catch (\Throwable) {
            return self::classify(['version' => $this->app->version(),
                'checks' => ['runtime' => false, 'database_readiness' => false],
                'skipped' => [], 'status' => 'failed'], gmdate('Y-m-d\TH:i:s\Z'));
        }
    }

    /** Pure classification allows incident policy regression testing without a live database. */
    public static function classify(array $source, string $timestamp): array
    {
        $checks = $source['checks'] ?? null;
        if (!is_array($checks) || $checks === []) {
            throw new \InvalidArgumentException('Operations checks are missing.');
        }
        $critical = []; $warnings = []; $procedures = [];
        $statuses = [];
        foreach ($checks as $name => $passed) {
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $name) || !is_bool($passed)) {
                throw new \InvalidArgumentException('Invalid operations check result.');
            }
            $statuses[$name] = $passed;
            if ($passed) continue;
            [$severity, $topic, $message] = self::rule($name);
            $issue = ['check' => $name, 'reason' => $message, 'runbook' => self::RUNBOOKS[$topic]];
            if ($severity === 'critical') $critical[] = $issue; else $warnings[] = $issue;
            $procedures[$topic] = self::RUNBOOKS[$topic];
        }
        $skipped = $source['skipped'] ?? [];
        if (!is_array($skipped)) throw new \InvalidArgumentException('Invalid skipped checks.');
        $skipped = array_values(array_unique(array_filter($skipped, static fn($x) => is_string($x) && preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $x))));
        sort($skipped, SORT_STRING);
        $version = $source['version'] ?? '';
        if (!is_string($version) || !preg_match('/^\d+\.\d+\.\d+$/D', $version)) {
            throw new \InvalidArgumentException('Invalid operations report version.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $timestamp)) {
            throw new \InvalidArgumentException('Invalid snapshot timestamp.');
        }
        return [
            'format' => 1, 'scope' => 'operations-triage', 'version' => $version,
            'checked_at_utc' => $timestamp,
            'status' => $critical ? 'critical' : ($warnings ? 'degraded' : 'ok'),
            'checks' => $statuses, 'skipped' => $skipped,
            'critical' => $critical, 'warnings' => $warnings,
            'runbooks' => array_values($procedures),
            'note' => 'Read-only observation; no remediation or production cutover performed.',
        ];
    }

    public static function exitCode(array $report): int
    {
        return match ($report['status'] ?? null) {
            'ok' => 0,
            'degraded' => 1,
            default => 2,
        };
    }

    private static function rule(string $name): array
    {
        if (isset(self::RULES[$name])) return self::RULES[$name];
        if (str_starts_with($name, 'worker_')) {
            return ['warning','queue','Worker heartbeat is stale, missing or unavailable'];
        }
        // Unknown future checks always fail closed, never silently disappear from incident evidence.
        return ['critical','runtime','Unknown failed operations check; investigate before declaring readiness'];
    }
}
