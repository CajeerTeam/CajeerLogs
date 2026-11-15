# Log Ingestion & Search Release — 0.3.0

0.3.0 consolidates the working PostgreSQL ingest/search runtime developed in 0.2.1–0.2.9. API v1, the original sync/async routes, HMAC v1/v2, validation, redaction policies, delivery families, signed cursors and the queue remain in service. No new numbered migration; 0001–0014 are unchanged. Channel remains public-preview until real CI evidence passes. No benchmark numbers are bundled as certification.

## Upgrade 0.2.9 → 0.3.0

1. Stop producers (or quiesce writes) and workers. Back up with `php bin/cajeer backup:create`; save the application, `.env` and security master separately.
2. Overlay the changed-files ZIP onto exactly 0.2.9. Do not use this delta on older releases; use a full distribution for 0.2.0/0.1.0.
3. Run `php bin/cajeer version`, `php bin/cajeer migrate:plan`, `php bin/cajeer migrate`, `php bin/cajeer migrate:verify`, `php bin/cajeer db:verify`, `php bin/cajeer doctor`, `php bin/cajeer openapi:lint`. Restart all processes, then check `/ready` and an authorized ingest/search. No mandatory new env variables.
4. Preserve the configured security master. It is required for HMAC v2 encrypted keys and delivery digests. Existing idempotency windows, credential-family behavior and signed cursors remain in force. Backups of 0.2.9 are accepted. Follow existing restore quarantine and credential revocation procedure.

## Read correctness and budgets

The read decoder preserves historic JSONB decimal precision by returning values with more than 15 significant digits, overflow or underflow as strings, matching the existing ingest precision policy. Big integers outside signed 64-bit remain strings. Stored SQL values are not changed; `context_equals` is still type-sensitive against JSONB. Signed 64-bit integers within PHP range are emitted as JSON numbers (JavaScript Number may not represent all such integers exactly; producers should use strings for exact external IDs). List/detail/CSV/JSONL agree on the same presentation. The digest is over presented page data, not a database checksum. Historical rewrite still uses the original patch semantics; it does not rewrite unrelated JSONB numbers.

Page items: 2 MiB. Individual detail: 4 MiB, checked in PostgreSQL before transfer and in PHP before JSON response; oversize returns 413 rather than 404 or partial data. The server cannot guarantee an absolute memory bound for PostgreSQL serialization of arbitrarily large legacy rows. Search budget covers SQL and cooperative response assembly, with no partial HTTP success when exhausted. Existing global no-store is retained and explicitly applied to log detail and stats. Database connection-loss classifications map to temporary 503/Retry-After.

Snapshot is a bounded received_at view, not a permanent MVCC transaction; retention and redaction still change history. Clock reversal, manual writes and legacy adoption remain outside the writer fence guarantee. See [Pagination](Pagination-Log-Explorer.md) for precise semantics. A page digest mismatch returns 412; restart the search or refresh the page.

## Reproducible benchmark

Use an isolated test project with a source, a read/write credential, active workers and sufficient quotas. The load generator writes real events. For v2, set CAJEER_TOKEN, CAJEER_SIGNING_KEY_ID and CAJEER_SIGNING_SECRET through a secure environment, never in shell command arguments. For v1 omit the two signing variables. TLS verifies the certificate; `--ca-file` accepts the trusted private CA.

```bash
python3 scripts/benchmark.py --url https://logs.example.test --project PROJECT_UUID --source app --requests 120 --batch 25 --concurrency 8 --verify-explorer --confirm
```

Measures single/batch/async latency and end-to-end throughput, replays identical bytes/key with fresh nonce, waits for terminal jobs, and compares UUID sets. With `--verify-explorer` the final pass uses snapshot pages (20 events), checks the first reverse page and conditional JSON export. Errors are nonzero exit; results are measurements of the specific run only. No secrets or event payloads are printed. Python SDK `ca_file` accepts a private CA path without disabling certificate or hostname verification.

## Release gate

Local (no DB): `php tests/release_pipeline.php`, `python3 tests/benchmark.py`, `python3 tests/certify.py` plus the existing regression, SDK and browser suites. Local fixtures do not certify PostgreSQL.

Actual disposable PostgreSQL: set `APP_ENV=test CAJEER_TEST_DATABASE=1` and explicit DB credentials; `python3 tests/pipeline_database.py` installs a fresh isolated schema, runs real HTTP with Python SDK HMAC v2, validates project and source redaction, rotation/replay, worker, mixed source batch, snapshot late arrivals, reverse page, conditional export, source scope and CLI export; cleanup drops only its randomized schema. Do not use live databases. The production Compose smoke calls the same scenario over HTTPS and runs both v1 and v2 benchmarks.

Build the full archive with `python3 scripts/release.py build release-out/CajeerLogs-0.3.0.zip`. Gate:

```bash
python3 scripts/certify.py database release-out/CajeerLogs-0.3.0.zip \
  --from-010 /tmp/cajeer-0.1.0 --from-020 /tmp/cajeer-0.2.0 \
  --from-029 /tmp/cajeer-0.2.9 --output /tmp/database-certification.json
```

Requires actual previous source trees, disposable database plus two distinct restore databases, PostgreSQL client tools, PHP pdo_pgsql and all existing test prerequisites. The exact archive is verified and unpacked; all database suites, integrated pipeline and three upgrades are mandatory in each PHP 8.4/8.5 × PostgreSQL 17/18 job. Final release gate validates four matching SHA-256 reports and runs real Docker smoke on the same archive. CI additionally requires SDK, PHPStan/PHPUnit, TLS/pooling, split roles and browser E2E. Reports are not independent security certifications. Missing tools or failed stages block certification. Historical commits must exist in repository history for the CI upgrade jobs.
