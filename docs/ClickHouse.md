# ClickHouse Integration Core — 0.7.1

ClickHouse is an **optional analytics engine**, not the canonical log database. PostgreSQL ingestion, the queue, search, exports, and existing Dashboard retain their behavior. **0.7.1 does not perform dual-write or PostgreSQL→ClickHouse replication**; that requires a durable outbox and replay in 0.7.2. Operators can insert validated records using the PHP SDK or the CLI.

## Configure and operate

Production requires `CLICKHOUSE_DSN=https://<host>:8443`, a trusted server certificate (optionally `CLICKHOUSE_TLS_CA_FILE`), `CLICKHOUSE_USERNAME`, `CLICKHOUSE_PASSWORD`, and `CLICKHOUSE_DATABASE`. `CLICKHOUSE_TIMEOUT_SECONDS` is 1–30 (default 5). Plain HTTP is refused unless `CLICKHOUSE_ALLOW_INSECURE=true` is explicitly set on a private, network-isolated deployment. Never expose ClickHouse HTTP to the public network, and use separate ClickHouse accounts for schema administration, writes, and reads where possible. Neither HTTP credentials nor query values are logged.

The optional `clickhouse` Compose service (`docker compose --profile analytics -f compose.production.yaml up -d clickhouse`) is internal to `backend`; no port is published. For this local Compose network only, `CLICKHOUSE_DSN=http://clickhouse:8123` and `CLICKHOUSE_ALLOW_INSECURE=true` permit internal HTTP. Provide a strong `CLICKHOUSE_PASSWORD` and start the runtime with the same configuration. If using an external ClickHouse cluster, configure HTTPS instead. Set `ANALYTICS_DRIVER=clickhouse` only when manually using the analytics service; it does not swap public API statistics before the replication stage.

Run from an authorized operator environment:

```
php bin/cajeer analytics:clickhouse status
php bin/cajeer analytics:clickhouse migrate --confirm
php bin/cajeer analytics:clickhouse aggregate /absolute/path/scoped-filters.json
php bin/cajeer analytics:clickhouse record --confirm /absolute/path/validated-events.json
```

Example filters: `{"project_id":"11111111-1111-4111-8111-111111111111","from":"2026-11-18T00:00:00Z","to":"2026-11-19T00:00:00Z","interval":"hour"}`. An input event has nonzero `id`, `project_id`, `source_id`, `occurred_at` (or `timestamp`), `received_at`, `level`, `source`, `message`, optional `service`, `host`, `context`, `trace_id`, `request_id`, and `tags`. Only use raw data from authorized, validated PostgreSQL events, never untrusted external JSON. Operator files must not be symlinks. The client enforces a 100-event / 1 MiB bound per insert, bounded JSON result size, a hard-coded query shape, mandatory project and interval scope, and typed ClickHouse HTTP parameters (`{project_id:UUID}`, etc.).

`migrate` executes sequential versioned SQL files and persists their SHA-256 checksums in ClickHouse. Run only one migration operator at a time; ClickHouse DDL does not provide a multi-statement transaction. Interrupted migrations are re-runnable because DDL uses `IF NOT EXISTS`. Existing 0.7.0 unscoped ClickHouse tables receive `project_id` and `source_id` with the nil UUID; their legacy rows **remain invisible to scoped analytics** and require a separate verified migration/backfill once provenance is available. The old MergeTree sorting key is not rewritten in place. A release migration checksum change must be reviewed, never silently overwritten.

## Security and operations

Never query a project without its authenticated UUID; every aggregate statement includes a project filter. Database authentication and authorization remain essential: choose read-only credentials for analytics and separate writer/admin credentials for manual records/migration. The application supplies only fixed SQL strings and validates filter values before passing them as HTTP-bound parameters. TLS certificate verification and hostname verification are mandatory for HTTPS. HTTP redirects are disabled, requests have a finite timeout, and responses are capped at 2 MiB. Database credentials must be stored in private environment configuration.

Health checking, schema creation, actual HTTP insert and aggregate are tested by `tests/clickhouse_database.php` against a live ClickHouse instance in CI; the independent `tests/clickhouse_http.py` mock checks parameter binding, HTTP errors and failure handling without requiring Docker. No performance or durable-replication claim is made for this stage.
