# Safe Update Installation — CajeerLogs 1.0.3

## Supported deployment

This is a **manual, operator-only native Linux/systemd updater**, not a Docker/container or HA updater. Start from an installed 1.0.3 or later release with a local private `.env`, a regular release directory (not a symlink or mount) and an application-owned canonical parent directory. Example layout: `/srv/cajeerlogs/current`, owned by `cajeerlogs`; the parent must not be group/world-writable. Use the runtime account, not root. `storage/` and `.env` must be owned by the same account; storage may not contain symlinks, hardlinks, devices or separate mounts. Use default local backup storage. Preserve external secrets/configuration independently; all custom runtime data must reside in `storage/` or explicitly managed external services. Arbitrary files outside `storage/` and `.env` are not copied; they remain in the previous release directory. Use only a dedicated PostgreSQL database owned by the runtime role; split-role, external environment-file, clustered, Docker and direct multi-host writer deployments are **not supported** by this updater. Use the existing manual upgrade/restore workflow in those cases.

Requirements: Linux filesystem supporting `renameat2(RENAME_EXCHANGE)`, Python 3.11+, PHP 8.4+ with PDO PostgreSQL/ZIP, Composer, compatible `pg_dump`/`pg_restore`, `gh` with attestation support and network/TLS access to GitHub and package repositories. The candidate is a complete signed GitHub release ZIP, not a source archive or delta ZIP. The caller must have `systemctl` permission for the named services (or use `--sudo-systemctl` with narrowly delegated noninteractive permission for exactly those commands/units). Use an operator session with a scoped, time-limited authorization (e.g. polkit or a tty-bound sudo ticket); never grant the web runtime passwordless unrestricted sudo or access to arbitrary units. All unit definitions must be administrator-owned; do not run application-controlled executable or configuration files as root. Keep operator execution and privileges outside HTTP. Store `GH_TOKEN` only in the operator environment if required.

All application services, ingress/PHP-FPM, workers, schedulers, export workers and their activating timers/sockets/path units must be listed explicitly with `--unit`, including inactive activators. Install these units in `/usr/lib/systemd/system` (or `/lib/systemd/system`) so persistent masks under `/etc/systemd/system` can be created. They must start the canonical live directory and read its local `.env` (no DB or version environment overrides). Do not list PostgreSQL, SSH or unrelated shared services. Stop all external writers, cron tasks and other application instances before installation; no external client may connect to the source or target database during maintenance. The source DB quiescence check rejects other live client connections. Only this deployment may use the target. The operator is responsible for the completeness of the service inventory and exclusive DB access.

Prepare an **empty, separately named** PostgreSQL database owned by the application runtime role, on the same server; the updater never creates or drops databases. Ensure space for a second DB, snapshot, staged distribution and copied storage. Protect and retain the source database and old release until the new version is accepted. For example, from a privileged PostgreSQL administration session (substitute your actual role and fresh name):

```sql
CREATE DATABASE cajeer_logs_next OWNER cajeer_logs;
```

## Install a published release

Use the current release's verified download feature first. A later version must be present in GitHub Releases, have the expected ZIP and SHA-256 sidecar, and have a valid GitHub-hosted signer attestation. Example with an illustrative future version `1.0.4` (only works once it is published and verified):

```sh
cd /srv/cajeerlogs/current
php bin/cajeer update:check --refresh
php bin/cajeer update:download --refresh
php bin/cajeer update:verify 1.0.4
php bin/cajeer update:install 1.0.4 --target-db cajeer_logs_next \
  --unit cajeerlogs-web.service --unit cajeerlogs-queue.service \
  --unit cajeerlogs-scheduler.service --unit cajeerlogs-scheduler.timer \
  --unit cajeerlogs-webhook.service --unit cajeerlogs-webhook.timer \
  --unit cajeerlogs-export-worker.service --unit cajeerlogs-export-worker.timer \
  --unit cajeerlogs-export-cleanup.service --unit cajeerlogs-export-cleanup.timer \
  --unit cajeerlogs-backup.service --unit cajeerlogs-backup.timer \
  --unit cajeerlogs-analytics-replication.service --unit cajeerlogs-analytics-replication.timer \
  --sudo-systemctl --confirm
```

Replace the illustrative units with the complete list for the actual host. `--sudo-systemctl` is optional when the operator already has systemd permissions. The updater revalidates the archive online, copies it into private staging and rechecks SHA-256, verifies all manifest entries, installs the signed Composer lock with plugins/scripts disabled, then stops and persistently masks the listed units. It creates and verifies a custom-format backup, restores it into the empty isolated DB (normal user/session/token identities retained **only** for a maintenance clone), copies private runtime files, changes only `DB_DATABASE` in staged `.env`, applies the candidate's existing `upgrade BACKUP_ID --confirm-stopped` and checks schema, readiness and source exclusivity. It synchronizes files, atomically exchanges directories, verifies local readiness again, persists a commit and reactivates the previously active units. HTTPS `/ready` must report the exact new version, and previously active services/timers must be active (successful oneshot completion is also accepted). Normal disaster recovery still revokes restored credentials.

## Failure and recovery

The sibling private directory `.current.safe-update/` contains the journal and per-transaction retained release, signed candidate copy, and old `.env`/storage. It must be protected from access by other users. It is **not** inside the web document root. Do not delete it, swap directories manually, or enable masked services while an update is pending.

```sh
cd /srv/cajeerlogs/current
php bin/cajeer update:status
php bin/cajeer update:recover --sudo-systemctl --confirm
```

Before durable commit, a failure or interrupted update can atomically return the original directory and original, unmodified DB to service. Incomplete cloned targets stay isolated; never reuse one without deliberate DBA cleanup and fresh provisioning. After the commit, services may already have accepted writes on the new DB. The updater **never** switches to the old DB in this case: it masks the declared services on failure and recovery resumes the new version after the operator repairs the cause. This avoids data loss from replaying a stale backup. Verify the current application, queue, scheduler, webhook, exports and audit before retiring the old DB. A retained old DB is a recovery snapshot, not a live standby; never expose both copies concurrently. Do not run the new updater against an already active old DB. Full PostgreSQL/systemd end-to-end acceptance and external signed-release verification are required before production rollout.
