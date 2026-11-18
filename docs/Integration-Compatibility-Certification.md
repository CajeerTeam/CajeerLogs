# CajeerLogs 0.6.9 — Integration & Compatibility Certification

This release keeps the 0.6.8 HTTP API v1 and PostgreSQL schema, including migration
`0027_export_jobs.sql`. There are no new migrations. A candidate archive is **not**
certified merely because unit tests, `scripts/release.py build`, or the local fixture
load tests pass.

## Executable acceptance paths

1. **Disposable PostgreSQL/PHP matrix:** `scripts/certify.py database` installs the
   identical verified distribution and runs real database tests including webhook
   subscriptions, outbox/lease/retry, export jobs, queue fairness, dead letters,
   HMAC, RBAC and retention. `tests/pipeline_database.py` starts a real PHP HTTP
   gateway and uses the Python client to ingest signed batches; it then commits
   an async job and two full export jobs, terminates the gateway, restarts it,
   resumes the queue and exporter, reads JSON/CSV, verifies row counts and SHA-256,
   checks token access and deliberately corrupts an export file to require HTTP 410.
2. **Upgrade/rollback boundaries:** a separate disposable database performs the
   `0.6.8 -> 0.6.9` upgrade with unchanged existing migrations. Backups made on
   0.6.8 are compatible; a 0.6.9 backup cannot be restored into a 0.6.8 runtime.
   The upgrade suite also exercises supported earlier histories. Never test a
   destructive restore on a real production database.
3. **HTTPS production stack:** `tests/production_smoke.py` installs the Docker
   Compose stack, checks TLS/security/RBAC, runs the real signed Python SDK and
   the pipeline scenario, stops all queue consumers while enqueuing an async
   request, verifies the pending receipt and an identical idempotent replay,
   restarts two queue containers and verifies committed events. It also generates
   JSON/CSV jobs, invokes the actual export worker, downloads both artifacts,
   compares SHA-256 and rejects deliberate file corruption.
4. **Performance:** `scripts/load_test.py` makes real HTTP requests against
   isolated HTTPS/PHP/PostgreSQL with live queue workers and a local fault proxy.
   `sustained` (>=60 seconds), `stress` (>=120 seconds, 1x/2x/4x), `soak` (>=900
   seconds) and `fault` (>=60 seconds) must all pass. Fault injection includes
   pre-commit 429/503, lost response after upstream commit and delay. The test
   verifies every received event through search and confirms async jobs finish.
   In addition to error rate, p95 and read latency, the gate measures achieved
   completed-request throughput, complete accounting, minute and step windows,
   unambiguous idempotent replays, and prevents a synthetic or short-duration
   report from being marked certified.

The report is written atomically and is bound to the SHA-256 of the same immutable
release archive. Certification is gated by the four PHP/PostgreSQL combinations
(8.4/17, 8.4/18, 8.5/17, 8.5/18), Admin Panel browser evidence and the live
performance report. Test evidence is not a cryptographic signature; the release
requires trustworthy CI execution and artifact provenance.

## Run locally

```sh
python3 tests/load_test.py
python3 tests/certify.py
python3 tests/release.py
python3 scripts/release.py build release-out/CajeerLogs-0.6.9.zip
python3 scripts/release.py verify release-out/CajeerLogs-0.6.9.zip
```

The commands above validate the runner and package locally, **not** the real
PostgreSQL/Docker certification. For the real database matrix use isolated
credentials with `APP_ENV=test`, `CAJEER_TEST_DATABASE=1`, the three distinct
explicit disposable databases, matching `pg_dump`/`pg_restore` and actual
previous-version source trees; see `scripts/certify.py database --help`.
`tests/production_smoke.py` requires Docker Compose v2 and OpenSSL and must run
only on an isolated machine where creating and deleting disposable volumes is
permitted. Full release runs through the `public-release` CI job.

## Acceptance and failure handling

- Any failed, skipped or missing required check makes the release gate fail.
- If accepted events cannot be verified by ID, or an async job remains pending,
  the load gate fails instead of silently assuming success.
- If the queue is stopped, no job can falsely be reported as completed; workers
  must recover persisted records after restart.
- A JSON/CSV download is allowed only for the authenticated authorized requester
  and only if its on-disk length and SHA-256 match PostgreSQL metadata.
- A webhook retry is at-least-once and must keep its delivery ID/payload; consumers
  must deduplicate notifications. `copytruncate` does not offer a universal
  no-loss guarantee; prefer Nginx rename/reopen.
- Production deployment requires reviewing the generated reports, checking
  infrastructure sizing and explicitly accepting observed SLO results. A 0.6.9
  source tree is not proof that the target installation met those requirements.
