# Advanced Analytics Dashboard — CajeerLogs 0.7.5

This release extends the existing Admin Panel **Overview**. It does not introduce a second dashboard, a separate search backend or a new PostgreSQL migration.

## Live charts

The existing authenticated `GET /api/v1/logs/stats` response gains `level_buckets`: a bounded array of `{timestamp, level, count}` for each nonempty UTC bin. `total`, `by_level`, `by_source`, `buckets`, `by_service` and `by_host` retain their previous meanings. For every bin, the sum of all level counts equals `buckets[].count` and the sum of error/critical/alert/emergency counts equals `buckets[].errors`.

Both execution paths compute these values from the same source data:

- A project with completed rollup backfill, no legacy null-source events and supported filters is served from incremental PostgreSQL minute rollups (including exact raw-data edges of partial minutes).
- Rich/unsupported filters, incomplete backfill and legacy events use the existing exact PostgreSQL SQL query. The extra level-bucket grouping is calculated in the same query, with the existing project/source permission predicate.

There is no additional per-bin HTTP request or new write-side index. All bounds are limited by the existing `LogQuery` interval and maximum-range validation.

## User interactions

1. Select a project, period, UTC grouping and optional source.
2. The primary chart displays event count and error volume, and a new chart displays `100 × errors / events` (defined as zero for empty bins). Percentage uses a fixed 0–100 scale.
3. Select one of the eight severity levels to see its actual event-count time series.
4. Click or keyboard-activate a bucket to open **Explorer** with exact `[from,to)` UTC bounds, source scope and the relevant level filter. The dashboard does not create a new permission bypass.
5. Enable **Compare with previous period** for an equal-duration preceding UTC interval. The comparison presents its own time axis and count trend, plus level/source/service changes. For sources absent from a truncated top-100 list, or services absent from a truncated top-10 list, the value is shown as `—` rather than falsely treated as zero.

The dashboard respects the existing project-level `logs:read` gate, uses the standard authenticated API client, cancels requests when navigation changes and preserves the existing auto-refresh behavior. It does not expose ClickHouse data that has not passed replication verification.

## Validation

```sh
node --test tests/analytics_dashboard.mjs
node admin/verify.mjs
php tests/analytics_rollups.php
php bin/cajeer openapi:lint
```

Real PostgreSQL coverage for raw and rollup results, including post-update/delete chart counts and permission scopes, is in `tests/analytics_rollups_database.php`, executed by the existing disposable-database CI job. Browser interactions are tested in `tests/admin_browser.py` where Playwright is available.

No SQL migration is required for an upgrade from 0.7.4. Production certification still requires the database and browser integration jobs to pass on the target release artifact.
