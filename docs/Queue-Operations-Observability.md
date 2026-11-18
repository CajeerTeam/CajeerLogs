# CajeerLogs 0.5.9 — Queue Operations & Observability

This release extends the **existing PostgreSQL** durable `ingest-v1` queue. It does not introduce a second queue backend, rewrite historic SQL migrations, or change event ID and idempotency semantics. **Migration 0025 is mandatory** and must be applied during a coordinated API/worker maintenance window.

## Cluster-wide dispatch control

`queue_dispatch_control` persists a single row for `ingest-v1`. It is deliberately **independent** of the capacity-driven `queue_accounting.admission_paused` latch:

* **Dispatch paused** means workers **do not claim new jobs**. Authenticated async HTTP requests can still return `202` while capacity is available; already accepted jobs continue occupying queue capacity. Project and global backpressure may reject them with `503` once their budgets are exhausted. Synchronous ingest is unchanged.
* **Admission paused** means producers receive `503 queue_full` until hysteresis releases it; existing workers can still drain accepted jobs unless dispatch is also paused.
* Active transactions and already issued leases are not cancelled by dispatch pause. A worker completes the currently owned job(s). PostgreSQL `FOR SHARE` on the control row is held for the whole claim transaction; an operator's `UPDATE` must wait for earlier claims to commit. After pause commits, a new claim transaction cannot acquire another lease.
* **Drain** pauses dispatch and waits up to the requested seconds for *live* leases to disappear. Expired leases remain durable and are reported separately; they are intentionally **not recovered while paused**. Resume dispatch to recover them. A timed-out drain returns failure and leaves dispatch paused.

Operations must be performed by an operator with access to the server's trusted CLI, or an authenticated administrator with `system:read`, CSRF protection and recent authentication. The HTTP API requires a user session, never a machine token. Every effective state transition writes `queue.dispatch_paused` or `queue.dispatch_resumed` to the existing immutable audit chain in the **same transaction**. Repeated idempotent requests do not change the generation or emit new audit events.

```bash
php bin/cajeer queue:dispatch
php bin/cajeer queue:pause 'planned-maintenance' --confirm
php bin/cajeer queue:drain 120 --confirm
php bin/cajeer queue:resume --confirm
```

HTTP:

```http
GET /api/v1/system/queue/dispatch
POST /api/v1/system/queue/dispatch/pause
Content-Type: application/json
X-CSRF-Token: <session-token>

{"confirm":true,"reason":"planned maintenance"}
```

Resume with `POST /api/v1/system/queue/dispatch/resume` and `{"confirm":true}`. A successful response contains `paused`, `generation`, `reason`, `changed_at`, `changed_by`, `live_running`, `expired_leases`, `ready_jobs` and `age_seconds`. An operator can review the same state and explicitly confirm changes in **Admin → System → Queue Center**.

### Maintenance workflow

1. `php bin/cajeer queue:dispatch` and `queue:capacity`. Identify active/expired leases and pending jobs.
2. `php bin/cajeer queue:drain 120 --confirm`. If timeout occurs, investigate the remaining live leases; do **not** assume a timed-out drain resumed dispatch.
3. Perform planned service maintenance. Existing pending and dead jobs remain durable and are not cleaned up.
4. `php bin/cajeer queue:resume --confirm`; verify `queue:dispatch`, `queue:workers`, `queue:verify`, `/ready`, and ongoing completions.
5. Review audit entries, monitoring dashboards, and the new dispatch paused alert. Alerts do not automatically resume the queue.

## Durable delivery metrics

Migration 0025 adds per-project counters to the existing `queue_project_state` accounting rows and extends the same PostgreSQL job transition trigger, avoiding an extra project-state update in the hot path. Counters update on **committed** job state transitions, not speculative worker activity. No project UUIDs, event payloads, credentials, job IDs or worker UUIDs are Prometheus labels.

| Metric | Meaning |
|---|---|
| `cajeerlogs_queue_accepted_jobs_total` | New durable jobs admitted (not idempotent replays) |
| `cajeerlogs_queue_accepted_events_total` | Events in those newly accepted jobs |
| `cajeerlogs_queue_completed_jobs_total` | Jobs transitioned to `completed` |
| `cajeerlogs_queue_completed_events_total` | Events stored by those completed jobs |
| `cajeerlogs_queue_retries_total` | Automatic `running → pending` and operator `dead → pending` transitions |
| `cajeerlogs_queue_dead_letter_total` | Transitions into `dead`, including exhausted leases |
| `cajeerlogs_queue_lease_expirations_total` | Attempts actually closed as expired |
| `cajeerlogs_queue_delivery_latency_seconds` | Cumulative histogram of committed enqueue-to-complete duration (1, 5, 30, 300, +Inf seconds) |
| `cajeerlogs_queue_dispatch_paused` | Cluster-wide manual dispatch pause (0 or 1) |
| `cajeerlogs_queue_dispatch_paused_age_seconds` | Time since the current pause began; 0 while running |
| `cajeerlogs_queue_dispatch_generation` | Number of effective pause/resume changes |

**Counter epoch:** new counters start at zero when migration 0025 is applied. Existing in-flight jobs are preserved; their completions will be counted after the migration even if their original enqueue occurred before migration 0025. Do not compare those two totals as a lifetime invariant across the migration boundary. No historic event counts or latency samples are fabricated.

The `+Inf` histogram count equals the committed completion count; the sum is the accumulated duration in seconds. Example throughput and latency queries:

```promql
rate(cajeerlogs_queue_completed_events_total[5m])
rate(cajeerlogs_queue_accepted_events_total[5m])
histogram_quantile(0.95, sum by(le) (rate(cajeerlogs_queue_delivery_latency_seconds_bucket[5m])))
increase(cajeerlogs_queue_retries_total[5m])
```

Other existing metrics retain their semantics: `queue_project_pending`, `queue_project_running`, capacity ratios, oldest pending/dead job, expired leases, queue admission rejections and aggregate worker heartbeat. The `OperationsMetrics` read is one bounded aggregation of project-state rows, not a scan of all historic completed jobs. The existing time-window gauges for oldest pending/dead still require PostgreSQL queries.

New alerts cover dispatch intentionally paused for more than five minutes, retry and dead-letter spikes, queued work without active workers, and sustained lack of completions while dispatch remains enabled. Alerts do not trigger automatic destructive actions. Prometheus rule tests use the existing monitoring stack.

## Compatibility and security

* `migrations/pgsql/0001`–`0024` are byte-for-byte unchanged.
* `0025_queue_operations_metrics.sql` adds only operational state and counters and replaces the existing project accounting function with an extended implementation. It does not modify accepted jobs, receipts, credentials, existing leases or event data.
* All operator actions are auditable and protected by transaction boundaries. A failed audit insert aborts pause/resume.
* Backups from 0.5.8 can be restored and migrated to 0.5.9, but restoring a 0.5.9 backup to an older version is refused.
* Existing scheduler and retention policies, dead-letter replay/discard and SDK delivery recovery are unchanged.
* A real PostgreSQL/Docker load run is required to calibrate alert thresholds and confirm queue throughput. The source release retains `public-preview` until the full CI matrix passes.
