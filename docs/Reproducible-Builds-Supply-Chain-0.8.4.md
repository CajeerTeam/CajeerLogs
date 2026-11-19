# CajeerLogs 0.8.4 — Reproducible Builds & Supply Chain

The release ZIP contains source code and bundled browser assets, not downloaded `vendor`,
`node_modules`, JVM or Docker layers. `scripts/supply_chain.py` runs **real isolated builds**
from the same allowed source files, checking that both output digests equal the candidate ZIP.
It never reuses build output or checksums as replacements for a rebuild.

```bash
python3 scripts/release.py build release-out/CajeerLogs-0.8.4.zip
python3 scripts/supply_chain.py reproduce release-out/CajeerLogs-0.8.4.zip \
  --source . --report release-out/reproducible-build.json
python3 scripts/supply_chain.py generate release-out/CajeerLogs-0.8.4.zip \
  --sbom release-out/CajeerLogs-0.8.4.sbom.cdx.json \
  --provenance release-out/CajeerLogs-0.8.4.provenance.json \
  --report release-out/supply-chain-report.json
python3 scripts/supply_chain.py verify release-out/CajeerLogs-0.8.4.zip \
  --sbom release-out/CajeerLogs-0.8.4.sbom.cdx.json \
  --provenance release-out/CajeerLogs-0.8.4.provenance.json \
  --report release-out/supply-chain-report.json --strict-dependencies
```

The CycloneDX 1.6 SBOM records each **shipped** source file's SHA-256 and its size.
Third-party manifests are inventoried as *declared* dependencies without pretending
that a version range is an installed package. The JSON in-toto statement is **unsigned
metadata**, not a verified identity. GitHub CI must also generate its authenticated
OIDC-backed build-provenance attestation using the release ZIP digest. Verify with
`gh attestation verify release-out/CajeerLogs-0.8.4.zip --repo OWNER/REPO`.

## Current release-blocking dependency gaps

The provided 0.8.3 source contains no `composer.lock`, no SDK `package-lock.json`,
no Gradle dependency lock/verification metadata; the Python build backend is not
exact pinned and production Docker `FROM` tags lack immutable digests. These are
**not** filled with invented package hashes. `--strict-dependencies` fails closed until
real lockfiles are generated with the appropriate package managers, committed and
retested. Upgrade/install scripts should use `composer install` from lock and `npm ci`,
not re-resolve dependency ranges. Pin Docker base image digests before claiming a
fully reproducible deployed runtime. Independent builds only certify the shipped
source ZIP, not external runtimes or third-party binaries.

## CI release gate

CI validates candidate ZIP integrity, two independent clean builds, SBOM and
provenance sidecars, OIDC attestation, resolved dependency locks and live E2E evidence
against **the same artifact digest**. Missing or altered reports and unresolved lock
policy fail the certification; neither a green source test nor an unsigned JSON
statement is a production release certification.
