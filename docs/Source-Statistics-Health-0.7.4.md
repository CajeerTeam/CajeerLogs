# Source Statistics & Health — CajeerLogs 0.7.4

## Production deployment

Apply release files to **0.7.3**, back up PostgreSQL, then run `php bin/cajeer migrate` **before restarting ingestion or the scheduler**. Migration `0030_source_ingestion_monitoring.sql` is required. Run `php bin/cajeer migrate:verify` and the usual deployment smoke checks. PostgreSQL 17+ is the source of truth. No ClickHouse dependency is introduced for these operational counters.

## Real accounting

Every newly accepted source admission writes to `source_ingest_minutes` in the same PostgreSQL transaction as the existing source counters, quota checks, HMAC nonce and sync/async admission. A rolled-back transaction or failed savepoint leaves no accepted metric. A keyed idempotency replay skips admission and therefore cannot increment the time-series twice. Authenticated policy denials (source disabled, body/batch size, project/source quota) increment rejected minute counters after the admission savepoint has rolled back, in the same transaction as the existing cumulative rejection counter. Requests denied before source resolution (invalid token, JSON, global envelope size, etc.) **are not included**.

The migration adds `sources.ingestion_metrics_since`, marking the start of minute-level tracking. Existing cumulative totals (`accepted_events_total`, `rejected_events_total`) remain unchanged. **Historical minute buckets cannot be reconstructed reliably from retained logs**, because accepted async jobs, policy rejections, deduplicated requests and retention affect their interpretation. Never treat initial zero time-series as evidence of no historical traffic.

Counters are aggregated in PostgreSQL by UTC minute with an index on `(project_id,source_id,minute)`. Each accepted/rejected batch adds one SQL statement, regardless of the number of participating sources. The scheduler runs `source_metrics_cleanup` every five minutes; each run deletes no more than 10,000 buckets older than 32 days with `FOR UPDATE SKIP LOCKED` and an index on `minute`. It cannot erase lifetime counters.

## API and Admin Panel

Existing `GET /api/v1/projects/{project}/sources/{source}/stats` fields remain intact. New `ingestion` object contains accepted/rejected 60-minute and 1,440-minute bucket totals, per-minute throughput and peaks, rejection percentage, 24 zero-filled UTC hourly bars, and a bounded latest-2,000 stored-event sample with error count, future timestamps, average and p95 receive delay. These are **sample metrics**, not population-wide error totals. Stored-log counts (`events`, `events_24h`, `events_60s`) retain their previous exact semantics and can decrease after retention.

`GET .../stats?idle_minutes=60` accepts a 1–10080 minute threshold and uses existing project/source-scoped RBAC and source tokens. The returned `ingestion.health.state` is `receiving`, `idle`, `never_received`, `disabled`, `archived` or `unknown`. It describes **authenticated API arrivals**, not service uptime, host network reachability or whether a collector process is running. `network_availability_checked` is always false. The monitoring UI in Admin Panel → Projects → Open source shows the same metrics and hourly history. The monitoring threshold uses the source list control.

## Verification

`php tests/source_monitoring.php` checks source state classification, migration parser compatibility and integration points. `CAJEER_TEST_DATABASE=1 php tests/source_monitoring_database.php` verifies real PostgreSQL migrations, HTTP authentication, admission with idempotency replay, rollback, project isolation, policy-denial metrics, hourly history, delay sample and bounded cleanup. The database test requires a disposable PostgreSQL installation and must pass in the CI matrix before production acceptance. Existing PostgreSQL event/rollup/history and backup migrations remain unchanged.
