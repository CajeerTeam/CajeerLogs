# CajeerLogs 0.8.5 — Migration & Schema Certification

## Supported database history

The operational PostgreSQL migration history remains **0001–0033** and the ClickHouse migration history remains **0001–0003**. Existing SQL checksums have not changed. No new application schema migration is required on upgrade from 0.8.4.

The PostgreSQL runner now fails closed if a *pending* migration appears in `schema_migration_runs` but its SQL file is absent, even if that pending operation was transactional. It also rejects symlinked SQL migration files. This is intentional: a partial or failed migration cannot silently disappear from a later release. Restore the exact original file (including its checksum) rather than deleting a journal row or accepting a new baseline.

The ClickHouse migrator performs a full manifest check before issuing new DDL. It rejects unknown applied versions, duplicate history entries, checksum differences and gaps in the applied version prefix. ClickHouse has no transactional DDL rollback across the migration script; if a migration was interrupted, inspect the physical schema and resume the same intact script. **Never erase `analytics_schema_migrations` to bypass a mismatch.** Only one operator may run ClickHouse migrations at a time.

## Execute the real database certification

Use a **disposable PostgreSQL 17/18 database** with a dedicated application role that can create test schemas. The test refuses to run unless `APP_ENV=test` and `CAJEER_TEST_DATABASE=1`. It creates a randomly named schema and copies the shipped migration scripts to a private temporary directory. It does not modify the normal application schema.

```sh
php tests/migration_certification.php
APP_ENV=test CAJEER_TEST_DATABASE=1 php tests/migration_scale_database.php
```

The second command actually applies all 33 PostgreSQL migrations, replays them idempotently, creates and populates a 100,000-row table, builds an online index, executes a bounded multi-transaction backfill, stops deliberately after two committed batches and resumes using a new connection. It checks the exact row count and aggregate row-ID checksum. It then injects schema drift, a modified SQL checksum, an advisory-lock conflict, a missing unfinished migration script and a multi-statement SQL failure. Each fault must be detected without losing previously committed data.

A JSON evidence file can be emitted by setting `CAJEER_MIGRATION_CERT_REPORT=/private/path/report.json` to an existing writable directory. The release gate additionally supplies `CAJEER_MIGRATION_CERT_ARCHIVE_SHA256` and refuses results for another release ZIP. These reports are operational test results, **not signed attestations**.

The main CI release gate executes the above PostgreSQL test on the exact verified ZIP in **all four** combinations of PHP 8.4/8.5 and PostgreSQL 17/18. It requires passing status, 100,000 processed rows, checksum `5000050000`, the expected PostgreSQL series and the exact artifact SHA-256. The real ClickHouse service test remains mandatory in its own CI job.

## Deploy / maintenance safeguards

1. Take and test a backup before any migration. Pause writes for schema-changing migrations according to the maintenance plan.
2. Run `php bin/cajeer migrate:plan` and review pending operations, their SHA-256 values, mode and estimated operational impact. `migrate:plan` is **not** a performance estimator.
3. Verify PostgreSQL has adequate disk headroom and that `DB_LOCK_TIMEOUT_MS`, `DB_MIGRATION_STATEMENT_TIMEOUT_MS` and `DB_MIGRATION_MAX_BACKFILL_BATCHES` reflect your maintenance window. Never set unbounded SQL backfill loops.
4. Execute `php bin/cajeer migrate`, followed by `php bin/cajeer migrate:verify` and `php bin/cajeer doctor`.
5. If ClickHouse is enabled, inspect and run `php bin/cajeer analytics:clickhouse migrate --confirm` with a single operator and verify its normal analytics queries.
6. On failure, retain the recorded journal and exact migration SQL. Restore the database backup for a failed migration that cannot be safely resumed; never run hand-crafted metadata edits in production.

**Limitations.** Passing syntax/unit tests or the local in-process HTTP fixture is not equivalent to real PostgreSQL/ClickHouse certification. The release remains `public-preview` until the actual DB matrix, load, migration/restore and supply-chain gates complete. The 100,000-row test is a bounded regression workload, not a guarantee of safe runtimes for your production database size; measure the specific schema, indexes and lock times on a staging copy of production data.
