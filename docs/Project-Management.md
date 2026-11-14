# Project Management — 0.1.6

## Upgrade

Stop HTTP traffic and background workers, back up PostgreSQL with the 0.1.5 CLI and save `.env`, TOTP keys and webhook secrets. Overlay the changed files and run `php bin/cajeer migrate:plan`, `php bin/cajeer migrate`, `php bin/cajeer migrate:verify`, `php bin/cajeer db:doctor`. Migration 0009 adds project lifecycle/policies and a nullable membership role; 0001–0008 remain unchanged. Existing projects are active, revision 1, no event quota, 1 MiB / 100 event limits, with unchanged retention. Existing memberships inherit the global role. With separate roles grant the migrator the required DDL privileges and preserve runtime access. Restart PHP-FPM and workers after verification. Backup restore accepts compatible 0.1.5 and earlier snapshots (0.0.9–0.1.5), validates history, applies 0009 in the separate target and invalidates credentials as before. Do not downgrade binaries against schema 0009.

## Management

Admin Panel → Projects and sources: select a project, search by name or slug, filter active/archived, edit name/description/metadata, retention and ingest limits, archive or restore, inspect real counts. Project UUID and slug are immutable. Metadata is a JSON object (8 KiB encoded maximum); do not store secrets there. It is visible to users with project read access and project-wide read tokens. Archive is reversible and does not delete data, membership, sources or tokens. Existing retention continues, including archived projects; set it to null before archiving if history must be kept indefinitely. New ingestion (sync/async) is blocked with 409; previously accepted jobs may drain or be manually retried, and committed idempotent requests may be replayed. Read access, export, and management remain available. No hard delete or automatic token revocation.

## HTTP API v1

All mutations require administrator session + CSRF. `GET /projects` supports `q`, `status=all|active|archived`, `limit`, `cursor`; results are UUID ordered. `GET /projects/{id}` returns metadata, policies, status, revision as a decimal string, and effective_role for sessions. `PATCH /projects/{id}` requires current revision, plus one or more mutable fields. Example:

```json
{"revision":"1","name":"Production","description":"Backend logs","metadata":{"environment":"prod"},"retention_days":30,"max_body_bytes":262144,"max_batch_events":50,"events_per_minute":5000}
```

Use `POST /projects/{id}/archive` or `/restore` with `{"revision":"2"}`. Each successful mutation returns updated project including the next revision. A stale revision gets 409 `project_revision_conflict`; reload and reconcile changes. CLI `retention:set` also increments revision and serializes with retention. `GET /projects/{id}/stats` (session with project access) returns exact sources/members/active_tokens/events/events_24h/last_received_at/queued_jobs/dead_jobs counts. Event counts cover retained events, not lifetime totals. Large projects may make exact counts expensive; database statement timeout applies.

`PUT /projects/{id}/members/{user}` accepts optional JSON `{"role":"viewer"}`, `{"role":"operator"}`, or `{"role":null}`. Empty body keeps old behavior (add if absent). Non-null project role can only restrict the global role; a global viewer cannot become an operator through project membership. Global administrator retains administrative access regardless of membership. Membership changes are audited. Existing project members endpoint and DELETE remain compatible.

## Policies

- `retention_days`: null (off) or 1..3650, based on received_at.
- `max_body_bytes`: 1024..1048576. Smaller global limits still apply.
- `max_batch_events`: 1..100.
- `events_per_minute`: null (unlimited) or 1..1000000. Atomic database-clock fixed UTC minute window shared across all tokens and sync/async admission. Only newly committed admissions count; replay, rejected or rolled-back requests do not charge. 429 with Retry-After: 60 if the batch would exceed quota. Splitting a batch is allowed; unused quota does not carry over. Existing IP/token limits remain. A fixed window can allow bursts across minute boundaries. It is not a storage quota or per-source limit.

Admission takes a project row share lock until commit, so archive/policy changes serialize with new ingress. Worker writes for already accepted jobs do not recheck admission limits. Metadata and project lifecycle changes use transaction-owned revision checks and audit; PostgreSQL transaction retry rules remain unchanged. CLI operations and third-party SQL writers must respect the same database constraints and locks.

## Tests

`php tests/projects.php` validates request fields, revisions, decoding and auth route registration. `CAJEER_TEST_DATABASE=1 php tests/projects_database.php` uses a disposable PostgreSQL 17/18 database, creates an isolated schema, upgrades from migrations 0001–0008, exercises the real application HTTP dispatch, role caps, quota, archive/replay/drain, stats and audit. CI also runs upgrade, browser fixture, regression and deployment tests. Local test results do not certify PostgreSQL deployment unless the real database suite is executed.
