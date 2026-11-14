# 0.0.9 — Security & Operations

## Upgrade 0.0.8 → 0.0.9

Stop HTTP ingest and workers during the update, back up PostgreSQL and the deployment configuration with existing tools, overlay the changed files and run `php bin/cajeer migrate`, `migrate:status`, `db:doctor`, `doctor`. The new migration is `0008_security_operations.sql`; 0001–0007 are unchanged. PHP 8.4+ with sodium, pdo_pgsql and Argon2id, PostgreSQL 17+; PostgreSQL client tools of at least the server major version are required for backup/restore. Use Nginx/PHP-FPM, TLS, the `public/` document root and a restricted OS user. Check the effective TOTP key before enabling the policy.

## TOTP and recovery

`SECURITY_2FA_REQUIRED_FOR_ADMINS=true` enforces enrollment for administrators (previous releases ignored this setting; check existing `.env`). Unenrolled administrators receive a restricted session and can only use `/auth/me`, logout and enrollment routes. The UI opens Settings and hides other navigation. With policy false, TOTP is opt-in for every user. All enrolled users must present a second factor at login regardless of the policy. API service tokens remain scoped machine credentials, not human sessions.

Set `SECURITY_TOTP_KEY` to a random 32-byte hex value **before enrolling** (`php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`). If unset, the existing installer-generated 64-hex `SECURITY_HMAC_SECRET` is used with HKDF domain separation. Do not change the effective key while users are enrolled. Keep it backed up independently of the database and ensure it is consistent across PHP workers. Never expose it via HTTP or logs. If policy is enabled and the key is invalid, `/ready` returns 503. Use NTP; six-digit SHA-1 RFC 6238 codes use a 30-second time step, with ±1 step tolerance; accepted steps cannot be reused. After activation a fresh code or recovery code is required for a new login.

In `/admin/` → Settings → Two-factor authentication, confirm the current password, add the displayed Base32 key manually to an authenticator (SHA-1, 6 digits, 30 seconds), then confirm a fresh code within 10 minutes. Ten random 128-bit recovery codes are returned once; store them offline. The secret is encrypted with sodium secretbox, authenticated to the user UUID; recovery codes are SHA-256 digests. Setup is bound to the current session. Activation revokes prior sessions and creates a new session; disabling requires password and unused TOTP/recovery code and revokes all sessions. Mandatory administrators cannot disable 2FA from HTTP. Login accepts optional `otp` (six digits or a 32-character lowercase hex recovery code). All enrollment operations require cookie session + CSRF. Never send codes in URLs. All credentials are masked from application audit/logs; protect browser/device and backups.

Trusted server operator emergency recovery, including after key loss:

```sh
php bin/cajeer auth:2fa-reset admin@example.com --confirm
```

This clears TOTP/recovery codes, revokes all sessions and writes audit `auth.totp_reset_cli`. The user's password is unchanged. With mandatory policy the administrator must enroll again before privileged operations. Treat access to the deployment CLI/DB role as privileged, validate user identity out of band, review audit history and rotate credentials after compromise.

## Logical backups

Configure a private directory owned by the service/backup operator, 0700, outside `public/`:

```dotenv
BACKUP_DIR=/var/lib/cajeerlogs/backups
PG_DUMP_BIN=/usr/lib/postgresql/17/bin/pg_dump
PG_RESTORE_BIN=/usr/lib/postgresql/17/bin/pg_restore
```

```sh
install -d -m 0700 /var/lib/cajeerlogs/backups
php bin/cajeer backup:create
php bin/cajeer backup:list
php bin/cajeer backup:verify backup-YYYYMMDDTHHMMSSZ-HEX16
```

Use the actual ID returned by `backup:create`. The directory is atomically published only after `pg_dump` succeeds, files are synced, SHA-256 calculated and `pg_restore --list` succeeds. Files are 0600. A dump is a PostgreSQL custom-format consistent snapshot of the configured schema, including migration history and data (not cluster roles, external files, `.env`, or other schemas). Migration advisory locking prevents concurrent CajeerLogs migrations while dumping. Do not manually change DDL during a backup. `verify` checks size, SHA-256 and archive TOC; a restore rehearsal is necessary to verify full recoverability. The manifest is not a signature and cannot establish authenticity against an attacker who controls the backup directory. Use encrypted storage, offsite encrypted copies, separate protected configuration/secret backups, controlled access and a retention policy managed by the operator. No automatic backup deletion. Never restore untrusted archives; a PostgreSQL archive can execute SQL as the restore role. Password is supplied only in child process environment, not in arguments/output; protect the service OS account. `pg_dump`/`pg_restore` are executed without a shell. `backup:create` fails if the DB is not migrated, client tools are missing, dump fails or directory permissions are unsafe.

Enable the included systemd backup service/timer after adapting paths, user and PostgreSQL tools. It runs daily at 03:00 local time with up to 5 minutes jitter. Monitor service exit status and last successful run, disk capacity and offsite transfer; test restores regularly. The scheduler does not delete backups.

## Restore rehearsal and disaster recovery

Stop all clients/workers that might connect to the restore target. Provision a **new empty database** on the same configured server owned by the configured application role (DBA operation), e.g. `CREATE DATABASE cajeer_restore OWNER cajeer_logs;`. Do not point the live application at this DB yet. Restore must use the same CajeerLogs release and schema as the backup. Use matching PostgreSQL client binaries and retain the matching TOTP key. The target must not be the configured live database, must be empty, owned by the app role, and exclusively available; do not allow new connections while restoring.

```sh
php bin/cajeer backup:verify BACKUP_ID
php bin/cajeer backup:restore BACKUP_ID cajeer_restore
DB_DATABASE=cajeer_restore php bin/cajeer db:doctor
```

Restore uses `pg_restore --single-transaction --exit-on-error --clean --if-exists --no-owner --no-privileges` only in the separately verified empty target. `--clean` handles the pre-created public schema; it never operates on the live DB. Migration history is rechecked after restore. Sessions, API tokens and recovery codes are invalidated in the restored database so previously revoked credentials cannot be revived; issue new API tokens and reset/re-enroll TOTP where necessary. Password hashes, user blocks, roles, active TOTP secrets, audit, queues and webhook history reflect the snapshot: reconcile changes after the snapshot and rotate compromised credentials. Restore can replay previously delivered jobs/webhooks if workers are restarted before reconciliation. Inspect queue/dead-letter, compare counts and sample events, reconcile webhooks and duplicate IDs, verify backup key/config and log in before switching `DB_DATABASE`. Start workers only after verification. A failed restore is not safe to promote; discard/recreate the target under DBA control. Recovery is logical snapshot restore, not WAL/PITR; use PostgreSQL infrastructure backups for point-in-time recovery/HA.

## Monitoring

`/health` measures runtime only; `/ready` verifies runtime, migrated writable PostgreSQL and required TOTP key configuration. `/metrics` keeps existing queue gauges and adds:

- `cajeerlogs_operations_available` (0 when DB queries fail; no fake zero metrics)
- `cajeerlogs_log_storage_bytes` (table including indexes and TOAST)
- `cajeerlogs_queue_oldest_pending_seconds`, `cajeerlogs_expired_leases`
- `cajeerlogs_webhook_pending`, `cajeerlogs_webhook_failed`
- `cajeerlogs_scheduler_last_success_timestamp_seconds{task=...}`, `cajeerlogs_scheduler_overdue_seconds{task=...}` (tasks appear after first success; alert on absent task)

Configure `PROMETHEUS_TOKEN` with a 32–256-character URL-safe secret and send `Authorization: Bearer TOKEN` from the scraper. Production authenticated scrapes require HTTPS. Without a token, restrict `/metrics` at the network/proxy (default compatibility behavior). The metrics token is separate from API tokens. `system:report` and `/api/v1/system/report` include storage free bytes, security readiness, real queue/database/scheduler metrics; only authorized users/system tokens see the system report. No secrets or log contents in metrics. Alert on readiness failure, dead jobs, expired leases, scheduler lateness, webhook failures, backup failure and disk exhaustion. Metrics queries are database-backed; use a sensible scrape interval (e.g. 30–60s).

`update:check /path/to/trusted/release.json` compares the local trusted release manifest with the installed version. It neither downloads nor installs updates and does not authenticate a remote registry. Take a backup and stop writers before any manual upgrade.

## Verification

`php tests/security_operations.php` tests RFC vectors, encryption, runtime security and failure handling. `CAJEER_TEST_DATABASE=1 CAJEER_TEST_RESTORE_DB=... php tests/security_database.php` requires a disposable source database and a separately provisioned empty target; it exercises actual migrations, login, TOTP, recovery codes, monitoring and real dump/restore. Do not point tests at production. The GitHub Actions matrix provisions PostgreSQL 17 plus client tools. `tests/admin_browser.py` exercises the browser UI on a controlled API; `tests/admin_browser_database.py` exercises the existing live database E2E flow. Run restore rehearsals with realistic volumes and verify production TLS/proxy, backups and disaster recovery before go-live.
