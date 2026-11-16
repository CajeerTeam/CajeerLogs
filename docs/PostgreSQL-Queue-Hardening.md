# 0.5.1 PostgreSQL Queue Hardening

The PostgreSQL ingest queue remains the only supported durable backend. Upgrade from
0.5.0 during maintenance: stop HTTP writers and workers, verify a backup, overlay the
release, run `php bin/cajeer migrate`, `migrate:verify`, `queue:verify`, then restart.
Migration 0022 rewrites jobs to add stored generated metadata; allow space and time
for the rewrite and WAL. No historical payload, lease, result or receipt is discarded.

## Admission and accounting

`QUEUE_MAX_JOBS` still counts pending, running and dead jobs. Terminal completed and
cancelled jobs do not consume capacity. Each ingest INSERT locks the singleton
accounting row before checking capacity, then the AFTER trigger adds the new job's
actual JSONB bytes and event count in the same transaction. Completion/discard
subtracts capacity in the same transaction; retry and claim leave capacity unchanged.
The table lock order (jobs before counter) also protects explicit reconciliation.
All writers must use the same queue limit; direct privileged SQL is outside the
admission API. The counter tracks active payload JSONB text bytes, not physical disk
or WAL bytes. A malformed historical payload without an array contributes zero events
but still contributes its bytes and a job. Existing decoding limits remain enforced.

`queue:capacity` reports exact active counts and the configured job limit;
`queue:verify` performs a read-only full aggregate in one MVCC statement, returning
nonzero on mismatch. `queue:reconcile --confirm` takes a write-blocking table lock and
repairs only the accounting row. Run it only during maintenance after investigating
any drift. It never edits or deletes jobs. Readiness checks the row exists; the full
scan is deliberately not part of every probe. `operations:check` and `db:verify` verify accounting.
Restore verifies it before publishing a target database. The trigger rejects TRUNCATE
jobs; use the existing supported lifecycle operations. Privileged owners must not
modify the accounting table or disable triggers outside controlled maintenance.

Claim uses the existing partial lease and ready indexes in separate queries, recovering
expired leases first and then ready jobs. SKIP LOCKED and lease fencing are unchanged;
strict FIFO is not promised. Claim/retry do not update the singleton counter. The
singleton still serializes capacity-changing transactions, but eliminates the hot
path count scan and advisory lock; no throughput improvement is claimed without a
real baseline. New metrics: `cajeerlogs_queue_active_jobs`, `_active_events`,
`_active_bytes`, `cajeerlogs_queue_max_jobs` (all gauges, shared database values).

Run `CAJEER_TEST_DATABASE=1 php tests/queue_database.php` on disposable PostgreSQL,
plus the release database gate with the actual 0.5.0 tree. Production certification
still requires the full PHP/PostgreSQL matrix and Docker smoke. Per-project quotas,
byte/event hard limits, worker scaling and throughput benchmarks are subsequent
releases; this release preserves existing HTTP semantics and delivery guarantees.
