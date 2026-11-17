# CajeerLogs 0.5.6 — Backpressure & Admission Control

PostgreSQL remains the only durable ingest queue. **All producers must use identical limits**. The shared `queue_accounting` row and transaction/trigger make admission globally serialized and exact, including concurrent PHP-FPM replicas. Pending, running, and **dead** jobs consume all three resources until completion/discard. Completed/cancelled jobs do not consume queue capacity.

## Runtime configuration

```dotenv
QUEUE_MAX_JOBS=1000
QUEUE_MAX_EVENTS=100000
QUEUE_MAX_BYTES=536870912
QUEUE_HIGH_WATERMARK_PERCENT=85
QUEUE_LOW_WATERMARK_PERCENT=60
```

`QUEUE_MAX_JOBS` is 1–100000. `QUEUE_MAX_EVENTS` defaults to 100000; 0 (disabled) or 1–1000000000. `QUEUE_MAX_BYTES` defaults to 536870912; 0 (disabled) or 1–1099511627776. Bytes refer to `octet_length(payload::text)` of PostgreSQL JSONB, **not** raw HTTP bytes, TOAST, heap, or WAL disk usage. Events are the count stored in the normalized `events` array. Disabled dimensions are not enforced but are still accounted in PostgreSQL. High watermark is 1–100%, low watermark 0–99% and strictly lower than high.

The operator MUST size these limits for actual PostgreSQL storage, WAL, network and disk budget. A high watermark of 85% stops new admissions once a successful enqueue reaches the threshold on **any** enabled dimension. The stop latch is stored in PostgreSQL. While latched, new jobs are rejected until **all** enabled resource counts fall to or below their 60% low watermarks. The first next admission checks and clears a drained latch. Individual hard limits are also checked on prospective jobs, so a single large job cannot overshoot capacity even if the queue is under its high watermark. A job cannot bypass a hard limit by being accepted concurrently on another PHP replica.

The shared row is locked in the same order as queue reconciliation (jobs table, then accounting row). Triggers on `jobs` update active jobs/events/bytes in the same transaction, so failed transactions and expired leases cannot leak capacity. A queue-full denial persists a rejection counter and latch in the outer HTTP transaction **without** applying project/source event quotas. A replay of a previously accepted `Idempotency-Key` is resolved before admission; it returns the existing job even when the queue is paused. Direct queue callers must run in a transaction and roll it back on a returned exception. No accepted job or pending/dead payload is discarded automatically to relieve pressure.

**Status:** HTTP 503 `queue_full` with `Retry-After: 5` for hard or hysteresis limits. HTTP 429 remains request rate limiting; HTTP 413 remains invalid/oversized request. HTTP 200 replay remains available. The byte/event limits apply to *async queue admission*. Synchronous requests continue to use their existing concurrent-ingest/project/source budgets.

## Operations

```bash
php bin/cajeer migrate              # required migration 0023
php bin/cajeer queue:capacity      # counts, limits, latch, rejection counters
php bin/cajeer queue:verify        # exact MVCC comparison against jobs
php bin/cajeer queue:status
php bin/cajeer health:ready
```

`queue:reconcile --confirm` remains an explicit maintenance operation; it recomputes only queue counts under the required table lock, preserving the latch and rejection history. To relieve pressure, allow workers to complete jobs, investigate dead-letter, or explicitly retry/discard after RBAC review. **Do not truncate jobs or manually reset accounting.** A full dead-letter queue can prevent new ingress until an operator resolves dead jobs. Adjusting limits after deployment changes capacity immediately; an existing latch resumes only after the new low thresholds have been met.

`/metrics` and the protected System Report expose `queue_active_jobs`, `queue_active_events`, `queue_active_bytes`, `queue_max_jobs`, `queue_max_events`, `queue_max_bytes`, `queue_admission_paused`, `queue_rejected_{jobs,events,bytes,paused}` `queue_admission_rejections_total{reason="..."}` and `queue_capacity_ratio` (highest utilization among enabled dimensions, may exceed 1 when upgrading from a larger queue). Metrics are DB-derived; no per-project labels are added.

## Failure and upgrade

Migration `0023_queue_backpressure.sql` adds the durable latch/rejection counters only. Migration `0022` and historical jobs are unmodified. Upon upgrade, the latch starts false; the first incoming request reconciles it against the configured high watermark. Changes to configured watermarks require all API replicas to restart with the same settings. Restored copies keep the latch and rejection counters while `queue:verify` checks jobs/events/bytes against actual jobs.

To test real saturation, use an isolated PostgreSQL schema and two independent PHP connections: fill jobs, then events, then byte budgets; try an oversized batch and concurrent admission; drain below low; verify that replay still returns the previous ID and `queue:verify` stays valid. Never disable `fsync`/WAL for an apparently faster benchmark.
