# CajeerLogs 0.7.0 — SDK & Integrations release operations

The 0.7.0 source release upgrades 0.6.9 without adding PostgreSQL migrations.
It retains API v1. A **stable certification** is only granted after the same,
checksum-verified distribution passes PHP 8.4/8.5 × PostgreSQL 17/18,
admin browser E2E, installed SDK/importer E2E, TLS production smoke and the
sustained/stress/soak/fault load gates. A build, source tests or a manually
edited report cannot replace this evidence. `release.json` stays
`public-preview` until those tests have actually passed.

## Apply 0.6.9 → 0.7.0

1. Quiesce ingestion workers, webhook dispatcher, importers and export workers.
2. Take and verify a PostgreSQL backup and archive the application configuration.
3. Apply the changed-files archive **over the original 0.6.9 source tree**;
   preserve `.env`, storage and service secrets. Do not replace the current
   installation with the changed-files ZIP alone.
4. Run `php bin/cajeer migrate`, `php bin/cajeer doctor`,
   `php bin/cajeer db:verify`, `php bin/cajeer queue:verify` and
   `php bin/cajeer queue:project-verify` under the deployment account.
5. Restart queue, export, webhook and aaPanel/Nginx services. Confirm ingestion,
   logs explorer, export download, webhook delivery, and agent status. Keep the
   existing secret rotation and local spool permissions.
6. Revert application files using the pre-upgrade snapshot if gates fail. The
   database schema is unchanged from 0.6.9. Do not downgrade application code
   after writing newer data without checking documented backup compatibility.

## Release artifact verification

```sh
python3 scripts/release.py build release-out/CajeerLogs-0.7.0.zip
python3 scripts/release.py verify release-out/CajeerLogs-0.7.0.zip
python3 scripts/integrations.py release-out/CajeerLogs-0.7.0.zip \
  --output release-out/sdk-integration-certification.json
```

The last command **extracts the verified release archive** into a fresh temp
folder and runs real package builds and HTTP tests; it requires Composer,
Python with pip/venv, Node/npm, Java 17 and Gradle with dependency access.
The installed SDK and importer checks include:

- Composer dependencies (real Monolog 3), PHP buffered HTTP/HMAC and replay;
- npm TypeScript compilation and durable crash-recovery tests;
- Python wheel installation in a separate virtual environment and SQLite
  spool/logger HTTP tests;
- Gradle JVM build and real Kotlin queue/Java interoperability;
- cross-language synchronous and asynchronous HTTP receipt tests;
- Nginx rotate/checkpoint/replay and aaPanel multi-site isolation tests.

Its JSON report binds all mandatory steps to the **SHA-256 of the exact release
ZIP**. `scripts/certify.py release` rejects a missing, incomplete or mismatched
SDK report, along with any failed database, browser or HTTPS load report.
The GitHub workflow publishes the release's `*-certified-ci` artifact only if
all those executable checks succeed.

## Operational caveats

- Kotlin's bounded async queue resides in JVM memory. Termination with pending
  events loses those in-memory events; use application-level durable storage if
  stronger guarantees are required.
- Filesystem SDK spools must not be put on ephemeral storage when restart
  recovery is expected. Idempotency has a bounded server-side window.
- aaPanel discovery requires explicit enrollment and separate source tokens;
  local CLI/JSON management is not a remote Admin Panel agent controller.
- Webhook delivery is at least once. Consumers must deduplicate delivery IDs.
- Export files are private, short-lived. 0.7.0 pins the **verified open file
  descriptor** during downloads to prevent path replacement between SHA-256
  validation and streaming. Exports still need read-only permissions for users
  who should not be able to mutate them.
- Copytruncate can lose concurrent writes; rename/reopen rotation is preferred.

These conditions and the full production load thresholds are specified in
`docs/Integration-Compatibility-Certification.md` and
`docs/Load-Testing-Performance-Certification.md`.
