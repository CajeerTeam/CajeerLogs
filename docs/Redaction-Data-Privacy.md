# Redaction & Data Privacy — 0.2.6

CajeerLogs masks known credential formats and configured values before new logs or async payloads become durable. This is best-effort secret detection, not a guarantee that arbitrary text is free of personal data or secrets. Producers should avoid sending sensitive values in the first place.

## Policy management

Global administrators with a browser session manage the policy in System, Projects, or Source details. All mutations and previews require CSRF. The REST paths (under `/api/v1`) are:

- `/system/redaction`
- `/projects/{project}/redaction`
- `/projects/{project}/sources/{source}/redaction`

GET returns `{"revision":0,"rules":[]}` for an unset policy. PUT accepts `revision` and `rules`; a stale revision returns 409. POST to the same path plus `/preview` accepts `rules` and one synthetic `event` in ingest format. It returns the masked normalized event without storing it, publishing rules or charging ingest quotas. Project/source previews require a registered source and include inherited policies. Global preview tests the draft system policy alone. Never paste real credentials or personal data into the preview. API responses use no-store.

Example policy:

```json
{
  "revision": 0,
  "rules": [
    {"type":"path","value":"/context/customer_id"},
    {"type":"path","value":"/context/contacts/*/phone"},
    {"type":"key","value":"email"},
    {"type":"regex","value":"customer-[0-9]{6}"}
  ]
}
```

Policies are additive: system + project + source; no child rule can disable a parent or built-in masking. Clearing a policy requires a new revision with an empty rules array. Rules are stored in existing `settings` keys `redaction.system`, `redaction.PROJECT_UUID`, `redaction.PROJECT_UUID.SOURCE_UUID`. Policy changes are atomic with an audit record containing revision and rule count, not rules or sample data. Historical rule snapshots are not retained. Do not put actual secrets in rules; rules are administrative configuration and included in backups.

- **path**: JSON Pointer-style paths (`~1` for slash, `~0` for tilde), `*` matches one segment, including array indices. Allowed roots: message, context, service, host, trace_id, request_id, tags. `context` and `tags` require a child segment, preserving their container shape. Matches replace the entire value/subtree with `[redacted]`. `*` is reserved as a wildcard, not a literal key.
- **key**: exact ASCII-case-insensitive nested context key name. Values, including numbers/objects, are replaced. Names themselves remain visible.
- **regex**: PCRE Unicode pattern body without delimiters. Any match replaces the entire string value, not just the matching substring. Applies to normalized, baseline-masked string values, not names, routing metadata or numeric values. No inline groups/options, control verbs or `~` delimiter. Use key/path rules to mask numeric values or entire nested objects.

Maximum 16 rules per scope, 256 bytes per rule value, 48 effective rules. Patterns are compiled with JIT disabled and bounded match/depth limits (10000/100); the application also bounds traversal/work and checks monotonic processing budgets (200 ms per policy event and 1 s per batch between events). These are cooperative bounds, not a hard real-time deadline. Regex failure or policy corruption fails closed; no new log/job is committed. A policy can make an event exceed existing validation limits, in which case it is rejected. Built-in masking remains active, including recognized password/token/cookie fields, Bearer/Basic, Cajeer credentials, common JWT, PEM private keys and URL/DSN credentials. The rules cannot change source slug, project, timestamp or level.

## Durability and consistency

New synchronous writes and direct repository writes mask before INSERT; new async payloads mask before enqueue. Worker reapplies the latest rules before storage. A schema-local shared PostgreSQL transaction advisory lock fences writes against policy publication (exclusive lock); policy-sensitive writes require READ COMMITTED so old snapshots cannot bypass an update. Existing idempotency receipts are not changed; replay returns the same IDs, not another write. HMAC and request hash are calculated from the original body; no raw body is retained for these purposes. Webhook outbox contains identifiers only. Policy errors use safe codes; metric `cajeerlogs_ingest_rejections_total{reason="redaction_failed"}` tracks masking failures. A worker retries transient failure with existing backoff and eventually dead-letters if unresolved.

## Historical records

Stop ingest and workers, freeze policy changes, create a backup, and use a trusted CLI operator. Run a page dry-run:

```bash
php bin/cajeer logs:redact-history PROJECT_UUID --dry-run 25
```

Apply after reviewing the count:

```bash
php bin/cajeer logs:redact-history PROJECT_UUID --apply --confirm 25
```

Use `next_cursor` as the final argument for subsequent pages, separately for dry-run and apply. Limit is 1..50, default 25. Each page has its own transaction; no cross-page snapshot. Output is counts and cursor only. Apply audits each page. Existing UUID, project/source, event/receive timestamps, token and receipt/job IDs are retained; the generated FTS index is updated by PostgreSQL. Context is patched at changed paths to avoid rewriting unrelated exact decimal values. Malformed or oversized historical events/patch counts fail the page rather than silently skip; investigate offline and retry. Unassigned legacy events are excluded; adoption now applies the target policies. Re-running with unchanged policies is safe. Use a disposable database for tests.

Policy changes do not retroactively erase stored data automatically. Old queued/dead payloads may still contain values not covered by the policies at acceptance; workers mask before storage, completed jobs clear payload, dead jobs must be handled explicitly via existing queue commands. History processing covers log rows, not old archives, backups, WAL, replicas, exports, snapshots or external consumers. PostgreSQL MVCC and storage backups can retain old versions. Securely expire/delete those copies according to the retention and incident response policy; this release does not claim physical erasure or GDPR compliance. Search filters/exports use the stored masked data; changing trace/request/service values can affect correlation and queries. Exact historical rules are not reconstructible from audit alone.

## Upgrade and tests

Overlay 0.2.6 onto 0.2.5 after backup. No new migration; all 0001–0013 unchanged. Restart all runtime and worker processes; do not mix older writers that do not apply policies. Run `php bin/cajeer migrate:verify`, `php bin/cajeer db:verify`, `php bin/cajeer openapi:lint`. `php tests/redaction.php` exercises the real policy engine and controlled SQL fixture; `CAJEER_TEST_DATABASE=1 php tests/redaction_database.php` uses a disposable real PostgreSQL database, verifies admin operations, scope isolation, sync/async, latest worker rules, history and rollback. Included in `scripts/certify.py` and the release workflow; the release remains Public Preview until the PostgreSQL matrix passes.
