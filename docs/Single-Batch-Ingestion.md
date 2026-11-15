# Single & Batch Ingestion — 0.2.2

The existing API v1 accepts a single event object or `{ "events": [...] }` with 1–100 events. Both POST `/api/v1/ingest` and `/api/v1/ingest/async` use the same HTTP validation, scope resolution, redaction and normalized batch checks. Existing SDK wire formats and successful responses are unchanged. Async requires an Idempotency-Key. Sync 201 means committed log rows; async 202 means committed job, not completed log rows. Poll the returned Location for completion. Replays return 200 and the same IDs/job; keep the exact request body and key and generate a new HMAC nonce. The idempotency scope and retention windows have not changed.

## Limits and atomicity

- HTTP body: 1 MiB (or smaller application/project/source limits); original event 64 KiB; 100 events. Existing field/context limits still apply.
- Normalized SQL representation: 4 MiB including UUID and source mapping; durable compact JSON including the source map: 2 MiB. A second limit of 8 MiB on PostgreSQL JSONB's textual representation bounds worker transfer/decoding, including expanded numeric exponents and JSONB whitespace. A new job exceeding it is rejected with 413 before commit. A pre-existing oversized or malformed durable job is dead-lettered; inspect and repair or explicitly discard it, never silently truncate.
- A single source map must cover exactly the batch's registered slugs. Numeric slugs are emitted as a JSON object; historical contiguous numeric maps encoded as arrays still decode. Queue payloads are revalidated and redacted at processing; corrupted field types and unknown fields are rejected.
- Mixed batches lock source rows by UUID in a single SELECT FOR UPDATE, validate all policies, then update counters in one UPDATE. Per-source quota buckets remain transactional and ordered; project quota and accepted counters roll back on any failed write. Rejected-policy telemetry commits independently of accepted counters through the existing savepoint.
- Async replay skips admission and queue capacity checks. A new job serializes and checks its durable payload before taking source and queue capacity locks. The hard queue capacity still includes dead jobs; resolve or discard dead jobs to recover capacity.

## Worker recovery

A committed claim has a two-minute lease. Worker locks the job row and rechecks lease validity after the lock is acquired. The row lock fences other workers throughout processing, even if a long successful operation passes the nominal lease deadline. Logs, webhook outbox, completion result and attempt history commit atomically. An expired claim that has not started processing cannot write or change failure state. Missing running attempt history causes rollback rather than false completion.

A structurally invalid, oversized or out-of-scope durable payload moves to dead-letter immediately (safe `invalid_payload` code). Transient SQL errors retain existing bounded backoff/attempt behavior. The operator can inspect `queue:list dead`, `queue:history ID`, then use `queue:retry ID` after repair or `queue:discard ID`. Do not edit payloads on a live running job. A connection loss or ambiguous COMMIT is not converted into automatic replay; the next worker reads committed state or reclaims an expired lease. Previously admitted jobs can finish after token revocation or source archival as before.

## Upgrade and verification

Backup before overlaying changed files on 0.2.1; stop web ingest and workers, run `php bin/cajeer version`, `php bin/cajeer migrate:verify`, `php bin/cajeer db:verify`, `php bin/cajeer openapi:lint`, then restart services. No new migration or required environment setting. Backups from 0.2.1 retain compatible migration history.

Run `php tests/batch_ingestion.php`, `php tests/queue.php`, `php tests/log_repository.php`, `php tests/sources.php`, and `python3 tests/http_runtime.py`. On a disposable real PostgreSQL database, `CAJEER_TEST_DATABASE=1 php tests/queue_database.php` exercises mixed batches, counters, malformed payloads, lease fencing, and rollback after backend termination during processing. The release gate uses 0.1.0 and 0.2.1 source trees (`--from-010`, `--from-021`) with `scripts/certify.py`, PostgreSQL 17/18 and PHP 8.4/8.5. Use `scripts/benchmark.py` on a dedicated test project for actual throughput and latency; reduced round trips are not a measured speedup. Public Preview until the real PostgreSQL and production smoke gates pass.
