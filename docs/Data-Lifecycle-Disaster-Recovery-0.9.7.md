# CajeerLogs 0.9.7 — Data Lifecycle & Disaster Recovery

## Retention and archive lifecycle

`retention:preview` reports the current project/source policy and never deletes data. `retention:run`/`retention:cycle --confirm` remove expired **hot** `log_events` in bounded PostgreSQL transactions. Policies use server `received_at`. Source retention can shorten but not extend project retention. **Cold and warm archives are not silently subject to hot pruning**: SQL migration `0033_storage_tiers.sql` rejects enabling retention when archive copies exist. Purge the warm/cold copies explicitly and verify their deletion before changing a retention policy. Do not edit historic migrations.

A project with no retention policy can stage hot snapshots to a PostgreSQL monthly warm partition (`storage:archive stage`), evict only rows with exact matching snapshots (`evict`), offload bounded compressed segments to verified S3-compatible storage (`cold`), and restore the *whole verified cold object* to warm (`restore-cold`). `restore-hot` restores absent rows only, using the original event schema and primary keys. Offload keeps remote objects immutable and requires independent GET/sha256 confirmation before removing a warm row. A failed PostgreSQL commit after successful S3 upload can leave an unreferenced remote object; configure bucket lifecycle/quarantine and periodically reconcile remote inventory. Do not bulk-delete S3 prefixes without independent proof.

## Executable offsite verification before recovery

Use an isolated operator account and a production-configured TLS S3 endpoint:

```sh
php bin/cajeer storage:archive status PROJECT_UUID
php bin/cajeer storage:archive cold-list PROJECT_UUID
php bin/cajeer storage:archive verify-cold PROJECT_UUID SEGMENT_UUID
php bin/cajeer storage:archive scrub-cold PROJECT_UUID 25
php bin/cajeer storage:archive restore-cold PROJECT_UUID SEGMENT_UUID
php bin/cajeer storage:archive restore-hot PROJECT_UUID YYYY-MM 100
```

`verify-cold` performs a fresh signed remote GET, compares actual SHA-256 and compressed size, bounded gzip decoding, event count, unique IDs, project/source identities, UTC month and complete snapshot cross-check, then rechecks the PostgreSQL catalog record under a lock. `scrub-cold` applies this to the **newest N objects only** (default 25, max 100). The `complete` field is false if older objects exist; a scrub of 25 is not proof that the entire bucket was checked. Any unavailable or corrupted segment raises an error; it never reports success from catalog metadata alone. `restore-cold` checks the same verified payload and revalidates the catalog after remote I/O, serialized with remote purge and project-retention edits. Neither command promotes a restored DB to production.

For full inventory assurance, process the entire catalog under an operator-controlled schedule; `scrub-cold` is intentionally bounded, not a silent scan of an unbounded bucket. S3 versioning/replication, KMS/key escrow and immutable object-lock policies are configured separately at the storage provider. This software cannot reconstruct a deleted, missing or corrupted object without another verified copy. PostgreSQL logical backups include the cold *catalog* but do not contain the external cold *object bytes*: after a DB restore, verify the external S3 objects independently.

## Disaster recovery and measured objectives

```sh
php bin/cajeer backup:create
php bin/cajeer backup:replicate BACKUP_ID
php bin/cajeer backup:offsite-verify BACKUP_ID
php bin/cajeer backup:dr-certify BACKUP_ID EMPTY_RECOVERY_DATABASE 3600 600
php bin/cajeer backup:history
```

The DR command decrypts a complete authenticated offsite package into private ephemeral storage, checks the PostgreSQL archive, restores it only into a separately provisioned empty database and validates the resulting schema, queue accounting and audit chain. Recovered sessions and API tokens are revoked before the Restore Fence is published. Failed restore targets must never be promoted and must be destroyed and recreated by a DBA. The integration scenario adds 32 independently verifiable snapshot rows, checks the original row count and ordered-content checksum against the restored database, and proves that a post-snapshot write is absent.

**RPO:** a conservative observed upper bound, `disaster_start - snapshot_started_at`; the snapshot start is captured before `pg_dump`. **RTO measurement:** elapsed monotonic time from offsite retrieval/decryption through database readiness. The existing `recovery_readiness_seconds` is *not* end-to-end production RTO; it excludes DNS/traffic switching, application boot, external cold objects, secret retrieval and client reconnection. `backup:dr-certify` rejects an objective breach, yet never performs cutover. Production RPO/RTO require fault-isolated, representative full-service drills and independent evidence.

## Testing and CI

`php tests/cold_segment.php` tests real compressed/archive parsing with corruption, duplicate IDs, cross-project and snapshot mismatch. `CAJEER_TEST_DATABASE=1 python3 tests/s3_storage.py` runs actual signed HTTP S3 with fault injection and real PostgreSQL archive transactions; it verifies remote scrubbing and rejects corrupted/unavailable objects. `CAJEER_TEST_DR=1 CAJEER_TEST_DATABASE=1` with isolated databases enables `tests/disaster_recovery.py` and releases a SHA-256-bound DR report. The release CI also requires upgrade and rollback of 0.9.6 to 0.9.7. Passing unit tests is not a claim that a full DR exercise or production S3 inventory is certified.
