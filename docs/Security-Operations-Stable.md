# CajeerLogs 0.5.0 — Security & Operations Stable candidate

This release integrates the working 0.4.x security and operations stack. The source
channel remains `public-preview` until the exact release ZIP passes the full CI
matrix and production gate. A source version or local unit test is not certification.

## Upgrade from 0.4.10

1. Stop writers and workers; create and verify a PostgreSQL backup using 0.4.10.
   Keep configuration, TOTP/HMAC/backup keys and TLS material separately in private storage.
2. Overlay the changed-files archive in the application root. Retain the existing
   `.env` and storage. Historical migrations 0001–0021 are unchanged; no new schema migration is required.
3. As the runtime user, run `php bin/cajeer upgrade:check BACKUP_ID`,
   `php bin/cajeer migrate:verify`, `php bin/cajeer db:verify`,
   `php bin/cajeer audit:verify`, and `php bin/cajeer doctor`.
4. Ensure production `.env` is owned by the runtime UID, regular, unlinked and mode
   0600 or 0400. Supply a strong `PROMETHEUS_TOKEN` (32–256 URL-safe characters)
   or explicitly disable `PROMETHEUS_ENABLED`. Otherwise metrics return 503 and readiness fails.
5. Production Compose defaults to a 30-minute idle timeout and five-minute recent
   authentication window. Review these policies before restart; preexisting sessions
   may need reauthentication or login. Bare PHP deployments retain their configured
   values; set `SECURITY_SESSION_IDLE_SECONDS=1800` and `SECURITY_REAUTH_SECONDS=300`.
   `SECURITY_2FA_REQUIRED_FOR_ADMINS=true` is required for the recommended production profile.
6. Rebuild/restart all services, including queue, scheduler, webhook and optional backup.
   Validate public TLS with `php bin/cajeer tls:check [CA_FILE]` from a host that can
   reach the public origin. Validate health and monitoring after the first worker passes.

## Operator acceptance check

```sh
php bin/cajeer operations:check BACKUP_ID
```

The command is read-only (no migrations, backup creation, restore, automatic repair
or retention). It checks actual runtime and database readiness, production HTTPS
and metrics configuration, recommended production identity policy, audit chain,
worker heartbeat and local storage headroom. When scheduled retention is enabled
and its UTC window is open, the latest run must have succeeded within 300 seconds.
If `MONITOR_BACKUP_ENABLED=true`, the backup heartbeat, scheduled backup age,
replication state and rehearsal age (7 days) must be healthy. An explicit backup ID
causes a real archive checksum/TOC check; if offsite storage is configured the
matching encrypted copy is also fully verified. Without a backup ID the report
explicitly skips archive verification; backup freshness is not proof of current
archive integrity. Output is JSON with `checks`, `skipped`, and status; exit 1 on
any failed required check. No credentials or raw SQL errors are returned. Audit
verification may take time on large chains; use a suitable DB statement timeout
and maintenance window. Public TLS handshake is intentionally a separate command.

The new security catalog guard checks actual security-invoker, language, volatility,
strictness, parallel safety and function-local search_path for migration 0019 audit
functions and 0020's token bucket. This supplements the existing persisted schema
fingerprint without modifying it. Changing these attributes causes schema drift
and prevents guarded runtime operations. Readiness also checks SELECT/INSERT/UPDATE/
DELETE for RBAC and saved views and EXECUTE for the five required functions. In
split-role deployments restore missing grants through the trusted migration owner;
do not run the runtime as a PostgreSQL administrator. Production Compose's legacy
DB-owner deployment remains supported; use the documented separate-role setup for
least privilege (see Secure-Installer.md). DB owners can still modify PostgreSQL
objects; keep audit checkpoints independently and restrict privileged access.

## Full gate and incident recovery

- Database: PHP 8.4/8.5 × PostgreSQL 17/18, including audit security-attribute drift,
  RBAC, TOTP/recovery, rate concurrency, retention, historical upgrade and restore.
  Actual 0.4.10 upgrade covers existing credentials, async job, encrypted offsite
  export/import, restore rehearsal and retention. All 21 migrations stay immutable.
- Split role: revoke RBAC/saved-view SELECT or audit function EXECUTE as owner;
  require readiness failure and recovery after grants are restored.
- Admin: exact release archive frontend build and real Chromium/Firefox/WebKit DB E2E.
- Production smoke: real HTTPS install and reinstall, TOTP, step-up rejection and
  recovery, live RBAC narrowing, token delegation refusal, audit verification,
  ingest/queue/search, backup and restore, bounded export rate, retention, and
  `operations:check`. Prometheus/Blackbox validate queue stop and database outage,
  then restart services, observe readiness and alert resolution and ingest again.

Run `scripts/certify.py` using the documented disposable databases and historical
source trees, including `--from-0410`. Final certification binds the four database
reports, admin evidence and actual Docker production smoke to the same ZIP SHA-256.
Never publish a certified artifact if any mandatory check fails or is absent.

## Incident runbook

- Suspected credential leak: revoke the affected API token/session, rotate relevant
  signing credentials, inspect audit, and verify the independently saved checkpoint.
  Do not replace shared TOTP/HMAC keys blindly (see Identity-2FA-Security.md).
- DB outage: stop ingest if needed; check `/health`, `/ready` and PostgreSQL status;
  fix the underlying DB and verify readiness, worker heartbeat, queue processing,
  audit integrity and alerts before declaring recovery. Avoid destructive repair.
- Restore: verify the independent encrypted archive, restore into a new empty
  target with the correct keys; retain Restore Fence, verify data and credentials,
  then explicitly perform cutover. See Backup-Disaster-Recovery.md.
- Disk pressure: pause writers if necessary, inspect `db:performance`, retention
  preview and storage on both volumes. Do not shorten retention or delete audit
  history automatically. Never run blocking VACUUM FULL on a busy system casually.

## Boundaries

The supported backup is logical PostgreSQL dump with independent POSIX offsite
storage, not WAL/PITR or native S3. Audit chain detects corruption but requires an
external trusted checkpoint for privileged rewrite/truncation detection. Workers
are monitored by role, not every replica. Production load targets are not an SLA;
measure your workload and restore time independently. The complete CI gate must
actually pass before treating the 0.5.0 candidate as stable.
