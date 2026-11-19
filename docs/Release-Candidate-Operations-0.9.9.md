# CajeerLogs 0.9.9 — Release Candidate & Operations Readiness

0.9.9 is a **release candidate**, not an automatically certified production release. Release publication requires passing CI on the *same immutable ZIP*, the pinned dependency graph and the evidence from the disposable production Docker stack. An archive of changed files is an **upgrade patch** and cannot be used as the complete distribution in `--certify`.

## Roles and prerequisites

- **Release owner:** verifies artifact SHA-256, SBOM, provenance and required trusted CI jobs; records the go/no-go decision.
- **Database operator:** verifies PostgreSQL 17/18 migration, integrity, backup restore and 0.9.8 → 0.9.9 rollback. A rollback restores the old executable **with its pre-upgrade database copy**; it never runs down-migrations on live data.
- **Incident commander:** owns outage communications and authorizes restore/cutover. The CLI only diagnoses and rehearses; it does **not** automatically switch traffic.
- **Security owner:** performs access review, `security:audit --tls`, secrets rotation and TLS expiry checks. No credential values belong in issue trackers or CI logs.

Use a fresh disposable project/database and an independently generated token for performance certification. No destructive fault-injection is permitted on production. Set `APP_DEBUG=false`, HTTPS `APP_URL`, correct PostgreSQL roles, authenticated Prometheus, and enabled admin MFA in the target deployment. Limit evidence access to operators.

## Candidate integrity and smoke

From the full release source tree:

```sh
python3 scripts/check_runbooks.py --root .
php bin/cajeer version
php bin/cajeer install:check
php bin/cajeer migrate:verify
php bin/cajeer health:ready
php bin/cajeer operations:check
php bin/cajeer operations:triage
php bin/cajeer security:audit
php bin/cajeer security:audit --tls
```

A nonzero exit from any safety-critical command is a **NO-GO**. `operations:triage` returns 0 (ok), 1 (degraded) or 2 (critical). Resolve degraded/critical signals using [on-call runbooks](runbooks/README.md). A check that was skipped is **not evidence of its success**. Save `operations:incident` snapshots only to the protected local incident directory and enforce file mode `0600`, directory mode `0700`.

On the *full candidate archive*, with its sidecars:

```sh
python3 scripts/release.py verify release-out/CajeerLogs-0.9.9.zip
python3 scripts/stable_release.py release-out/CajeerLogs-0.9.9.zip
python3 scripts/supply_chain.py verify release-out/CajeerLogs-0.9.9.zip \
  --sbom release-out/CajeerLogs-0.9.9.sbom.cdx.json \
  --provenance release-out/CajeerLogs-0.9.9.provenance.json \
  --report release-out/supply-chain-report.json --strict-dependencies
```

Reject missing Composer/npm/Gradle locks, unsigned/unknown digest containers, stale artifacts, missing attestations and any failed verification. Do not use `--strict-dependencies` results from another ZIP or another commit.

## Required evidence and CI matrix

| Mandatory validation | Actual subject | Pass condition |
|---|---|---|
| Dependency integrity | Installed Composer/npm/Gradle graphs, OCI manifests | Locked and registry-verified, strict gate green |
| Reproducibility | Clean independent ZIP builds | Identical SHA-256 |
| PHP/PostgreSQL | 8.4/17, 8.4/18, 8.5/17, 8.5/18 | 4 distinct passing reports on identical artifact |
| Database upgrade/rollback | 0.9.8 → 0.9.9 (and defined supported historical sources) | Pre-upgrade backup restored and old binary/API exercised |
| OpenAPI and SDK | Actual PHP router, contract, PHP/Python/TypeScript/Kotlin SDKs | No incompatible v1 changes; real HTTP tests pass |
| Admin Panel | Chromium/Firefox/WebKit against PostgreSQL | Real browser E2E passes |
| Analytics | ClickHouse 25.8, PostgreSQL replication, S3 fault tests | Integrity and recovery pass |
| Security | Real test suites, TLS/RBAC/TOTP, monitoring | All critical assertions pass |
| Docker production | HTTPS, install, 2FA, workers, backups, retention, stop/restart | Functional and operator commands verified |
| Load | sustained/stress/soak/fault | 60/120/900/60 seconds minimum, all SLO gates pass |

`scripts/certify.py release` rejects incomplete reports and requires a successful disposable Docker test. The long-duration load test verifies IDs **and persisted event content** (project, source, level, message) after real async processing and HTTP retries. The final operational evidence also requires the actual security audit, incident snapshot permissions, queue outage triage, Prometheus alerts, PostgreSQL outage and successful post-recovery ingest. Evidence is tied to the candidate SHA-256 and may not be copied between builds.

The minimum certification workload uses 100% async batches of >=20 events, rate >=4 RPS, concurrent search >=1 RPS, >=12 concurrency, p95 <=5000 ms, fault p95 <=8000 ms, error ratio <=1%, achieved rate >=85% of target. The supplied CI additionally measures backlog drain and no duplicate event IDs; user-adjustable weaker limits cannot produce a certified report. See [performance procedures](Load-Testing-Performance-Certification.md).

## Operations after deployment

**Each shift:** check `/ready`, protected `/metrics`, queue workers, scheduler, storage headroom, TLS expiry, `operations:triage` and security alerts; record UTC timestamps. Review the [on-call index](runbooks/README.md) for trigger-specific action.

**Before upgrade:** verify current DB schema and audit chain; create and verify a full `pg_dump` backup plus offsite copy; record release ZIP SHA-256; rehearse restore into an empty target database; announce a maintenance window. Stop writes and workers using the normal orchestrator; apply the versioned code and perform the safeguarded `upgrade BACKUP_ID --confirm-stopped`. Never run a production upgrade against a live shared test database. Validate API, Admin Panel, worker, retention, webhook, search and metrics before admitting traffic.

**During rollback:** keep services stopped, retrieve the previous release's immutable code/SDK/container set and **matching pre-upgrade DB backup**, restore only to an empty quarantined target, validate account access, audit chain, event counts and queue processing, then switch traffic by an approved external deployment action. Record elapsed duration and actual data boundary. Never assume that reversing SQL migrations restores data.

**After outage:** compare durable receipts with searchable events, run `queue:verify`, `audit:verify`, backup verification and `operations:triage`; preserve a private incident snapshot. Run an offsite-only restore rehearsal. Record RPO (last consistent point of recovery vs last acknowledged write) and RTO (incident start to end-user-ready), not merely the duration of `pg_restore`. Investigate drift or missing events before resuming production.

## Acceptance decision

- **GO:** every required CI job succeeded on the same ZIP with trustworthy audit/provenance; security and DR owners signed off the target-environment limitations.
- **NO-GO:** missing lockfiles/digests, unverified OCI manifest, disabled gate, partial matrix, unrun browser/DR/load tests, unknown RPO/RTO, failed 2FA/TLS, or wrong artifact hash.

These instructions do not claim the 0.9.9 candidate has achieved a production SLA. Publish a certified stable 1.0 only after the evidence exists.
