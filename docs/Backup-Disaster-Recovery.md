# Backup & Disaster Recovery — 0.4.8

## Upgrade and scope

Stop writers/workers, take and verify a backup on 0.4.7, retain all application secrets and configuration independently, overlay 0.4.8, run `php bin/cajeer upgrade:check BACKUP_ID`, `migrate:verify`, `db:verify`, `audit:verify` and `doctor`. No new SQL migration; 0001–0020 are unchanged. Rebuild containers. The release remains public-preview until the exact candidate passes CI.

The backup is a PostgreSQL logical custom-format dump of the configured schema, not a snapshot of the whole cluster. PostgreSQL roles, TLS files, application configuration, encryption keys and any external files must be backed up separately. Keep old TOTP keys needed by older snapshots. Preserve backup encryption keys separately from the encrypted copies. Loss of the key makes the corresponding offsite copies unrecoverable. Use trusted backups only: PostgreSQL restore executes database definitions, not an untrusted file import sandbox.

## Local backup and schedule

`BACKUP_DIR` defaults to `storage/backups/private`. Private canonical directory owned by the running operator (0700), outside public. `BACKUP_TIMEOUT_SECONDS` (3600) limits each PostgreSQL child process, `BACKUP_MIN_FREE_BYTES` (100 MiB) is a preflight reserve, not a forecast of the dump size. Use capacity monitoring and a dedicated filesystem. `pg_dump` and `pg_restore` must match the supported PostgreSQL versions. BackupManager fsyncs the archive, manifest and directory before/after atomic publication; a killed process can leave hidden partial directories, not a published successful backup. Do not delete an active partial directory. Inspect and remove abandoned partials only while backup processes are stopped.

```sh
php bin/cajeer backup:create
php bin/cajeer backup:list
php bin/cajeer backup:verify BACKUP_ID
php bin/cajeer backup:run
php bin/cajeer backup:history
```

`backup:run` is a due-check and executes a real dump when due (default `BACKUP_INTERVAL_SECONDS=86400`). Schedule state and per-operation history are private files in BACKUP_DIR; no DB migration. A scheduled dump's ID is recorded before replication. If replication fails, subsequent runs retry that same dump; they do not create a new dump every 15 minutes. If the configured offsite is missing/unsafe, the run fails. Only a successful dump and, when configured, authenticated offsite readback advance the schedule. Local-only runs report `last_offsite_verified=false`. History records start, finish, duration, result and safe failure status; an interrupted run can remain `running`. A nonblocking local file lock serializes CLI operations sharing the same backup directory. Use one designated backup service per installation; do not rely on flock across unrelated filesystems or distinct local roots. Avoid concurrent DBA restore/upgrade/prune operations.

Systemd: install the supplied `examples/systemd/cajeerlogs-backup.service` and timer, adapt the account/paths, run `systemctl daemon-reload && systemctl enable --now cajeerlogs-backup.timer`. The timer checks every 15 minutes; persistent timer catches missed runs. For remote mounts add `RequiresMountsFor=/path/to/offsite` to the service, and keep that mount available. For Docker, `docker compose --env-file .env.production -f compose.production.yaml --profile backup up -d backup` runs the due-check every 15 minutes in a separate worker. The base profile defaults to local-only. For offsite use a Compose override for backup service (do not mount the key in the HTTP gateway):

```yaml
services:
  backup:
    environment:
      BACKUP_OFFSITE_DIR: /offsite
      BACKUP_ENCRYPTION_KEY_FILE: /backup-key
    volumes:
      - /mnt/independent-backup/cajeerlogs:/offsite
      - /etc/cajeerlogs/backup-key:/backup-key:ro
```

Mounts must be readable/writable as appropriate by the container UID (www-data, typically 33). Set ownership and permissions before starting. Set the same BACKUP_OFFSITE_DIR in the runtime configuration if you want the UI to show that configured flag; never expose the key merely for diagnostics. Check `backup:history` for actual verified state. The system report reads schedule state without starting a backup. Do not use a writable network share as the only copy; enforce server-side snapshots/immutability and separate credentials.

## Encrypted offsite copies

Provision a private independent POSIX filesystem (e.g. NFS with correct ownership and permissions, or a separately mounted backup volume). It must support atomic hard-link publication and fsync. By default local and offsite `st_dev` must differ; this catches the common case of a missing mount but does not prove geographic separation. `BACKUP_OFFSITE_REQUIRE_DISTINCT_DEVICE=false` is for controlled same-device tests only. No native S3 API is implemented in this release; use independent POSIX storage, not an object-store mount that cannot provide these guarantees.

Generate the independent backup key (32 random bytes represented by 64 hex characters):

```sh
umask 077
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;' > /secure/backup-key
```

Set `BACKUP_OFFSITE_DIR=/mnt/independent-backup/cajeerlogs` and `BACKUP_ENCRYPTION_KEY_FILE=/secure/backup-key` in the backup operator environment or private dotenv. Inline `BACKUP_ENCRYPTION_KEY` also supported, but use a file. Do not configure both. Store the key in offline escrow, with a record of which backups it protects. Key rotation is operator-managed; keep the old key for old packages and supply it only in the restore environment when needed.

```sh
php bin/cajeer backup:replicate BACKUP_ID
php bin/cajeer backup:offsite-list
php bin/cajeer backup:offsite-verify BACKUP_ID
php bin/cajeer backup:fetch BACKUP_ID
```

`.cjr` packages use libsodium secretstream XChaCha20-Poly1305 with a random stream header, authenticated encrypted manifest, 1 MiB bounded encrypted data frames, and an explicit authenticated final frame. The plaintext dump SHA-256 and size are checked while encrypting and decrypting. Verification reads the entire copy, rejects wrong keys, identity mismatch, corrupted/oversized/truncated frames and trailing bytes; extraction uses a new private file only. Package publication uses a temporary private file, full verification and no-overwrite link; the final path is synced after unlinking the temporary file. If interrupted between link and unlink, the two links cause verification to fail closed; after stopping workers, inspect matching inode and remove only the temporary link before retrying. Import verifies authenticity, compatible release, checksum and `pg_restore --list` before publishing a new local backup. Existing backups are never overwritten. A cryptographically valid package is not proof of a successful database restore. The local plaintext backup remains private and is not encrypted at rest by this feature; use disk encryption where required.

## Retention

`BACKUP_KEEP_LOCAL=0` disables pruning by default. Set it to e.g. 7 to protect the newest seven local backups; optional `BACKUP_KEEP_DAILY=7`, `BACKUP_KEEP_WEEKLY=4`, `BACKUP_KEEP_MONTHLY=12` additionally protect the newest archive from each UTC period. The pending backup, last successful scheduled backup and last successful rehearsal backup are also protected. A candidate is eligible only if its local dump verifies and its matching encrypted offsite package fully authenticates. Corrupt or missing copies are skipped. Offsite packages are never automatically deleted. Use an independent remote retention policy with protected snapshots and verify recovery before expiring remote copies.

```sh
php bin/cajeer backup:prune
php bin/cajeer backup:prune --confirm
```

The first command is a dry run. The second rechecks the candidate and offsite copy, quarantines the local directory by rename and deletes its two expected files; no wildcard recursive deletion. Unexpected files cause refusal. Interrupted pruning may leave a hidden `.pruning` directory; inspect and recover manually while services are stopped. Schedule confirmed pruning separately after reviewing the policy. The journal is not pruned by this command.

## Disaster recovery / rehearsal

1. Stop application writers and workers before production cutover. Provision a separate empty PostgreSQL database, owned by the configured backup/restore application role, with matching schema name, no other connections or user objects. Use a trusted DBA. A restore cannot target the configured live database or system databases.
2. On a recovery host install compatible CajeerLogs, configure original database/schema identity and all required historic crypto keys. Set BACKUP_DIR and offsite credentials; enumerate and authenticate the copy with `backup:offsite-list` and `backup:offsite-verify`, then `backup:fetch`. If the original local archive survives, `backup:verify` suffices for its checksum/TOC; encrypted verification adds independent authentication.
3. Run `php bin/cajeer backup:rehearse BACKUP_ID EMPTY_TARGET_DATABASE` (or `backup:restore`). It executes the existing actual pg_restore, exact declared migration history, migrations, logical integrity and audit chain verification, and TOTP-key checks. Restore Fence quarantines the target until completion; on failure do not switch to it. Recreate a new empty target before retrying. Rehearsal does not automatically drop or promote the target; retain or dispose of the test DB using DBA tooling. The command records successful rehearsal time and measured elapsed duration.
4. Check `backup:history`, inspect the target with a separate configuration and run `db:verify`, `audit:verify`, `doctor`. Test authenticated access, selected event counts and application behavior. Restored sessions, recovery codes and API tokens are revoked/invalidated; issue new credentials before switching production. Existing TOTP enrollments remain if keys are available. Review pending queue jobs, idempotency and webhooks before starting workers to avoid unwanted external delivery. Configure APP_URL/TLS, DNS, and target DB explicitly. Keep the old database isolated until cutover is validated.
5. Repeat rehearsal at least weekly using a new disposable target. Measure end-to-end RTO (including provisioning, credentials and cutover) and actual RPO (last recoverable snapshot); `backup:rehearse` measures restore execution only. Daily backup is a target RPO, not a guarantee. Logical dumps do not provide WAL/PITR. Failures and pending replication must alert the operator. Use separate PostgreSQL WAL archiving if sub-daily RPO is required.

## Verification

`php tests/backup_operations.php` exercises actual cryptography and filesystem operations, including failure injection, using a pg_restore --list executable fixture for local orchestration. The required PostgreSQL upgrade test from actual 0.4.7 adds encrypted export, import, scheduling and fenced restore rehearsal on a real dump. Run complete release certification on PHP/PostgreSQL matrix and the exact candidate before deployment. Offline filesystem tests do not certify a remote mount, Docker deployment or RTO.
