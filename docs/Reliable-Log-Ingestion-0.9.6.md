# Reliable log ingestion — 0.9.6

The PostgreSQL-backed `ingest-v1` queue is the durable delivery path. HTTP `202` confirms the job was committed, **not** that the log is searchable. Poll `/api/v1/ingest/jobs/{id}` to `completed` and inspect `result.event_ids`; a retried request with the **same original body** and `Idempotency-Key` returns the existing receipt. The synchronous route acknowledges the committed event IDs in HTTP `201`. Preserve original idempotency keys across network timeouts; do not issue new keys for ambiguous writes.

## Production deployment

Run `php bin/cajeer queue:pool` under the provided systemd/Docker supervisor. PostgreSQL 17+ is required; worker process and lease timeouts must satisfy `QUEUE_PROCESS_TIMEOUT_SECONDS + 5 <= QUEUE_LEASE_SECONDS`. Configure `QUEUE_MAX_JOBS`, `QUEUE_MAX_EVENTS`, and `QUEUE_MAX_BYTES` based on measured operational capacity and monitor `queue:capacity`, `queue:workers`, `queue:pool-health`, `queue:verify`, and `queue:project-verify`. HTTP 503 `queue_full`/`queue_project_full` means no job was admitted: respect the `Retry-After` response and retain the same payload/key. A `dead` job counts against queue capacity until explicitly retried and successfully delivered, or discarded; no silent data loss is allowed.

## Crashed workers and expired leases

Normal workers reclaim expired leases automatically. To perform an *operator-directed* bounded cleanup after a worker crash, first inspect `queue:dispatch`, `queue:workers`, `queue:status` and `queue:verify`. If needed, stop or pause new dispatch and run:

```sh
php bin/cajeer queue:pause "incident recovery" --confirm
php bin/cajeer queue:recover 100 --confirm
php bin/cajeer queue:list pending
php bin/cajeer queue:list dead
php bin/cajeer queue:verify
php bin/cajeer queue:resume --confirm
```

`queue:recover` changes only genuinely expired `running` leases, bounded to 1–1000 per call; it never inserts logs, never steals live worker leases and never runs an unbounded backlog scan. It commits each recovered job's old attempt outcome and new state together. Retry schedules use the original lease expiry plus bounded jittered backoff. Exhausted jobs move to DLQ with retained payload. Existing workers' lease IDs no longer authorize completion, so they cannot commit after another worker has recovered the job. Stop after any unexpected integrity or schema failure, repair the underlying cause, and rerun the check.

Use `queue:history JOB_ID`, `queue:list dead` and the Admin Panel to inspect failures without revealing payload. After the cause is fixed, use `queue:retry JOB_ID` for explicitly audited replay, or `queue:discard JOB_ID` only when data loss is authorized. Never blindly resend with a new key or delete jobs directly.

## Acceptance evidence

Local unit tests: `php tests/ingest_recovery.php`, `php tests/queue.php`, `php tests/retry_policy.php`, `php tests/worker_runtime.php`. The real PostgreSQL 17/18 job `php tests/ingest_recovery_database.php` must be run with `CAJEER_TEST_DATABASE=1` and `APP_ENV=test` against a disposable database. The test uses a separate PHP process, hard-kills it with SIGKILL after it commits a lease, exercises recovery and fencing, resumes the delivery, verifies same-key replay, then exercises exhausted retries and audited DLQ recovery. This integration test is mandatory in the database release gate, and 0.9.5 → 0.9.6 remains a separate upgrade/rollback acceptance requirement. Neither local unit passing nor adding a CI job is a claim of a completed production certification.
