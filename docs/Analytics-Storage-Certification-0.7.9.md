# CajeerLogs 0.7.9 — Analytics & Storage Certification

The certification suite runs real migration, analytics replication, rollup reconciliation, and archive lifecycle tests against **disposable** PostgreSQL 17+, ClickHouse 25.8 and a signed, fault-injecting local S3 HTTP server. It does not pretend to emulate a real commercial S3 service or to replace performance measurements on production-sized hardware.

## What runs

The verified **0.7.9 release ZIP** is extracted to a clean temporary directory. `scripts/analytics_storage_certify.py` refuses an invalid archive or a mismatched release version, checks the live database runtimes, and executes:

1. **Migration engine:** creates and upgrades isolated PostgreSQL schemas, replays migrations, repairs detected drift, tests recovery from interrupted concurrent index/backfill migrations and rejects missing histories.
2. **Rollup reconciliation:** validates per-project totals and dimensions before/after updates, deletion, rollback, partial windows and backfill; detects deliberate dimensional corruption.
3. **PG → ClickHouse replication:** verifies transactional outbox, rollback, expired lease fencing, duplicate acknowledgement, revision replacement, tombstones, scoped backfill, replay and zero-double-count query results.
4. **Signed S3 / warm / cold / combined recovery and load:** actual AWS SigV4 PUT/GET/DELETE, byte-for-byte readback, PostgreSQL partition migration, S3 503 on PUT/GET/DELETE, corrupted GET, and lost PUT acknowledgement. A failure must preserve warm rows/metadata, while a recovered transfer must not inflate analytics or expose other projects. The cross-tier test additionally simulates ClickHouse connection refusal, recovers the outbox, moves live events through PostgreSQL warm and S3 cold, restores them and compares PostgreSQL rollups and ClickHouse counts.

The same signed-S3 stage processes **300 events** in 12 real PostgreSQL batches, propagates them to ClickHouse, times 12 aggregation queries (measured p95), stages all rows into a monthly partition, offloads them to S3 with SHA-256 readback, restores them, and measures ingestion/replication/stage/offload/restore throughput. Default acceptance thresholds: 10 events/s for PostgreSQL ingestion and ClickHouse replication, <=5000 ms ClickHouse p95. These may be raised for the target environment using `CAJEER_CERT_MIN_INGEST_EPS`, `CAJEER_CERT_MIN_REPLICATION_EPS`, `CAJEER_CERT_MAX_ANALYTICS_P95_MS` (positive numbers). The CI evidence must contain the measured rates, latency and threshold gates; a `passed` label alone cannot certify performance.

The final release gate additionally requires the existing **four sustained/stress/soak/fault workload phases** on the disposable production Docker/TLS stack. These tests are independent of this 0.7.9 suite.

## CI invocation

Only on **isolated CI** with disposable databases and non-production credentials:

```bash
export APP_ENV=test CAJEER_TEST_DATABASE=1 CAJEER_TEST_CLICKHOUSE=1
export DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=cajeer_logs
export DB_USERNAME=cl_test DB_PASSWORD=replace-with-unique-test-only-secret
export CLICKHOUSE_DSN=http://127.0.0.1:8123 CLICKHOUSE_DATABASE=cajeer_logs
export CLICKHOUSE_USERNAME=cl_test CLICKHOUSE_PASSWORD=replace-with-unique-test-only-secret
export CLICKHOUSE_ALLOW_INSECURE=true
python3 scripts/release.py build release-out/CajeerLogs-0.7.9.zip
python3 scripts/analytics_storage_certify.py release-out/CajeerLogs-0.7.9.zip \
  --output release-out/analytics-storage-certification.json
```

The suite never calls a production mutation endpoint. Tests use private PostgreSQL schemas with randomized names; ClickHouse test project UUIDs are randomly generated. The S3 mock binds to `127.0.0.1` and uses a one-shot fault file in a private temporary directory. S3 objects left after an ambiguous response are not silently asserted absent: reconcile actual object inventories on the target S3 service.

### Evidence and release policy

The JSON report includes the archive SHA-256, PHP/PostgreSQL/ClickHouse runtime versions, stage names, observed PASS assertion counts, durations and exit codes. It is written on both success and failure. A failed, empty, misordered or mismatched report is rejected by `scripts/certify.py release --analytics-storage-report ...`. This report is **not cryptographically signed**, not an external audit and does not establish commercial S3 compatibility.

No PostgreSQL schema migration is introduced in 0.7.9; the latest one remains `0033_storage_tiers.sql`. Upgrade 0.7.8 → 0.7.9 preserves the existing database objects. Keep backups, verify them, then follow the existing maintenance/rollback procedures. Live test performance and failure data, along with actual target-region S3 retention and KMS tests, are required before declaring complete production acceptance.
