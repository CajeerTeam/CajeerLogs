# CajeerLogs 0.9.1 — Release Baseline & Supply Chain

## Production deployment baseline

The production Dockerfile pins the published multi-platform PHP 8.4 FPM and
Nginx image manifests by SHA-256. The production Compose deployment additionally
pins PostgreSQL 17 and ClickHouse 25.8 by image digest. Container tags are only
human-readable identifiers; all pulls resolve the fixed digest. Images must be
pulled and verified against the OCI registry in a networked CI runner before
shipping or deploying; digest *strings* do not prove image availability.

Runtime does not require installing Composer or Node packages: it ships the PHP
core and static Admin Panel. Development, build and SDK distributions **do** use
external packages. Their transitive dependency integrity is mandatory for 0.9.1.

## Generate real lockfiles

A networked Linux runner with PHP 8.4 and required extensions, Composer 2,
Node.js 22, npm 10, JDK 17, Gradle 8.10.2 and Docker Buildx is required. In
GitHub Actions, dispatch `Refresh locked dependencies (maintainer)` and review
its verified lockfile artifact. Alternately run:

```bash
python3 scripts/dependency_locks.py refresh
```

This performs actual Composer resolution, `npm install --package-lock-only`,
Gradle `--write-locks --write-verification-metadata sha256 build`, installs the
locked packages, executes TypeScript and Kotlin builds, audits Composer/npm,
and fetches raw OCI manifests whose SHA-256 must equal the pinned digest.
No synthetic transitive package metadata is accepted. Commit **all four** generated
files together:

- `composer.lock`
- `packages/ts-sdk/package-lock.json`
- `clients/java-kotlin/gradle.lockfile`
- `clients/java-kotlin/gradle/verification-metadata.xml`

If any resolution, audit, test or digest check fails, do not commit the result
as certified. A dependency bump must regenerate all relevant lock and verification
artifacts, then run the full matrix.

## Verification and release

```bash
python3 scripts/dependency_locks.py verify --install
python3 tests/dependency_locks.py
python3 scripts/release.py build release-out/CajeerLogs-0.9.1.zip
python3 scripts/supply_chain.py reproduce release-out/CajeerLogs-0.9.1.zip --source . --report release-out/reproducible-build.json
python3 scripts/supply_chain.py generate release-out/CajeerLogs-0.9.1.zip --sbom release-out/0.9.1.sbom.json --provenance release-out/0.9.1.provenance.json --report release-out/0.9.1.dependencies.json
python3 scripts/supply_chain.py verify release-out/CajeerLogs-0.9.1.zip --sbom release-out/0.9.1.sbom.json --provenance release-out/0.9.1.provenance.json --report release-out/0.9.1.dependencies.json --strict-dependencies
```

CI `dependency-integrity` runs before release construction. The full CI release
gate also verifies authenticated GitHub OIDC attestations for the *same* ZIP and
certification report. Installing exact locked third-party packages is separate
from compiling the local PHP/JS release and cannot be substituted by source-only
unit tests.

## Status for the source-only archive

When the package registries cannot be reached, validated Composer/npm/Gradle lock
files cannot be generated or tested. The release must remain uncertified and
`--strict-dependencies` must fail closed. No exception for unavailable registries
or substitute hash values is permitted.
