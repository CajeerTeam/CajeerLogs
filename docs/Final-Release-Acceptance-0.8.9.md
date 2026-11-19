# CajeerLogs 0.8.9 — Final Release Acceptance

This is an **executable release acceptance gate**; creating a candidate archive is not a
certification. The final GitHub Actions `public-release` job can pass only after all
mandatory jobs succeed and their evidence verifies against the exact release ZIP SHA-256.

## Security

`python3 scripts/security_acceptance.py release-out/CajeerLogs-0.8.9.zip --output security-acceptance.json`

The runner extracts the verified archive into a clean temporary directory and executes
six real regression suites: deployment policy, TLS, secure installer, ingest
authorization, security hardening and security operations. A nonzero return code,
missing assertion, excessive output or timeout fails the job. Each execution has a
bounded duration and hashed output; the report excludes secrets, raw logs and
credentials. Runtime environment is whitelisted, not inherited from production.
The final release gate rejects a report with missing/duplicated/failed stages,
insufficient assertions, invalid timings or a mismatched release digest.

This is not an external penetration test or CVE-free certification. The existing
`composer audit` and supply-chain validation remain separate mandatory CI steps.

## Matrix and recovery

CI verifies PHP 8.4/8.5 × PostgreSQL 17/18, the real application/DB integration
suites, upgrade and rollback rehearsals from supported versions (including 0.8.8),
DR recovery, ClickHouse and storage fault recovery, SDK builds, browser tests and
repeated integration in independent extracted copies. Old release source is obtained
from actual Git history. If unavailable, the relevant certification must fail.

## Load and acceptance

`tests/production_smoke.py` provisions an isolated HTTPS Docker deployment, creates
actual ingest/queue/search data and runs `scripts/load_test.py` with four mandatory
live phases: sustained ≥60 s, stress ≥120 s, soak ≥900 s, fault ≥60 s. Workload
completion, latency, error rate, async durability, concurrent search and fault
replay are verified. Measured objectives are those configured in the load runner.
The final gate runs this on the verified ZIP and validates each phase.
`--certify` enforces ≥4 write RPS, ≥1 read RPS, batch ≥20, concurrency ≥12,
p95 ≤5000 ms (≤8000 ms in fault mode), ≤1% errors and ≥85% of offered load.
Those limits cannot be relaxed by caller parameters or forged report thresholds. It does not
pretend a short fixture test is a production load certification.

## Operational release decision

1. Use `release.json` and `release-out/CajeerLogs-0.8.9.zip.sha256` to verify
   artifact identity. Keep the GitHub provenance attestation and reproducibility
   report with release evidence.
2. Require all mandatory jobs to pass and preserve `security-acceptance.json`,
   `database-certification.json`, and all other CI evidence together with the ZIP.
3. If any stage fails or the full target environment is unavailable, label the
   archive **candidate / uncertified**. Never publish it as certified.
4. Prior to deployment, rehearse upgrade/rollback, backup restore and incident
   recovery in the actual environment; see [operations readiness](Operations-Readiness-0.8.8.md).

`tests/security_acceptance.py` tests both a real archive run and malformed-report
rejection. The security report is tied to the exact release digest but is not a
digital signature; rely on protected CI and provenance for report authenticity.
