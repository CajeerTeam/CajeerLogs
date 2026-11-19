# CajeerLogs 1.0 — versioning and support policy

CajeerLogs API v1 follows semantic versioning. This policy applies to **certified GA releases published through the tag-triggered `publish-ga` GitHub Actions job**. A source archive marked 1.0.0 or locally passing unit tests is not sufficient evidence of a certified release.

## Compatibility

- **API:** `/api/v1` retains existing routes, auth semantics, error codes and required response fields for the 1.x line. Additive fields are allowed. Incompatible API changes require a new major API version and a documented migration.
- **Runtime:** PHP **8.4 and 8.5**, PostgreSQL **17 and 18** are the tested matrix. PostgreSQL remains the system of record. ClickHouse 25.8 is optional and requires separate integration verification. No MySQL support is implied.
- **SDK:** PHP, Python, TypeScript and Kotlin client wire compatibility is checked in release CI. SDK package installation requires resolved and audited dependency locks.
- **Schema:** 33 historical SQL migrations are immutable. A patch must not silently rewrite applied migrations. For upgrades, take and verify a backup, stop writers and workers, run the documented upgrade, then check readiness. Rollback means restoring **both** an earlier application binary and its consistent original database snapshot; down-migrations are not a rollback guarantee.
- **Browser:** Admin Panel assets belong to the exact release version. Mixed caches, schema drift and inconsistent runtime binaries block release identity acceptance.

## Maintenance commitment

The **latest GA patch of 1.0.x** receives critical/high security fixes and compatibility corrections until **six months after the public GA of 1.1.0**, with a minimum of **12 months following 1.0.0 GA publication**. The first condition to end support cannot occur before the second. Previous 1.0.x patches are superseded and users should upgrade to the newest patch; unsupported pre-1.0 previews have no maintenance guarantee. Any early end of support must be announced with a migration path and at least 90 days' notice. This is a maintenance policy, not a contractual response-time SLA.

## Vulnerability handling

Report vulnerabilities privately using the repository's GitHub Security Advisory reporting channel; **do not publish exploit details in issues**. Maintainers triage critical vulnerabilities promptly, coordinate fixes and advisory publication, and publish the affected range, mitigations and verified fixed version. No guaranteed hourly response SLA is advertised. Secrets, logs and personal data must not be attached to public reports.

## Release evidence

GA requires SHA-256-bound results of the full PHP/PostgreSQL matrix, ClickHouse, browser E2E, SDK integrations, TLS/security checks, upgrade/rollback, RPO/RTO, real load/SLO, dependency audits, reproducible builds and signed GitHub Actions OIDC attestations for **both the ZIP and final certification report**. `scripts/ga_release.py` verifies the cryptographic attestations against the exact repository, `refs/tags/v1.0.0`, commit and trusted workflow before publishing assets. Publication fails closed for missing results, expired/invalid signing certificates, wrong version or unsuccessful checks. Re-publishing or replacing assets of an existing GA release is forbidden.

See [GA installation and verification](Production-Stable-GA-1.0.0.md) and [Production checklist](Production-checklist.md).
