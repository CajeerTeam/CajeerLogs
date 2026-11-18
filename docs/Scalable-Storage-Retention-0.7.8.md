# CajeerLogs 0.7.8 — Scalable Storage & Retention

This release implements **operator-driven**, bounded storage transitions. It does **not** transparently repartition the live `log_events` table: the UUID primary key, existing ingestion/foreign keys, signed pagination, rollups and CDC triggers must remain compatible with 0.7.7. A live-table swap would require a separately tested online migration and is deliberately not attempted. `log_events` remains the authoritative **hot** searchable tier. `archived_log_events` is a **real PostgreSQL RANGE-partitioned** warm archive; individual compressed, verified S3 objects form the **cold** tier. Archived events are not available in `/logs` or the Dashboard until restored to hot storage.

## Upgrade

Back up and verify 0.7.7, overlay the changed files, run:

```bash
php bin/cajeer migrate
php bin/cajeer migrate:verify
php bin/cajeer db:verify
php bin/cajeer doctor
```

Migration `0033_storage_tiers.sql` creates the partitioned archive parent and S3 segment metadata; no existing migration or log table is rewritten. Monthly warm partitions are created by `storage:archive stage` under a PostgreSQL advisory transaction lock. Migration fingerprints intentionally exclude only attached monthly archive partitions and their child indexes; the parent schema, triggers, functions and all unrelated objects remain drift-checked. Database backup includes the warm archive and S3 metadata, **not the external S3 object bytes**. Back up and replicate the S3 bucket independently and verify recovery.

## Storage lifecycle

Choose a month containing old `received_at` values (UTC), an immutable project UUID and a small batch size (1–500). **No automatic eviction or archival is enabled by default.** Project **and all its sources** must have retention disabled; policies cannot be enabled while warm/cold copies exist. This prevents using archive tiers to silently bypass ordinary data-expiry rules. Existing retention deletion of hot data continues unchanged. Configure durable archive retention/erasure separately; do not infer compliance from project hot retention alone.

```bash
# Stage copies into a monthly PostgreSQL partition. Hot logs stay searchable.
php bin/cajeer storage:archive stage PROJECT_UUID 2026-10 100
php bin/cajeer storage:archive status PROJECT_UUID

# Explicit destructive transition. Only hot rows whose complete CURRENT snapshot
# equals the warm snapshot are deleted. Updates after staging are preserved hot.
php bin/cajeer storage:archive evict PROJECT_UUID 2026-10 100 --confirm

# Rehydrate from warm back to hot, preserving UUIDs and triggering normal rollups/CDC.
php bin/cajeer storage:archive restore-hot PROJECT_UUID 2026-10 100

# Offload up to 100 warm rows into a SHA-256 verified gzipped JSONL S3 object.
# After successful readback the corresponding warm rows are deleted transactionally.
php bin/cajeer storage:archive cold PROJECT_UUID 2026-10 100 --confirm
php bin/cajeer storage:archive cold-list PROJECT_UUID

# Restore by segment UUID, then restore-hot if API/Explorer access is needed.
php bin/cajeer storage:archive restore-cold PROJECT_UUID SEGMENT_UUID
php bin/cajeer storage:archive restore-hot PROJECT_UUID 2026-10 100

# Explicit erasure before enabling a retention policy. Repeat until empty.
php bin/cajeer storage:archive purge-warm PROJECT_UUID 2026-10 500 --confirm
php bin/cajeer storage:archive purge-cold PROJECT_UUID SEGMENT_UUID --confirm
```

Repeated commands process only bounded batches. Status reports warm rows and cold objects/row/byte counts. Cold metadata retains exact key, count, byte length and SHA-256. A remote upload is independently read and hashed before its metadata transaction commits. A crash after remote upload but before database commit can leave an **orphaned object**, never silently delete a confirmed row; periodically audit bucket prefixes against the metadata. A remote DELETE is idempotent; if metadata commit fails, retry the same `purge-cold` command. Offload holds row locks across the bounded S3 request, so operate in a maintenance window or lower batch sizes if network latency is high.

Hot eviction is an explicit **semantic removal** from normal search/analytics. Triggers produce ordinary deletion changes (including rollup updates and ClickHouse tombstones). Restoring to hot produces normal insertion changes. Historical reports will change accordingly. Do not offload events needed for continuous searchable retention without a separate archive-aware query workflow. `storage:archive stage` is safe to repeat; stale snapshots must be purged and staged again before they can be evicted.

## S3 configuration and security

```dotenv
S3_ENDPOINT=https://s3.example.net
S3_BUCKET=cajeer-archive
S3_ACCESS_KEY=<dedicated-service-access-key>
S3_SECRET_KEY=<dedicated-secret>
S3_REGION=us-east-1
S3_PATH_STYLE=true
S3_TLS_CA_FILE=
# Only for isolated local test emulators, NEVER public production endpoints:
S3_ALLOW_INSECURE=false
```

Production requires HTTPS with peer/hostname verification; disable redirects and use a private endpoint defined by the operator. The client signs PUT/GET/DELETE with AWS Signature V4 and verifies every object by exact-byte SHA-256 after an independent GET. Object size is capped at 16 MiB; uncompressed content is also bounded. No caller-provided arbitrary path or SQL is accepted. Use a restricted bucket/IAM policy, object-side encryption at rest (KMS/SSE), versioning where required and private credentials outside source control. This release does **not** implement client-side S3 payload encryption; trust the bucket's enforced encryption and access controls. `S3_ALLOW_INSECURE=true` is for isolated mock servers only.

## Testing and limits

`python3 tests/s3_storage.py` exercises real signed HTTP traffic with a strict local S3 mock, including PUT/readback, checksum and DELETE. `CAJEER_TEST_DATABASE=1 python3 tests/s3_storage.py` additionally executes `tests/storage_tiers_database.php` against disposable PostgreSQL, testing partitions, rollback/eviction, exact snapshots, source/project isolation, remote offload/recovery, purge and retention guard. Full production rollout still requires a real S3 service and PostgreSQL under production load, backup/restore and retention validation. The release is **public-preview**, not performance-certified.
