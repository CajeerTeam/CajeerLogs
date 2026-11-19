# 0.9.4 — Production installer and in-place upgrade

This document describes executable operations, not an install contract. Run as the designated application operator on a private host, **never** on a production database for tests. PHP 8.4/8.5 with `pdo_pgsql`, `sodium`, Argon2id, PostgreSQL 17/18 are required. The web installer is disabled. The database/roles must be provisioned by the DBA before installation. Configure an HTTPS `APP_URL` in production and an operator-owned private `storage/` and config directory; keep `.env` or the `CAJEER_ENV_FILE` target mode 0600.

## First installation

```sh
php bin/cajeer version
php bin/cajeer install:check
# Either use the secure interactive prompt, or export CAJEER_ADMIN_EMAIL and
# CAJEER_ADMIN_PASSWORD_FILE (absolute path to a private 0600/0400 file).
php bin/cajeer install --non-interactive
php bin/cajeer install:status
php bin/cajeer migrate:verify
php bin/cajeer doctor
```

The `install` command uses both filesystem locks and a PostgreSQL session advisory lock. It writes `.env` atomically without logging or persisting the bootstrap password, applies committed migrations using the migration role, creates **only the first** administrator with Argon2id, and checks readiness using freshly loaded configuration. Passwords are accepted through a concealed interactive terminal or `CAJEER_ADMIN_PASSWORD_FILE`. Subsequent calls require the same administrator credentials and never reset the password, create a second administrator or rotate retained keys. The install process cannot change the configured DB target or existing sensitive keys. To recover lost credentials, use `admin:reset-password EMAIL --confirm`, not a second initial administrator.

If the process is interrupted, run `install:status` and `migrate:plan`, correct the specific error and rerun **the same** install with the same DB target, keys and administrator. Migration engine journal records committed steps and rejects drift. Do not delete the lock files, migration journal or installation marker; never disable drift checks. With separate runtime and migration roles, follow [the secure role setup](Installer-Administrator.md) and `install:prepare-schema --confirm` before first install.

## Upgrade from 0.9.3 to 0.9.4

There are **no new PostgreSQL migrations** in 0.9.4. Existing instances still require a verified pre-upgrade backup and a successful release/compatibility check. For historical supported upgrades that do have pending migrations, the new CLI **executes** them and verifies the resulting schema.

1. Schedule maintenance. Disable all external ingestion, HTTP writers, workers, scheduler, webhook and export background jobs. **`--confirm-stopped` is the operator's acknowledgement, not an automatic process-stop mechanism.** Ensure no old processes remain with old code loaded.
2. From the old version, create a fresh backup with `php bin/cajeer backup:create` and preserve the returned `backup-...` ID. Keep the original release ZIP, original `.env` and its secret files, and verify a restorable offsite copy. A local checksum alone does not prove disaster recovery.
3. Deploy the **verified** release ZIP matching 0.9.4. Preserve `.env`, the `storage/` volume and PostgreSQL data. On split-role deployments supply migration credentials only to the CLI; do not persist them in application config.
4. Execute the actual guarded upgrade:

```sh
php bin/cajeer version
php bin/cajeer backup:verify BACKUP_ID
php bin/cajeer upgrade:check BACKUP_ID
php bin/cajeer upgrade BACKUP_ID --confirm-stopped
php bin/cajeer install:status
php bin/cajeer migrate:verify
php bin/cajeer doctor
php bin/cajeer audit:verify
```

5. Check queue/worker health, existing administrator access, log search and signed ingestion. Restart PHP-FPM and background jobs against the new code only after checks pass; restore ingestion and monitor error/queue rates.

The `upgrade` operation refuses unknown backups, schema mismatches, incompatible historical versions, drift, read-only/recovery PostgreSQL, incomplete admin bootstrap and concurrent local or cross-host installations. It rechecks verified backup/history **after** acquiring locks. It performs migrations with a dedicated migration connection **only when SQL is pending** (no DDL credentials needed for a verified schema-identical 0.9.3 → 0.9.4 upgrade), validates a current full migration history, verifies installation readiness, and atomically records `installation.upgrade.0.9.4` in `settings` with one audit event. It will not create or overwrite an administrator or modify `.env`.

If the process fails during a migration: keep services stopped and the original backup intact; inspect `migrate:plan` and `install:status`; correct the underlying issue and rerun `upgrade` with **the same** backup ID. A committed **prefix** of the expected pending migrations can be resumed; out-of-order or modified history will be rejected. A fully completed upgrade may be retried without reapplying SQL or duplicating its completion record. `upgrade:check` is a strict dry-run check of the initial history, so it can fail following a partially applied upgrade; `upgrade` itself rechecks the validated suffix for recovery.

**Rollback is not an in-place schema downgrade.** If a changed schema or runtime proves unhealthy, stop traffic, provision a *new empty* PostgreSQL database, restore the paired old backup, redeploy the unchanged prior release and original secrets, validate before cutover. `backup:restore BACKUP_ID EMPTY_DB` only works with a fresh exclusive target. If a migration failed or data diverged, do not copy upgraded DB contents back to the older code.

## Verification, CI and limits

`php tests/production_installer.php` covers session lock files, symlink rejection, CLI argument validation and resumable migration history. `CAJEER_TEST_DATABASE=1 python3 tests/upgrade_release.py /actual/0.9.3/tree` runs a real old release, PostgreSQL backup, the new `upgrade` twice, a data/API check and independent restore with old code. Database jobs require PostgreSQL and both `pg_dump` and `pg_restore` in a disposable CI environment. Local filesystem tests alone are not database/production certification.

The verified archive and actual PHP/PostgreSQL 17/18 CI matrix are required for release qualification. The strict supply-chain gate remains blocking until approved dependency locks and base image digests are verified. Explicit operator acknowledgement never substitutes for verifying that production writers have actually stopped.
