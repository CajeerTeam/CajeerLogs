# CajeerLogs 0.7.3 — Aggregation Engine

**Scope:** production PostgreSQL exact rollups, minute/hour/day API statistics, historical backfill and dimensional reconciliation. ClickHouse remains an optional independently replicated event mirror introduced in 0.7.2. This release does **not** claim that a non-idempotent ClickHouse materialized view is a correct rollup over replayed `ReplacingMergeTree` inserts.

## Upgrade and enablement

1. Take a verified PostgreSQL backup before installing the 0.7.3 changed files. Apply `php bin/cajeer migrate` with the normal database migration role. PostgreSQL migration `0029_analytics_rollups.sql` adds minute counters, an event-state ledger, per-project backfill cursors, and statement-level INSERT/UPDATE/DELETE triggers. Existing `0028` replication migration is **unchanged**; ClickHouse schema is unchanged.
2. New events are counted **inside the same PostgreSQL transaction** as their ingestion (and rolled back if ingestion rolls back). Updating a dimension or timestamp removes the previously counted contribution and adds the new one. A delete removes its original contribution. Changes to message-only fields are also retracted/reapplied in one transaction, preserving exact counters. No remote I/O is performed on the hot ingestion path.
3. For **each project UUID**, repeatedly run:

   ```sh
   php bin/cajeer analytics:rollups backfill PROJECT_UUID 1000
   php bin/cajeer analytics:rollups status PROJECT_UUID
   ```

   Stop when `complete` becomes `true`; the cursor is committed with each batch. Backfill selects events using `FOR SHARE` so competing event updates/deletes must wait until counted. Its event-state insert uses `ON CONFLICT DO NOTHING`, so events already handled by ingestion triggers are not counted twice. Parallel backfill runs on one project serialize on its backfill row; projects remain independent. **Do not enable rollup reads before the backfill completes.** The built-in API guard enforces this automatically.
4. Verify an aligned UTC interval (e.g., 00 seconds / 0 microseconds):

   ```sh
   php bin/cajeer analytics:rollups verify PROJECT_UUID 2026-11-18T00:00:00Z 2026-11-19T00:00:00Z
   php bin/cajeer analytics:rollups aggregate PROJECT_UUID 2026-11-18T00:00:00Z 2026-11-19T00:00:00Z
   ```

   `verify` compares every minute/source/level/service/host group to the underlying PostgreSQL rows, as well as overall counts; a matching total alone is not enough. Integrate nonzero `mismatched_groups` into monitoring. Run reconciliation in a quiet period or under a stable database snapshot; live writes between verifier queries can create transient differences.

## Query semantics

`GET /api/v1/logs/stats` retains its existing response shape: `total`, `by_level`, `by_source`, `source_count`, `sources_truncated`, `by_service`, `by_host`, and error-bearing time `buckets`, including minute/5-minute/hour/day grouping and ranking limits. The engine uses rollups only after the project's cursor reports `complete` and only for filters representable by the stored dimensions: project, source ID/slug, level, service, host and time. Free-text, tags, JSON, trace/request ID and multi-value search continue using exact `log_events` SQL. Partial first/last minute ranges use the original events at those edges; complete inner minutes use counters, ensuring arbitrary timestamp boundaries remain exact.

Project-scoped historical events without `source_id` cannot be represented in source-level counters. The engine detects them through a partial index and keeps that project on the original exact SQL query until the legacy records are adopted or removed.

The query runs against **PostgreSQL** (the durable source of truth). This avoids the at-least-once duplication and late-update issues of a naive materialized aggregate on ClickHouse `ReplacingMergeTree`. ClickHouse 0.7.2 aggregation still queries the versioned mirror with `FINAL`. Do not compare it to PostgreSQL without waiting for replication lag to drain.

## Operations and limitations

- Monitor `analytics:rollups status` and periodic `analytics:rollups verify` for every project. Distinct mismatched dimension groups are evidence of corruption or a non-snapshot verification, not something to silently suppress. No approximate statistics are returned by this engine.
- New minute rows are retained along with event-level state. Deleting source events by configured retention retracts their contributions and removes empty dimension groups in the original transaction. An event moved between minute buckets or projects removes the old group and increments the new group.
- The state ledger occupies extra PostgreSQL disk space (approximately one state row per retained event). Rollup maintenance increases PostgreSQL write IO and should be benchmarked on the deployment's peak-ingestion profile before broad rollout. The write side uses statement-level group operations rather than per-event PHP calls, but it still adds transactional work. Rollups do not replace indexes on `log_events`.
- Backfill is bounded to 2,000 history rows per transaction and does not require full-table locks. It never auto-runs on startup. For historical data restored after a completed backfill, run a controlled rebuild/reconciliation procedure rather than assuming the completion flag was reset; restoration must include the rollup tables and their ledger consistently.
- PostgreSQL 17+ and PHP 8.4+ are required by the project. Full certification requires CI database tests on 17/18 and load tests on the actual deployment. No local PostgreSQL E2E result is implied by this documentation.
