# CajeerLogs 0.8.6 — Upgrade & Rollback Certification

The 0.8.6 release gate runs real PHP applications against disposable PostgreSQL 17/18 databases and a verified, immutable release ZIP. It does **not** execute downgrade SQL against a live upgraded database.

## Tested transition for each supported baseline

1. Extract the real previous release source, install it in a fresh PostgreSQL schema, and create an administrator, project, source, API token, synchronous event and pending asynchronous job through actual HTTP endpoints.
2. Produce the previous release's **real PostgreSQL custom-format backup**, record its SHA-256, and preserve the previous runnable code and installation secrets for recovery.
3. Verify and overlay the exact candidate ZIP, run pending migrations, check schema/data integrity, queue accounting, audit history, authenticated API, retained sessions/tokens and idempotent ingestion. Execute the pending job.
4. In a **different, explicitly provisioned, owned and empty PostgreSQL database**, restore the pre-upgrade backup using `pg_restore --single-transaction --exit-on-error` and start the **old PHP code** with the original keys. Verify previous-version readiness, authentication, log read, idempotency, pending-job processing and persisted data. Confirm that the upgraded database remains unchanged.
5. Independently restore the same backup with the candidate release to the upgrade-restore database, migrate it to current schema, verify integrity and confirm tokens/sessions are invalidated by the recovery path.
6. Drop only the unique temporary schemas. A failed stage aborts release certification. No success report is emitted if one stage is missing or fails.

Rollback here means recovery of both **previous binaries and pre-upgrade data** from a point-in-time snapshot. It intentionally loses writes made after the snapshot, so it is **not** an online reverse migration. An on-call rollback must stop writers, capture/validate a recovery point, restore into a new isolated database, validate with the matching old release and only then deliberately redirect traffic. No automatic production cutover or destructive database overwrite is performed.

## Gate requirements

Use `scripts/certify.py database` with all `--from-*` source directories, including `--from-085`, and:

- `CAJEER_TEST_DATABASE=1`, `APP_ENV=test` and explicit `DB_*` credentials; distinct disposable `DB_DATABASE`, `CAJEER_TEST_RESTORE_DB`, `CAJEER_TEST_UPGRADE_RESTORE_DB`, `CAJEER_TEST_UPGRADE_ROLLBACK_DB` databases
- `PG_DUMP_BIN` and `PG_RESTORE_BIN` pointed to real matching PostgreSQL client executables, and `psql` on PATH
- The actual verified candidate archive and real historical baseline source trees, not synthetic fixtures or version-label-only repositories

The CI release gate covers the prescribed historical upgrades through 0.8.5 on PHP 8.4/8.5 × PostgreSQL 17/18 and validates the evidence for every required stage. Running a local source-unit test, or producing this document, is **not** database certification. The candidate stays uncertified until the complete live CI gates succeed.
