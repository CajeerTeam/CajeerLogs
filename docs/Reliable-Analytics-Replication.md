# CajeerLogs 0.7.2 — Reliable Analytics Replication

0.7.2 adds a **working, opt-in PostgreSQL→ClickHouse replication worker**. PostgreSQL stays the source of truth. Existing ingestion, SDKs, search, webhooks, backup and export keep using PostgreSQL. There is **no synchronous remote write in an ingestion transaction**; a ClickHouse outage does not make HTTP ingestion dependent on ClickHouse. The new 0028 migration adds three statement-level triggers on `log_events`, so inserts, redactions/updates and deletes create an outbox entry in the same PostgreSQL transaction. Uncommitted/rolled-back events produce no outbox entry. Old events require explicit backfill.

## One-time upgrade

1. Back up PostgreSQL and stage a real ClickHouse 25.8+ deployment with valid HTTPS credentials. Install 0.7.2 over 0.7.1 and keep the previous migration files unchanged.
2. Run `php bin/cajeer migrate` using the authorized migration role, then `php bin/cajeer analytics:clickhouse migrate --confirm` using an authorized ClickHouse DDL role. Migration 0028 is required in PostgreSQL; ClickHouse 0003 creates `replicated_log_events` with `ReplacingMergeTree(revision,deleted)`.
3. For each authorized project ID, run `php bin/cajeer analytics:replication backfill PROJECT_UUID 1000` repeatedly until `end_of_scan=true`. The per-project cursor is committed with each batch. Re-running after an interruption resumes from the cursor; new writes are independently covered by the trigger. Backfill uses `ON CONFLICT DO NOTHING`, never overwriting newer tombstones.
4. Set up the dedicated service/timer in `examples/systemd/cajeerlogs-analytics-replication.{service,timer}` or call `php bin/cajeer analytics:replication drain 100 25` from a trusted scheduler. The provided unit assumes `/srv/cajeerlogs` and a `cajeerlogs` service account; adjust ownership and privileges for your deployment. Do not expose replication commands as public endpoints.
5. Check `php bin/cajeer analytics:replication status`, including `dead` and `oldest_pending_seconds`. Run project-scoped `analytics:replication compare /absolute/filters.json` after backfill and the backlog has drained. The report compares counts, not snapshot-consistent copies across two databases. `analytics:replication aggregate /absolute/filters.json` reads only the new replicated mirror, never the historical direct-write table.

### Commands

```
php bin/cajeer analytics:replication status
php bin/cajeer analytics:replication work 25
php bin/cajeer analytics:replication drain 100 25
php bin/cajeer analytics:replication backfill PROJECT_UUID 1000
php bin/cajeer analytics:replication replay PROJECT_UUID dead 100
php bin/cajeer analytics:replication replay PROJECT_UUID completed 100
php bin/cajeer analytics:replication aggregate /absolute/filters.json
php bin/cajeer analytics:replication compare /absolute/filters.json
```

Only the project named in `replay` is affected. `completed` replay is useful when rebuilding ClickHouse after restoring from an earlier snapshot; it reuses the last recorded revision, so replay does not multiply logical event counts. Never clear PostgreSQL outbox history until all outstanding recovery requirements are satisfied.

## Delivery/recovery guarantees

- `SELECT … FOR UPDATE SKIP LOCKED` leases at most 100 entries for 120 seconds. Multiple workers can drain independent events concurrently. Expired leases can be reclaimed. A successful ClickHouse INSERT is **not acknowledged** in PostgreSQL until after the remote request succeeds.
- Every event has a stable `(project_id,event_id)` and a PostgreSQL-assigned, monotone `revision`. A lost ClickHouse HTTP response can result in duplicate physical ClickHouse rows, but `ReplacingMergeTree(revision,deleted)` plus `FINAL` produces the latest logical state for the same event; old leases cannot acknowledge newer revisions. A delete creates a tombstone even when the original PostgreSQL event is already gone.
- Up to 20 automatic retries are made with exponential delay (5 to 3,600 seconds). Invalid rows and exhausted retries enter `dead`; they do not disappear silently. A scoped operator replay is required. A failed ClickHouse connection never becomes a confirmed delivery. Metrics show backlog and dead entries.
- ClickHouse writes are batched, with a hard 100-event/1 MiB transport limit. Invalid records in a batch are isolated and dead-lettered; unrelated valid records still proceed. Pending records are never acknowledged on an ambiguous connection error.

**Important limits:** `ReplacingMergeTree` deduplication is a **query-time** property when using `FINAL`, not a claim that exactly one physical row exists. Older message versions can remain in ClickHouse parts until storage merges/TTL. This is not a secure-erasure mechanism. The ClickHouse mirror currently uses a 180-day TTL based on `received_at`; do not backfill beyond that retention horizon or rely on this release for hard deletion SLAs. Align privacy/retention obligations before enabling full-event replication. If ClickHouse writes become permanently unavailable, PostgreSQL outbox grows and consumes database storage; alert on both queue length and available disk space.

### Safety

Use PostgreSQL runtime credentials with only the privileges needed by the outbox worker and a dedicated ClickHouse writer account. Use separate ClickHouse accounts for migration and analytical reads where possible. Network links must be TLS or an explicitly isolated private Docker network. No public admin/HTTP endpoint can start backfill or replay. All analytic reads require `project_id`; interval range is bounded to 31 days and filter values are passed as typed query parameters. For absolute data correctness run parity checks only after the lag is zero, and account for concurrent writes during the check.

CI includes a live PostgreSQL 17 + ClickHouse 25.8 integration test on the exact release ZIP covering transactional outbox, lost acknowledgements, stale leases, project isolation, revision replacement, deletion and replay. It is **not** considered passed unless the CI job has actually run.
