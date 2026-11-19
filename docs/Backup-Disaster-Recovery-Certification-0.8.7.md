# CajeerLogs 0.8.7 — Backup & Disaster Recovery Certification

## Executable recovery procedure

The 0.8.7 recovery command **does not use the local backup archive**. It decrypts the configured, authenticated offsite `.cjr` package into a new private staging directory, checks its full SHA-256 and actual PostgreSQL restore TOC, then executes the same `pg_restore --single-transaction --exit-on-error`, schema-migration, integrity, audit, queue-accounting, secret-key and token/session-revocation checks used by `backup:restore`. The destination **must** be a separately provisioned, owned, *empty* PostgreSQL database with no other connections. Restore Fence quarantines the destination until validation and credential revocation commit. The source/live database cannot be overwritten. The plaintext scratch directory is removed on success **or failure**.

On a recovery host, install the compatible release and independently restore all required `.env`, TOTP and encryption keys. Mount the original offsite backup filesystem and configure `BACKUP_OFFSITE_DIR`, `BACKUP_ENCRYPTION_KEY_FILE`, PostgreSQL connection and client binaries. Before the drill, provision an empty named recovery database as its owner (not the live one). Run:

```sh
php bin/cajeer backup:dr-certify BACKUP_ID EMPTY_TARGET_DATABASE 3600 600
php bin/cajeer backup:history
```

The last two arguments are the maximum RPO and maximum recovery readiness period, in whole seconds. Both are mandatory. `RPO` is bounded conservatively by **disaster start time minus `snapshot_started_at`**, recorded immediately *before* `pg_dump` acquires its snapshot. `created_at` is not used to estimate RPO because it occurs after the dump and would understate potential data loss. Backup archives made before 0.8.7 remain restorable, but cannot be assigned this time-bound certificate. An RPO breach fails **before** connecting to or modifying the target database. Monotonic timing starts before offsite retrieval, authentication and decryption and ends after database recovery validation; a breach returns failure even if the target was restored. Never promote a failed certificate.

The measured duration is **time to validated database recovery readiness**, not end-to-end production RTO. It does not include application server/container startup, restored external file stores, key escrow retrieval, DNS/load-balancer cutover, client reconnect, manual decision time, or work lost after the snapshot. Production RPO and RTO require rehearsal on representative data sizes, network latency and actual recovery infrastructure with these additional steps measured separately. This is a PostgreSQL **logical schema** backup, not physical WAL/PITR or a cluster-level replica. Without WAL-based recovery, writes after the snapshot are intentionally absent.

`BACKUP_OFFSITE_REQUIRE_DISTINCT_DEVICE=true` is the default. An independent filesystem is required for actual resilience; the automated CI fault drill uses distinct **directories** on the same CI filesystem and sets this option `false`. Its report explicitly marks `fault_domain_separation_proven=false` and must not be misrepresented as geographic or infrastructure-level disaster certification.

## Real fault-injection matrix

`CAJEER_TEST_DR=1` and `CAJEER_TEST_DATABASE=1` enable `tests/disaster_recovery.py` only in `APP_ENV=test` with **three separately provisioned** databases named `cajeer_dr_*`. It uses the exact SHA-256-verified candidate ZIP and actual PostgreSQL 17/18 on PHP 8.4/8.5:

1. Write a before-snapshot marker, perform real `pg_dump`, authenticate and publish a real encrypted offsite copy, then write a post-snapshot marker.
2. Reject an absent offsite mount, wrong encryption key, one-bit ciphertext corruption and a timed-out PostgreSQL client. Confirm no rejected attempt modifies the empty database.
3. Reject restoring over the source database and a deliberately stale-RPO attempt.
4. Move the local plaintext archive completely out of view, recover **only** from the encrypted offsite package into a distinct empty database and verify the restored marker is present and post-snapshot marker absent.
5. Verify actual queue and audit integrity and database readiness on the restored target. Verify the scratch plaintext is gone and no local archive was silently republished.

The CI database certification report includes stage execution, a version-and-archive-hash-bound independent DR report, actual RPO-bound and recovery-readiness measurements. The release gate rejects absent/incomplete/falsified stage evidence in each required PHP/PostgreSQL matrix combination. This code and local unit tests are **not** proof that the full CI matrix or a representative production disaster drill has passed until those jobs run successfully.

## Failed recovery and operating limits

The restore destination is not automatically switched into service or deleted. A killed/failed restore target may retain a Restore Fence marker; do not reuse it. Destroy and reprovision it through trusted DBA procedures after investigation. Inspect `backup:history` (failure entries deliberately do not disclose secrets). Preserve independently managed backup keys, PostgreSQL roles, permissions, TLS configuration, secrets and other non-DB files. Use a separate retention and immutable offsite policy; loss of both local and offsite copies, or the key, is not repairable by this command. Time-based certificates require accurate clock synchronization. Confirm HTTP readiness, real authentication and business workflows on the recovery deployment before any **manual** production cutover.
