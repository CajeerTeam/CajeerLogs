# Worker Runtime & Concurrency — 0.5.2

The production queue uses independent PHP CLI processes and PostgreSQL durable jobs. No Redis or new schema migration is required. `queue:work N` is still a finite batch (N=1..10000, exits when empty). `queue:work` is a single recyclable continuous worker. Use `queue:pool` for production: it supervises and replaces children, including after a normal resource recycle.

## Run and inspect

```sh
php bin/cajeer queue:pool       # configured process count (default 2)
php bin/cajeer queue:pool 4     # explicit override for this invocation
php bin/cajeer queue:workers   # shared-cache registry, no DB connection
php bin/cajeer queue:pool-health # local hostname, expected configured count
```

`queue:pool-health` is intended for the supplied single-pool-per-container deployment. Its default expected count is `QUEUE_WORKER_PROCESSES`; pass `queue:pool-health 4` if running `queue:pool 4` with a different configured value. It checks fresh progress and local PID identity (Linux process start ticks, POSIX fallback). Multiple pools on the same host may contribute to the local count; use separate container hostnames for isolation. `queue:workers` and System Report expose per-process progress; the shared-storage registry is observational, not a distributed scheduler or lease authority. It does not establish liveness for a process on another host between heartbeat updates. The aggregate legacy `health:worker queue` retains its 120-second threshold. Individual worker staleness is max(120, lease+30) seconds. Long processing transactions or slow startup may temporarily report unhealthy. No new process UUID labels in Prometheus.

## Policy

| Environment | Default | Meaning |
|---|---:|---|
| QUEUE_WORKER_PROCESSES | 2 | 1–32 per pool (pcntl, posix, proc_open required) |
| QUEUE_WORKER_MAX_JOBS | 1000 | claim outcomes before recycle; 0 unlimited |
| QUEUE_WORKER_MAX_SECONDS | 3600 | elapsed lifetime before recycle; 0 unlimited |
| QUEUE_WORKER_MEMORY_MB | 256 | PHP allocated-memory threshold at operation boundaries; 0 unlimited, otherwise >=32 |
| QUEUE_POLL_MIN_MS | 50 | initial idle delay ceiling |
| QUEUE_POLL_MAX_MS | 1000 | idle delay ceiling |
| QUEUE_LEASE_SECONDS | 120 | persisted lease duration |
| QUEUE_CLAIM_TIMEOUT_MS | 5000 | transaction/statement limit for claim/failure recording |
| QUEUE_PROCESS_TIMEOUT_SECONDS | 90 | PostgreSQL transaction limit for processing |
| QUEUE_SHUTDOWN_GRACE_SECONDS | 120 | supervisor drain deadline |

Poll ceilings double after empty polls, with half-to-full jitter, never below the configured minimum; successful claim resets the delay. Connection recovery retains existing bounded retry behavior. Processing timeout must be at least five seconds below lease duration; shutdown grace must be at least processing timeout. Claim timeout must be shorter than lease. Transaction timeouts are scoped with SET LOCAL; PostgreSQL 17+ may terminate a session on transaction timeout. An uncertain transaction is never replayed by the worker; durable state/lease recovery is authoritative. SQL transaction deadlines do not bound DNS/connect, PHP CPU time, or total retries. Memory and lifetime are recycling thresholds, not hard per-job limits: retain PHP/container memory and connection limits.

SIGTERM/SIGINT stops new claims; if a signal arrives during claim, the worker completes that already-owned job. No sleep holds a migration guard. Supervisor sends SIGTERM and waits up to the configured grace, then SIGKILLs remaining children, exits nonzero, and leaves any durable unfinished lease for recovery. Use a service manager that also kills the process group on hard supervisor failure. POSIX child workers detect a lost parent at operation boundaries. Normal recycling does not change job IDs or delivery receipts.

## Production

`compose.production.yaml` runs `queue:pool` with default two children and a per-container health check. Scale `docker compose --env-file .env.production -f compose.production.yaml up -d --scale queue=2 queue` for four children. Budget DB connections and CPU for each worker and API process. The default container stop grace is 130s; if increasing worker grace, increase `CAJEER_QUEUE_STOP_GRACE` (and systemd TimeoutStopSec) above it. The supplied systemd service runs a pool and uses KillMode=mixed. Use `queue:work N` for finite cron only; do not accidentally run extra pools. Restart pools to apply configuration changes. Run `queue:verify` after maintenance or restore.

Per-instance markers are private files in shared `storage/cache`. Normal exits remove them; stale crashed markers are pruned on later starts after 24h. Registry scans are bounded to 1024 records and report truncation/invalid markers; keep storage private and monitor registry availability. The gauges `cajeerlogs_queue_workers_active`, `cajeerlogs_queue_worker_registry_available` and `cajeerlogs_queue_worker_registry_invalid` describe shared storage, not cluster-wide unique Prometheus replicas. Do not sum duplicate shared-storage scrapes. No automatic scale-up based on backlog is introduced.

## Verification

`php tests/worker_runtime.php` launches actual supervisor/child processes and tests restart, drain, forced kill, policy validation, and individual heartbeat behavior without PostgreSQL. The upgrade gate and production smoke exercise real queue processing with multiple processes. Throughput must be measured on the deployed hardware; no linear scaling or hard SLA is claimed.
