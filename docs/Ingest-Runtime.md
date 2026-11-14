# Ingest Runtime — 0.2.1

Existing `/api/v1/ingest`, `/api/v1/ingest/async` and job-status API remain compatible. No SQL migrations are added. Backups from 0.2.0 retain the same history. The single `IngestService` runs both write paths: transport and credential shape, envelope checks, admission, PostgreSQL schema guard, rate limits, auth and HMAC, validation/redaction, transactional token recheck, nonce, receipt/job lookup, project/source admission, INSERT or durable enqueue, outbox/receipt and COMMIT. A confirmed synchronous write returns 201; durable enqueue returns 202, not a claim that logs are stored. Replay returns 200. No arbitrary SQL or unknown COMMIT is retried.

## HTTP requirements

- Body: at most 1 MiB or `APP_MAX_BODY_BYTES`, whichever is smaller. The public entrypoint applies the route-specific cap before reading `php://input` (at most limit+1 bytes). Nginx/PHP may buffer before application code; this is not a guarantee about upstream buffering.
- `Content-Type: application/json` or structured `type/subtype+json`, optionally `charset=UTF-8` (quoted or unquoted). Other media parameters/charsets and compressed bodies are rejected with 415. Empty JSON is 400.
- When visible to the application, Content-Length must be decimal, bounded and equal the actual decoded body length. Conflicting length/transfer encoding and unsupported transfer encoding are 400. Chunked transfer is accepted when the trusted HTTP server has already decoded it and the framing is unambiguous. PHP SAPI cannot recover original wire headers once a proxy normalizes them. A trusted HTTP server must reject malformed/ambiguous wire framing; do not expose FPM directly. The application does not implement chunk decoding or trust forwarding headers for IP/TLS.
- Production requires HTTPS. Existing bearer, HMAC canonical body and key semantics are unchanged. Credentials and body never appear in metrics, error logs or correlation IDs.

## Admission and timing

| Setting | Default | Range |
|---|---:|---:|
| `INGEST_MAX_INFLIGHT` | 16 | 0–128 (0 disables) |
| `INGEST_BUDGET_MS` | 25000 | 100–120000 |

`INGEST_MAX_INFLIGHT` is a local bound on active sync/async write service operations, not a distributed or per-token quota. Slots are nonblocking `flock` locks in private `storage/cache`; process exit releases them. Keep this directory writable by the PHP service account. All PHP workers sharing a local filesystem and the same limit share slots; do not delete lock files during service. Use local storage with working POSIX locks, not an unverified network filesystem. Separate replicas need separate capacity planning; database/project/source quotas remain shared. Slot saturation returns 503 `ingest_busy` + `Retry-After: 1`. Existing durable queue capacity returns 503 `queue_full` + `Retry-After: 5`.

The monotonic processing budget starts after HTTP envelope checks. It is checked before/after connection and schema admission, after validation, at transaction boundaries and before durable commit. The transaction uses `SET LOCAL statement_timeout` capped to the remaining budget and configured `DB_STATEMENT_TIMEOUT_MS`; retry attempts reuse the original deadline. Connection setup and pretransaction statements retain existing DB timeouts. This is **cooperative** processing control, not a hard whole-request deadline: libpq connection attempts, pretransaction operations, rollback, network I/O and COMMIT may outlast the budget. Never report failure merely because time expired after a confirmed COMMIT. Configure DB connect/statement/lock timeouts and gateway timeout consistently. Avoid raising the budget above gateway timeout without also reviewing infrastructure timeouts. Nginx sets header/body idle timeouts and buffers requests; malformed requests rejected by the gateway may not reach PHP.

Storage/connectivity failures return safe 503 `service_unavailable` + `Retry-After: 5`. 429 retains rate-limit guidance. 400/401/403/409/413/415/422 are not blindly retried. On temporary failure retry the original bytes and Idempotency-Key with a fresh HMAC nonce/timestamp. A 503 does not prove the original write failed: COMMIT may be uncertain. Without a sync key a retry can duplicate events. Async requires a key. Existing receipt windows and token scoping apply.

## Monitoring

`/metrics` exposes `cajeerlogs_ingest_requests_total{mode,status}`, `cajeerlogs_ingest_rejections_total{mode,reason}`, `cajeerlogs_ingest_duration_seconds` (cumulative histogram) and `cajeerlogs_ingest_metrics_available`. Labels are bounded; no token, IP, request ID, source, body or arbitrary exception message. Counts represent application HTTP responses, not event count or durable writes; 200 replay and 202 enqueue are distinct status series. Timing includes the application handler, not upstream buffering or network delivery. Request construction failures reaching PHP are counted too. Gateway-only failures are not counted. Aggregates are local to `storage/cache`, updated under a file lock with atomic replace; storage loss/reset can reset counters. Metrics are best effort and do not affect durable delivery. Monitor disk and file permissions; a damaged metrics file is reported unavailable and may be reset by stopping writers and removing the metrics JSON. No per-request payload is persisted. Exporter uses existing Prometheus token protection.

## Upgrade and test

Back up PostgreSQL and configuration, stop workers/ingest, overlay changed files onto 0.2.0, run `php bin/cajeer version`, `php bin/cajeer migrate:verify`, `php bin/cajeer db:verify`, `php bin/cajeer doctor`, `php bin/cajeer openapi:lint`; restart services. No new migration. Production Compose must be rebuilt to use the new images.

`php tests/ingest_runtime.php`, `python3 tests/http_runtime.py` cover envelope, slots, deadline, metrics and failure responses. `CAJEER_TEST_DATABASE=1 php tests/ingest_database.php` requires a disposable real PostgreSQL database and checks timeout while a separate session holds a token row lock, rollback and retry. Existing integration/production smoke and certification gate remain mandatory; the gate checks 0.1.0 and 0.2.0 upgrade trees against 0.2.1. See the database release instructions for required isolated databases. No integration certification is implied by the local tests.

For the full release gate use `python3 scripts/certify.py database release-out/CajeerLogs-0.2.1.zip --from-010 /path/to/0.1.0 --from-020 /path/to/0.2.0 --output /tmp/database-certification.json` with the disposable PostgreSQL environment described in the Database Core Release documentation.
