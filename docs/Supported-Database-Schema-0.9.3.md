# CajeerLogs 0.9.3 — Supported PostgreSQL Schema

This release preserves **exactly** `migrations/pgsql/0001` through `0033` without changing their SHA-256 bytes. PostgreSQL 17 and 18 are the supported CI targets. Never downgrade the live application database in place.

## Independent schema certification

The ordinary `migrate:verify` compares the current catalog to a fingerprint stored in PostgreSQL. `migrate:certify` additionally reconstructs every installed migration from the **shipped, checksum-verified SQL** inside a randomly named scratch schema, compares full catalog structures and leaves no scratch objects on success or failure. It runs all SQL statements in one transaction and **rolls the entire transaction back, including scratch DDL**. It reads no application rows, does not rewrite existing tables, and never accepts unknown migration history. The migration role must have `CREATE` privilege on the database. A successful JSON report contains the number of migration files, a history SHA-256, and independent matching catalog hashes. This is *local evidence*, not a signature or substitute for independent CI.

Use the release binary and a dedicated, controlled migration operator during a maintenance window:

```sh
php bin/cajeer migrate:plan
php bin/cajeer migrate:verify
php bin/cajeer migrate:certify
```

`migrate:certify` exits nonzero on any drift, inaccessible schema, missing migration file, concurrent maintenance lock, or a nontransactional migration that cannot safely be replayed. Do not grant application HTTP/worker roles `CREATE` on the database merely for this command; use the separately provisioned migration CLI credentials (`DB_MIGRATION_USERNAME` / `DB_MIGRATION_PASSWORD_FILE`). No new migration table or SQL file was introduced for this release.

## Test a new installation or upgrade

On a disposable PostgreSQL 17/18 instance, set `APP_ENV=test` and `CAJEER_TEST_DATABASE=1`, with explicit test credentials. Run:

```sh
php tests/migration_engine.php
php tests/migration_certification.php
APP_ENV=test CAJEER_TEST_DATABASE=1 php tests/migration_engine_database.php
APP_ENV=test CAJEER_TEST_DATABASE=1 php tests/migration_scale_database.php
```

The integration test creates a separate randomly named schema; applies all 33 migrations; verifies the journal; replays all of them independently; intentionally modifies both a live table **and** its stored catalog fingerprint; checks the forged fingerprint is rejected; restores the original structure; and confirms replay again. It also tests historical schema adoption, advisory-lock conflicts, failed migrations, bounded backfill resumption and concurrent index recovery. All temporary schemas are dropped in cleanup.

The release CI additionally performs upgrade of a true 0.9.2 runtime, verifies its logs/queue/API and a durable preupgrade backup, restores the backup into a separate empty database, and boots the original 0.9.2 binary against the restored database. The upgraded live database must remain unchanged by this rollback rehearsal.

**Safety:** First validate the backup with `backup:verify`, and use `upgrade:check BACKUP_ID` before any upgrade. On failure restore a checked backup to a new empty database; do not erase `schema_migrations`, change its checksums, or use unsupported reverse migrations.

**Certification status:** Successful tests from a real PostgreSQL 17/18 service are required. An unavailable database cannot be treated as a passed test.
