# Health Checks & Prometheus (0.4.10)

## Probe contracts

| Endpoint / command | Purpose | Failure |
|---|---|---|
| `GET /health` (also `/` and `/api/v1/health`) | PHP/configuration/storage runtime liveness; no SQL | 503 if runtime checks fail |
| `GET /ready` | Full database readiness: writable primary, fsync, migration checksums/catalog, grants, Restore Fence, required identity keys | 503 if any mandatory check fails |
| `GET /metrics` | Prometheus text exposition; runtime, SQL, queue, security and worker observations | 401 for an invalid configured bearer; unavailable SQL collectors report availability=0 |
| `php bin/cajeer health:ready` | Same database readiness policy for service managers | exit 1 on failure |
| `php bin/cajeer health:worker queue\|scheduler\|webhook\|backup` | Freshness of the last successful pass for the role | exit 1 if never, stale or unavailable |

Health endpoints have `Cache-Control: no-store` and return only the service, version, status and scope. The protected System Report retains detailed diagnosis. A stopped worker, failed backup, or expired external certificate is an operational alarm, not a reason to make PHP liveness depend on every service. A read-only or incompatible database fails readiness. Production HTTPS/Host enforcement and the metrics bearer remain in force.

Readiness and each metrics scrape create an isolated nonpersistent PostgreSQL session: one connection attempt, 2-second libpq connect timeout, 1500ms SQL statement timeout, 1000ms lock timeout, 3000ms TCP user timeout. Normal application/worker timeouts are unchanged. These are per-connect/per-statement limits, not a guaranteed overall wall-clock deadline (DNS, multiple SQL statements and OS I/O may take longer). Use a 15-second scrape timeout and an independent external probe. No query is issued by liveness. Metrics avoid a second readiness connection and omit SQL data when collectors fail; the `*_available` series distinguish unavailability from zero.

## Worker progress, not process existence

`storage/cache/heartbeat-{queue,scheduler,webhook,backup}` contains the last successful progress time. Atomic publication and a role-specific lock prevent partial writes and contention; the queue coalesces updates to at most once per five seconds per process. The queue publishes after a successful empty poll or processed job; scheduler after a successful pass (including no due tasks); webhook after a completed batch (individual delivery failures are separate metrics); backup after a successful `backup:run`, including `not_due`. Errors do not advance timestamps. Missing, malformed, linked or future-dated markers are never treated as healthy. These are aggregated **role-level** markers, not per-replica liveness; a healthy replica can mask a failed peer. Use service/container monitoring for per-replica health.

Stale thresholds: queue 120 seconds, scheduler 180 seconds, webhook 420 seconds, backup 14,400 seconds. Long operations exceeding these limits can alert; tune monitoring/rules for the workload rather than asserting progress for a hung job. All workers and PHP-FPM in the supplied Compose share the storage volume and run as the same UID. For host/systemd use the same service UID and cache directory; do not run workers as root. Separate hosts with separate storage need separate scrape targets. A cache reset clears heartbeat and process-shared counters. The optional backup worker is only expected by alerts when `MONITOR_BACKUP_ENABLED=true`; enable this in the application/production environment when running scheduled backups.

## Metrics

Existing ingest/search/identity request, rejection and histogram series, PostgreSQL counters, queue counts, scheduler tasks and retention metrics remain compatible. The new series are:

- `cajeerlogs_ready` — full readiness (0/1), independent of `cajeerlogs_up`.
- `cajeerlogs_worker_last_success_timestamp_seconds{role}`, `cajeerlogs_worker_stale_after_seconds{role}`, `cajeerlogs_worker_healthy{role}`, `cajeerlogs_worker_expected{role}` — bounded role labels, explicit zero for a never-run worker.
- `cajeerlogs_scheduler_last_success_timestamp_seconds{task}` and `cajeerlogs_scheduler_overdue_seconds{task}` now include all four fixed tasks even before their first run; zero last-success means never run. Retention alerts respect pause and maintenance windows. `cajeerlogs_retention_last_run_duration_seconds` exposes the last measured pass.
- `cajeerlogs_backup_status_available`, `cajeerlogs_backup_last_success_timestamp_seconds`, `cajeerlogs_backup_interval_seconds`, `cajeerlogs_backup_duration_seconds`, `cajeerlogs_backup_offsite_configured`, `cajeerlogs_backup_offsite_verified`, `cajeerlogs_backup_replication_pending`, `cajeerlogs_restore_last_success_timestamp_seconds`, `cajeerlogs_restore_duration_seconds` — read from existing backup schedule state, no dump/restore during scrape. Zero timestamp means no successful scheduled backup/rehearsal. Status availability is distinct from completion; backup/restore duration is omitted until measured. Backup duration measures the successful run (a successful retry does not include earlier failed runs). All backup metadata must be readable by the application UID; if using a different `BACKUP_DIR` for the backup worker, configure/mount the same directory in the HTTP app (do not mount the encryption key into the HTTP app unless needed by its existing configuration).
- `cajeerlogs_audit_write_failures_total`, `cajeerlogs_audit_metrics_available` — shared counter of caught audit INSERT/prepare failures, outside the failed DB transaction; never emits SQL or credentials. It is best-effort (errors also go to the application log). If cache state is unreadable the availability series is 0, not a fabricated zero counter.

No UUIDs, IPs, credential values, URLs, or log text are emitted as application labels. Application filesystem metrics remain available during SQL failure. PostgreSQL and application filesystem sizes are distinct. No automatic deletion or restart is driven by the monitoring stack.

## Optional monitoring stack

`deploy/monitoring/compose.yaml` runs Prometheus (30-day local TSDB), Blackbox Exporter, Alertmanager and Grafana. It is separate from the core deployment and makes real HTTPS requests to the public APP_URL. It requires Docker Compose and network access from the monitoring containers to the public origin; use a reachable hostname, not container-local `localhost`. The external probes verify TLS and HTTP 200 without redirects; Prometheus uses the bearer file and verified TLS. The Blackbox exporter also exposes `probe_ssl_earliest_cert_expiry`. Prometheus/Grafana/Alertmanager UI ports are bound to loopback only (9090/3000/9093); use SSH tunnels or a separately secured reverse proxy. Blackbox has no published port.

Prepare a protected config directory, a token file matching `PROMETHEUS_TOKEN` (32–256 URL-safe characters) and a Grafana password file. The default Prometheus/Blackbox/Alertmanager container UID/GID is 65534; grant read/traverse via that group without making secrets world-readable. Grafana defaults to UID 472; ensure its password file is readable by that UID. On Linux (run as a trusted operator with appropriate ownership privileges):

```bash
sudo install -d -m 0750 -o "$(id -u)" -g 65534 /etc/cajeer-monitor
# Create metrics-token privately using your secret manager/editor; do not put the token in shell history.
# Then set the ownership and mode so the monitoring container can read it:
sudo chgrp 65534 /etc/cajeer-monitor/metrics-token
sudo chmod 0640 /etc/cajeer-monitor/metrics-token
# Prepare /etc/cajeer-monitor/grafana-password as a separate private file (UID 472 or readable group).
python3 deploy/monitoring/prepare.py https://logs.example.com /etc/cajeer-monitor
export MONITOR_CONFIG_DIR=/etc/cajeer-monitor
export MONITOR_GRAFANA_PASSWORD_FILE=/etc/cajeer-monitor/grafana-password
docker compose -f deploy/monitoring/compose.yaml up -d
```

For private CA, place the trusted CA PEM at `/etc/cajeer-monitor/ca.pem`, readable by the monitoring containers, and add `--private-ca` to `prepare.py`. There is no skip-verification option. Custom application metrics path: `--metrics-path /custom-metrics`. Optional notification: put an HTTPS webhook URL in `webhook-url` (0640, group 65534) and use `--webhook`; otherwise Alertmanager retains alerts in its UI with no external notification receiver. Protect monitoring config/backups as sensitive. The generator does not embed token or webhook values in generated config. Restart monitoring containers after regenerating config. To verify rules locally with Docker:

```bash
docker compose -f deploy/monitoring/compose.yaml exec -T prometheus \
  promtool check config /etc/cajeer/prometheus.json
```

The provisioned Grafana dashboard `CajeerLogs Operations` visualizes readiness, worker health, backlog, ingest/search, auth limits, backup age, retention, PostgreSQL, storage and certificate lifetime. Alert rules detect failed scrape, HTTPS liveness/readiness failure, stale workers, queue backlog/leases, overdue scheduler and retention, backup/restore freshness, replication pending, storage warning, certificate expiry, elevated login failures and audit write failures. Thresholds are baseline defaults, not measured production SLAs. For backup age/restore alerts, set `MONITOR_BACKUP_ENABLED=true` in application configuration. Use `promtool test rules deploy/monitoring/alerts.test.yml` from the repository root with Prometheus installed to exercise never-run, stale worker and paused-retention logic.

## Upgrade and verification

No new database migration. 0.4.9 backups remain compatible. After applying the changed-files overlay, rebuild/restart the application and workers so the heartbeat writers are active. Run `php bin/cajeer health:ready`, verify role heartbeats after their first successful passes, and inspect `/metrics` with your metrics bearer over HTTPS. If the database is stopped, `/health` should remain 200, `/ready` 503 and SQL availability metrics 0. Never stop a production database solely to test an alert; use the disposable test stack.

`tests/health_monitoring.php`, `tests/monitoring_config.py`, HTTP/SPA checks and the exact release ZIP gate cover local state/format/configuration. The release gate runs actual `promtool` rule/config tests, `amtool` config validation, Prometheus and Blackbox Exporter against the real production TLS gateway, then stops the queue and PostgreSQL to observe stale heartbeat/readiness/liveness. The full PostgreSQL and Docker test matrix must pass before stable certification. Grafana provisioning is supplied but not a separate browser-certified UI.
