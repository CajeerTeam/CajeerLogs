# CajeerLogs 0.9.0 — Production Quality & Security Stable

This release consolidates the **runnable PHP runtime, production Compose images, Admin
Panel, OpenAPI and all four published SDK version numbers** to `0.9.0`. It does not
introduce a new PostgreSQL/ClickHouse migration or silently change API v1.

## Hard deployment identity check

Every production PHP process inherits `CAJEER_RELEASE_VERSION=0.9.0` from
`compose.production.yaml`. `Version::read()` compares it with the actual shipped
`release.json`, not with a caller-supplied HTTP header. A mismatch fails application
boot: a mis-tagged old runtime cannot silently serve alongside a new gateway or
consume the same queue. The production Docker build also verifies the version of
its actual copied `release.json`. Do not remove the expected-version setting to
make a failed deployment green. Restore the intended image and rerun checks.

## Real release identity verification

Build from the repository root:

```sh
python3 scripts/release.py build release-out/CajeerLogs-0.9.0.zip
python3 scripts/release.py verify release-out/CajeerLogs-0.9.0.zip
python3 scripts/stable_release.py release-out/CajeerLogs-0.9.0.zip
python3 tests/stable_release.py
php tests/release_runtime.php
```

The identity verifier extracts the independently verified **actual ZIP** and
executes its shipped PHP CLI. It compares the effective runtime, OpenAPI, Admin,
PHP/TypeScript/Python/Kotlin SDK versions, browser cache keys and production image
tags. It also enforces critical production Compose security settings. Negative tests
reject a stale browser import, mixed SDK, retagged old image, disabled security
setting and byte-level ZIP modification. This verifies **consistency** only, not
real database load, external integrity of the container bases or a third-party
security review.

## CI certification and promotion

A push or pull request still builds and tests a candidate. Only a push of the
`v0.9.0` tag can enter `public-release`. A successful tag run requires the
PHP 8.4/8.5 × PostgreSQL 17/18 matrix, real 0.8.9 → 0.9.0 upgrade/rollback,
Disaster Recovery, ClickHouse, browser E2E, security, SDK integration and
long-duration actual Docker load certification **for the same SHA-256 ZIP**.
The final report is independently attested via GitHub OIDC before the artifact
is labeled certified. Verify both the ZIP and the final report with
`gh attestation verify` in the source repository before deployment.

## Current external certification blockers

The attached 0.8.9 sources do not contain `composer.lock`, the TypeScript SDK
`package-lock.json` or real Gradle dependency locks; Python build requirements
were ranges in the supplied 0.8.9 source, and production `FROM` images have no immutable SHA-256 digests. In 0.9.0 the Python build backend versions are pinned; the remaining missing locks and OCI digests still block strict verification.
The existing `scripts/supply_chain.py verify --strict-dependencies` correctly
**rejects certification** when any are missing. These values must be resolved and
verified with the actual upstream registries; inventing a lockfile, digest or
success report would invalidate certification. The build environment used for
this patch has no registry access, Docker, or a PostgreSQL/ClickHouse service.

A release metadata `channel: stable` is a **target channel**, not evidence of
production certification. Do not publish a stable build or approve a deployment
until protected CI has fully passed and the authenticated acceptance evidence is
present. Follow [release acceptance](Final-Release-Acceptance-0.8.9.md),
[operations readiness](Operations-Readiness-0.8.8.md) and
[disaster recovery](Backup-Disaster-Recovery-Certification-0.8.7.md).
