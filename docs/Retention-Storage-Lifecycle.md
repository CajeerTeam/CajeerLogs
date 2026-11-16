# Retention & Storage Lifecycle — 0.4.9

## Upgrade

Stop writers and maintenance processes, make and verify a backup on 0.4.8, overlay 0.4.9, run `php bin/cajeer upgrade:check BACKUP_ID`, `php bin/cajeer migrate`, `php bin/cajeer migrate:verify`, `php bin/cajeer db:verify`, `php bin/cajeer audit:verify`, `php bin/cajeer doctor`. Migration `0021_retention_execution_history.sql` is required; prior migrations remain unchanged. Runtime requires SELECT/INSERT/UPDATE/DELETE on retention_runs and USAGE on its identity sequence (default grants of split-role installer already cover this; existing DBA-managed installations must grant explicitly). The migration creates empty history; counters start at zero, not historical estimates.

## Policies and destructive actions

Retention remains opt-in. A project or source with NULL has no policy of its own. A log expires when **either** its project or source retention is exceeded, using `received_at`, not client occurrence time. Source policy cannot extend a shorter project policy. Archived/disabled resources still follow configured retention. No automatic global default is imposed on existing projects. Change policy via the existing project/source API/UI or `retention:set PROJECT DAYS|off`. A policy reduction is destructive after the next scheduler pass; pause scheduled retention (`RETENTION_SCHEDULER_ENABLED=false` on scheduler instances), back up, and inspect impact before applying a shorter policy. A preview describes **current** policies, not a hypothetical change. Re-enabling scheduled retention resumes pruning. Other maintenance remains active. Optional `RETENTION_WINDOW_UTC=23:00-03:00` restricts scheduled starts to a half-open UTC daily window (including midnight crossing). Empty means all day. Manual `retention:run` and `retention:cycle` deliberately override scheduler pause/window; stop them separately. Do not treat an export or a cursor as protection from expiration.

```
php bin/cajeer retention:list
php bin/cajeer retention:preview 1000
php bin/cajeer retention:preview 1000 PROJECT_UUID
php bin/cajeer retention:run 1000
php bin/cajeer retention:cycle --confirm
php bin/cajeer retention:history
```

Preview uses a read-only transaction, indexed per-policy candidate scans, deduplication, and a capped global sample. It returns `candidates` (at most requested limit), `more` (at least one additional candidate), `oldest_received_at` and scope. It never locks/deletes event rows or writes history. Results are a point-in-time estimate; concurrent ingestion and changed policies alter later results. The scan is bounded per policy, not independent of number of policies, and has a statement timeout. Unknown project is rejected.

Each actual pass uses a separate transaction and the existing retention advisory lock (same lock used by policy edits). Candidate rows use SKIP LOCKED; locked old rows may remain. A capped candidate stream can return a short batch even with more expired rows, so short batch is not proof of zero backlog. Scheduler runs one bounded pass per minute. Manual cycle repeats passes up to the configured batch count/time budget, sleeps outside transactions, stops on a short batch, and preserves already committed passes on error. Budget is a soft overall deadline: individual lock waits/SQL/commit/connection work can extend it; SQL statements have their own timeout. No VACUUM FULL or disk-driven deletion is performed.

## Configuration

| Environment | Default | Range |
|---|---:|---|
| RETENTION_SCHEDULER_ENABLED | true | true/false |
| RETENTION_WINDOW_UTC | empty | HH:MM-HH:MM UTC |
| STORAGE_WARN_BYTES | 1073741824 | 0 disables warning |
| RETENTION_BATCH_SIZE | 1000 | 1–10000 |
| RETENTION_TIMEOUT_MS | 5000 | 100–60000 |
| RETENTION_CYCLE_BATCHES | 10 | 1–100 |
| RETENTION_CYCLE_BUDGET_MS | 30000 | 100–300000 |
| RETENTION_PAUSE_MS | 100 | 0–5000 |
| WEBHOOK_RETENTION_DAYS | 30 | 1–3650 |
| QUEUE_RETENTION_FLOOR_SECONDS | 0 | 0–31536000 |

Queue cleanup deletes completed/cancelled ingest jobs only after the greater of the accepted per-job delivery retention and the optional floor. Pending/running/dead jobs are preserved. Webhook cleanup affects terminal delivered/failed/cancelled rows only; pending/sending remain. Set webhook retention consciously; deleted delivery history is not recoverable except from backup. Auth cleanup still respects existing session, nonce and receipt expiration; never shorten their accepted lifetimes. Audit chain is not pruned; backup retention remains a distinct confirmed operation.

## Monitoring and troubleshooting

History stores the last 1000 passes, including failed passes when the database is available. Success is written in the same transaction as deletion, so rollback cannot claim removal. Cumulative total persists across history trimming. Failure history is best effort if the DB/connection is unavailable; scheduler logs and exit status remain authoritative in that case. `oldest_received_at` is oldest candidate **before** deletion; `backlog_lower_bound` is the sampled candidate count minus removed rows (at least zero), not a live exact count. A zero lower bound does not guarantee no backlog. `sample_capped` indicates more candidates than the batch limit. `duration_ms` measures batch work before history/commit, not a wall-clock SLA.

System report shows last 10 passes, limits, estimated live/dead rows, last autovacuum and application storage free space. `php bin/cajeer db:performance` supplies real PostgreSQL table/index sizes, tuple estimates and VACUUM statistics. Dead tuples are not measured bloat. PostgreSQL storage may reside on a different disk than application storage; monitor it separately. Deleted rows may not release filesystem space immediately. Do not run blocking VACUUM FULL automatically. Check autovacuum and disk/WAL capacity with DBA tooling.

Prometheus:
- `cajeerlogs_retention_deleted_events_total`: counter since migration (restoring backup may reset it).
- `cajeerlogs_retention_last_run_timestamp_seconds`: gauge, 0 before first pass.
- `cajeerlogs_retention_last_run_success`: 1 on successful pass, 0 on failure/never run.
- `cajeerlogs_retention_backlog_lower_bound`: gauge present only when last pass succeeded.
- `cajeerlogs_log_estimated_live_rows`, `cajeerlogs_log_estimated_dead_rows`: PostgreSQL statistics.

Application disk gauges `cajeerlogs_app_storage_free_bytes` and `cajeerlogs_app_storage_low_space` use the configured warning threshold, not the PostgreSQL volume. `cajeerlogs_retention_scheduler_enabled` and `cajeerlogs_retention_window_open` distinguish a paused or closed-window scheduler; do not alarm on deliberate inactivity.

Use `cajeerlogs_operations_available` to detect missing DB metrics, scheduler overdue series for task freshness, and alert on stale/missing last run rather than assuming absence means healthy. Repeated full batches/positive backlog indicate the need to adjust policy/batch throughput after measuring ingest/search latency. For failure, inspect scheduler log and DB locks/statement timeout, correct cause, then retry. Never delete beyond configured retention to free disk.
