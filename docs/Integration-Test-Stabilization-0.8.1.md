# CajeerLogs 0.8.1 — Integration Test Stabilization

## Release evidence

`integration-stability` runs actual PHP HTTP, PHP buffered SDK,
Nginx and aaPanel collectors, ClickHouse HTTP/migration transport, and signed S3 HTTP tests **twice**, each pass in a fresh extracted copy of the same
verified release ZIP. Tests use real loopback HTTP servers, filesystem spools and
signed requests; they do not claim to replace real PostgreSQL/ClickHouse/S3 E2E.

The runner strips database and cloud credentials, isolates HOME/TMPDIR, records the
SHA-256 of each test's log and the release artifact, and rejects a failed, timed-out,
missing or skipped stage. On timeout it terminates the process group, including test
HTTP children. Logs are not embedded in certification evidence.

```bash
python3 scripts/release.py build release-out/CajeerLogs-0.8.1.zip
python3 scripts/integration_stability.py release-out/CajeerLogs-0.8.1.zip \
  --passes 2 --output /tmp/integration-stability.json
python3 tests/integration_stability.py
```

The Python durable-delivery SDK and load/fault generator are verified separately in
the existing SDK/runtime CI matrices.

The `public-release` job requires the artifact-bound integration-stability report,
not merely a green job status. The real database certification runs the complete
HTTP pipeline twice with a new PostgreSQL schema per pass and invokes actual
historical upgrades. Both the pipeline and upgrade test use
`CAJEER_TEST_RELEASE_ARCHIVE` supplied by the certifier; requesting certification
without this archive is an error. The upgrade matrix adds **0.8.0 → 0.8.1**.

## What is not certified locally

Tests requiring PostgreSQL 17/18, ClickHouse 25.8, real S3, all SDK packaging and
full browser engines must still run on the matching CI infrastructure. The release
remains `public-preview` until every database matrix, SDK, browser, security and
performance gate passes against the final archive.
