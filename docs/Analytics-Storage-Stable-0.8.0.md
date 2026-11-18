# CajeerLogs 0.8.0 — Analytics & Storage release acceptance

This release integrates the existing PHP/Monolog, Python, TypeScript and Kotlin clients,
Nginx/aaPanel importers, webhooks, JSON/CSV exports, PostgreSQL analytics rollups,
ClickHouse replication/search analytics and PostgreSQL warm / S3 cold storage.

## Safe update from 0.7.9

1. Take and verify a database backup; retain configuration and object-store credentials outside the web root.
2. Stop workers, scheduled replication, export jobs and archive operations before installing the changed files.
3. Verify `php bin/cajeer version` reports `0.8.0`, then execute `php bin/cajeer migrate:verify`.
   There are **no new PostgreSQL or ClickHouse migrations** compared with 0.7.9.
4. Run `php bin/cajeer doctor`, inspect `analytics:replication status`, and reconcile per-project
   rollups using `analytics:rollups verify PROJECT_UUID START_UTC END_UTC`.
5. Before evicting any hot data, ensure the immutable warm snapshot matches the hot record;
   use small batches. The `restore-hot` command processes only *missing* hot rows,
   allowing repeated small-batch recovery without starving behind previously restored rows.
6. Ensure object-store TLS, bucket lifecycle, retention and permissions have been tested against
   the chosen **real** S3-compatible provider. The CI emulator alone is not that proof.
7. Restart workers and monitor replication lag, failed deliveries, archive disk consumption and errors.

## Release certification

`python3 scripts/release.py build release-out/CajeerLogs-0.8.0.zip` creates the candidate artifact.
The exact artifact must then pass the fail-closed `scripts/certify.py` gate, which requires:

- PHP/PostgreSQL matrix 8.4/8.5 × 17/18, including an actual 0.7.9 → 0.8.0 database upgrade;
- packaged SDK/importer integration evidence;
- real browser/database Admin Panel runs;
- signed HTTP S3 fault-injection, PostgreSQL/ClickHouse replay, rollup reconciliation and
  warm/cold data migration evidence;
- sustained, stress, soak and fault-injection load results against the production-style Docker stack.

Evidence is bound to the SHA-256 of the ZIP. Missing, mismatched or failed reports must
block the release. A CI run that has not been executed is **not** evidence of certification.
The repository's release metadata remains `public-preview` pending complete acceptance;
changing the version to `0.8.0` alone is not equivalent to production approval.

## Recovery limitations

Hot eviction intentionally removes events from hot search and triggers analytics tombstones.
S3 write/read verification does not prevent an orphan object after a network failure or SQL
transaction rollback; reconcile the bucket inventory and PostgreSQL `archived_log_objects` manifest
before irreversible deletion. For zero-downtime migrations, a rehearsed operator procedure
and measured production-sized workloads are still required.
