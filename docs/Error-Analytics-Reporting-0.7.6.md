# Error Analytics & Reporting — CajeerLogs 0.7.6

This release implements real, bounded, project-scoped PostgreSQL error grouping in the existing log access path. It does not require a separately deployed analytics service or a background reindex. All inserts, updates, deletes, historical redaction, retention and replay are reflected in the next query because **the original log_events table is authoritative**. The new partial error index accelerates project/time filtering. Full PostgreSQL E2E and performance certification remain required before claiming production readiness.

## Upgrade

Apply the patch over the complete 0.7.5 source tree, then run:

```sh
php bin/cajeer migrate
php bin/cajeer migrate:verify
php bin/cajeer openapi:lint
php tests/error_analytics.php
```

`migrations/pgsql/0031_error_analytics.sql` adds an immutable normalization/hash SQL function and a partial index on error events; it does not rewrite existing events or store raw error messages in an analytics table. On large installations, creating the index will take an exclusive schema migration window; schedule the migration accordingly. Existing SDKs, ingestion, replication, webhook workers and export workers remain unchanged.

## Runtime API

`GET /api/v1/logs/errors?project_id=UUID&from=RFC3339&to=RFC3339&interval=hour` returns exact error-event total, grouped fingerprints with counts/first/last seen, number of affected sources, sample event ID and a short example, UTC time buckets, and a comparison against the preceding *equal-duration* interval. Bounds are half-open: `[from, to)`. Only PSR-3 `error`, `critical`, `alert` and `emergency` are counted. Minimum `logs:read` permission is required. A source-bound token cannot query outside its source.

`GET /api/v1/logs/errors/report?...&format=json|csv` offers an audited, authorization-gated download; sessions additionally require `logs:export`. CSV includes a summary record, top group records and time buckets. JSON contains the full report structure. The download is synchronous and bounded to 31 days and 100 most frequent groups, and runs inside the existing search timeout. Reports explicitly return `group_count`, `groups_truncated` and `previous_groups_truncated`. If earlier groups have been truncated, missing previous counts are **unknown**, never zero. For large projects, narrow the period or source to obtain exhaustive group details. This is not a background batch report and does not represent all groups when truncated.

The Admin Panel has an **Отчёты об ошибках** page with time range/source selection, comparison metrics, trend bars, group table, CSV/JSON export and drill-down to Explorer. The `error_fingerprint` filter is also available in the Explorer for exact group membership; it requires a bounded 31-day interval.

## Fingerprints and privacy

PostgreSQL `log_error_signature(message, project_id, coalesce(service,''))` hashes a v1-normalized message prefixed by project UUID and service. Normalization masks UUIDs, e-mail addresses, IPv4 values, hexadecimal IDs and decimal runs. The grouping intentionally does **not** claim semantic equivalence for arbitrary stack traces, secrets, paths or unstructured identifiers; different text can still form different groups. Project and service are part of the fingerprint to avoid cross-project and cross-service grouping. Fingerprints are deterministic identifiers, **not encryption**; authorization and upstream redaction remain mandatory. The response sample is a 180-character excerpt of a permission-scoped, already-ingested message.

Group counts and trend buckets are derived with PostgreSQL `GROUPING SETS` in a read-only **REPEATABLE READ** transaction, so the current and previous periods belong to one database snapshot. The existing `SearchRuntime` statement timeout and rate limits apply. Partial source/project indexes and the 31-day upper bound protect ordinary deployments, but large unaggregated ranges can hit the timeout; this is an explicit `503 search_timeout`, not a silently incomplete result.

## Validation

```sh
php tests/error_analytics.php
CAJEER_TEST_DATABASE=1 APP_ENV=test php tests/error_analytics_database.php
node admin/verify.mjs
php bin/cajeer openapi:lint
```

Run the database test only against a **disposable PostgreSQL 17+ database**. It creates and removes its own schema. It exercises real ingestion, group normalization, prior-period comparisons, source/project isolation, fingerprint drill-down, rollback, updates and deletes. It is registered in CI and the release certification stage. A full production-ready claim requires passing database E2E and load tests on the target infrastructure.
