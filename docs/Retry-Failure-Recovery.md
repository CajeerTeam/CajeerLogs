# Retry & Failure Recovery — 0.5.3

PostgreSQL remains the authoritative queue. HTTP 202 means the job and its attempt budget are committed, not that events have been stored. Worker processing commits events, outbox, completion, attempt history and accounting together. A lost connection or ambiguous COMMIT is never replayed blindly.

## Configuration

All producers and workers should use the same environment. New jobs persist `QUEUE_MAX_ATTEMPTS` (1–100, default 8). Existing jobs retain the budget accepted on the original release; replay does not reset it. Manual `queue:retry ID` adds the configured budget to the existing attempt count, capped at 100 total attempts. `queue:discard` remains explicit and retains the cancellation receipt.

| Variable | Default | Range |
|---|---:|---|
| QUEUE_MAX_ATTEMPTS | 8 | 1–100 |
| QUEUE_RETRY_BASE_SECONDS | 5 | 1–3600 |
| QUEUE_RETRY_MAX_SECONDS | 3600 | 1–86400, >= base |
| QUEUE_RETRY_JITTER_PERCENT | 25 | 0–100 |
| QUEUE_OUTAGE_BASE_MS | 250 | 50–10000 |
| QUEUE_OUTAGE_MAX_MS | 30000 | 50–60000, >= base |
| QUEUE_OUTAGE_MAX_FAILURES | 10 | 1–100 |

For attempt n, cap the exponential base `base * 2^(n-1)` at the maximum. Draw an integer delay from `[max(1, base - floor(base*jitter/100)), min(maximum, base + floor(base*jitter/100))]`. Zero jitter is deterministic. The delay is persisted as `available_at` using PostgreSQL time. A final or invalid job is dead immediately, with payload preserved. Delay configuration applies when a failure is recorded, not retroactively to jobs already waiting.

## Failure and recovery

- Malformed durable payload / invalid scope (HTTP 403, 413, 422 or JSON error): dead, `invalid_payload`.
- Known rollback due to statement timeout, lock timeout or transaction conflict: schedule retry, with safe reason. TransactionManager retains its separate bounded conflict retries within an attempt.
- Other database or processing errors: retry within the persisted budget. No exception messages or SQL arguments stored in history.
- Connection loss, unknown COMMIT, read-only, capacity, authentication/TLS, schema/permission errors: propagate, do not write an ordinary retry on the failed session. Reconnect at the operation boundary. Continuous CLI workers use jittered exponential outage backoff and run database readiness on a fresh session before another claim. After the configured consecutive-failure limit the process exits and the supervisor applies its restart backoff. Finite `queue:work N` fails on infrastructure errors rather than waiting indefinitely.
- Expired lease: lock the job, close its running attempt as `expired`, and fence the old lease. If the budget is exhausted, move to dead. Otherwise schedule recovery at the old lease deadline plus the retry delay. If already due, claim immediately with a new lease; otherwise return to pending and claim later. This does not release queue capacity. The finite worker's `skipped` count includes these recovery transitions.

The retry delay is measured from the expired deadline, so a prolonged outage does not unnecessarily delay all old jobs again. Readiness does not detect every resource failure (for example, filesystem exhaustion); this is a per-worker cooldown, not a cluster-wide circuit breaker or a global retry-rate budget. Repeated crashes still consume job attempts. Infrastructure repair and operator intervention may be necessary; dead jobs are not silently discarded.

## Operations

`php bin/cajeer queue:workers` includes configured `retry_policy`; System Report displays it. `queue:list dead`, `queue:history ID`, `queue:retry ID` and `queue:discard ID` remain available. If a database failure is detected, check `health:ready`, `queue:workers` and `queue:verify`; inspect the safe failure category and fix the dependency before retrying dead jobs. Never manually delete active jobs or reset a lease while a worker is processing it. Stop the pool before exceptional administrative maintenance.

No migration is needed from 0.5.2. Back up first, stop old workers, overlay the patch, verify migration and queue integrity, then restart the API and queue pool together. The release gate includes actual 0.5.2 upgrade and PostgreSQL queue lifecycle tests. Local process/unit tests do not substitute for PostgreSQL concurrency and production certification.
