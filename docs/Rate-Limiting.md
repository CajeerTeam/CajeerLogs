# Rate Limiting & Abuse Protection (0.4.5)

CajeerLogs uses PostgreSQL as the shared rate-limit authority; no Redis is required. Apply migration `0020_rate_limit_buckets.sql` before starting the new runtime. The previous fixed-window counters and project/source event quotas are preserved.

## Policies

| Setting | Default | Scope |
|---|---:|---|
| `API_RATE_LIMIT_PER_MINUTE` | 600 | Common peer and authenticated principal budgets across identity, logs and ingest; previous route budgets remain in force |
| `API_RATE_SEARCH_PER_MINUTE` | 120 | Log list and event detail per principal |
| `API_RATE_ANALYTICS_PER_MINUTE` | 30 | Log, project and source statistics per principal |
| `API_RATE_EXPORT_PER_MINUTE` | 10 | Log and NDJSON audit export per principal |
| `API_RATE_ADMIN_WRITE_PER_MINUTE` | 60 | Identity/controller writes other than login and logout |
| `API_RATE_BURST_PERCENT` | 100 | Initial bucket capacity as percentage of each rate; 1–100, minimum one request |

All rates must be integers in 1–100000. Use the same configuration on all replicas; policy changes create new token buckets (old buckets expire), and should be rolled out together. `API_RATE_BURST_PERCENT=25` permits at most a quarter-minute budget instantly, then replenishes continuously. A rate of 60/min replenishes one request per second. Lowering the percent affects all shared token buckets. Existing login limits (20/IP and 10/account per 300 seconds), step-up/password limits (5 per 300 seconds), identity principal limit (300/min), explicit token request limits and project/source event quotas still apply. Those legacy limits remain fixed-window and can be stricter than the new buckets. Event quotas charge committed admissions, not HTTP attempts; a batch larger than its quota must be reduced, not retried indefinitely.

## Enforcement

The API uses canonical transport peer addresses (IPv4-mapped IPv6 collapses to IPv4). Forwarded headers are not trusted. Only validly framed requests that reach the controller enter the application limiter; the gateway additionally limits dynamic requests to 50/s per peer (burst 100) and 64 concurrent requests per peer. These gateway limits are per Nginx instance, not cluster-wide. Behind a proxy, its address is used until trusted-proxy configuration is deliberately implemented; do not use arbitrary forwarded headers to bypass this.

`api_rate_charge` locks one bucket row, uses PostgreSQL time after acquiring the lock, and replenishes integer credits (one request = 60 million credits). Capacity is clamped, so idle time never creates an unbounded burst. Each attempt charges the peer and principal before the operation; a rejected later budget does not refund earlier budgets. Request limits are charged outside the ingest/business transaction. Ingest reauthorization under lock never charges twice. Policy storage failures reject requests rather than bypass limits. Authenticated principals share budgets across sessions and endpoints; token IDs, not secrets, identify machine principals. Separate project/source event quotas remain transactional and are not changed by this release.

HTTP 429 from request buckets carries `Retry-After` in whole seconds, rounded up to the next admissible token or remaining fixed-window time, and `Cache-Control: no-store`. Other budgets may still be exhausted when that time elapses. The gateway supplies an advisory one-second delay. Legacy project/source quota responses retain their existing 60-second guidance. Never retry mutations automatically: check the result and use the existing Idempotency-Key for ingest.

## Operations

The protected System report and Admin → System display effective configured rates and burst capacities, not per-principal secrets or remaining balances. The scheduler's auth cleanup deletes up to 10,000 expired token buckets per run (two hours of inactivity); the expiry index avoids full-table scans. Monitor cleanup and PostgreSQL storage under hostile traffic. The table is not a hard cap on distinct peers. Nginx edge limits and upstream network protection remain important against distributed denial of service.

Prometheus exposes existing `cajeerlogs_ingest_rejections_total` and `cajeerlogs_search_rejections_total` with `reason="rate_limited"`, plus `cajeerlogs_identity_rejections_total{mode="login|read|write",reason="rate_limited"}`. These are process-shared file counters on each runtime storage volume, not globally deduplicated cluster metrics; scrape each independent instance/volume appropriately. No principal, IP, token, or project IDs become metric labels. Gateway-level rejects do not reach PHP and are not included in these application counters.

## Verification

`php bin/cajeer migrate`, `php bin/cajeer migrate:verify`, `php bin/cajeer doctor` and `php bin/cajeer openapi:lint` after upgrade. Run `CAJEER_TEST_DATABASE=1 APP_ENV=test php tests/rate_limit_database.php` against a disposable PostgreSQL database: it tests concurrent independent PHP workers, refill, cleanup and real HTTP dispatch. The release gate includes this test and actual upgrade from 0.4.4. Do not run destructive test fixtures on production databases. Full load benchmarks and trusted proxy/TLS policies remain separate release work.
