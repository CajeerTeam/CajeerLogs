# 0.2.3 — Event Validation

Both `/api/v1/ingest` and `/api/v1/ingest/async` use `StrictEventJson` and `IngestValidator`. Authorization and HMAC continue to operate on the original bytes; normalized data is never substituted into the signing input or request hash. No SQL migrations are added.

## JSON and event contract

- Exactly one event object, or an object containing only `events` (1–100 event objects).
- Duplicate keys are rejected at every nesting level, including equivalent escaped names (e.g. `"name"` and `"\u006eame"`). Malformed syntax, invalid UTF-8 and unpaired surrogate escapes return 400 `invalid_json`. Duplicate keys never reach redaction, admission or storage.
- 1 MiB HTTP body; 64 KiB per raw event object (including its internal whitespace and escapes) and per compact decoded event; 32 KiB compact context before and after masking. Existing normalized SQL/job limits remain 4 MiB/2 MiB; PostgreSQL JSONB job text limit remains 8 MiB.
- Maximum depth 24 measured from the event object (root depth 0), 2048 items per array/object, 8192 nodes per event, 200000 parser nodes per HTTP body, 256 UTF-8 bytes per object key. Parser also has a depth ceiling of 27 for the whole envelope. Numeric literals are limited to 1024 bytes.
- New events reject ASCII C0 controls except tab, LF and CR in string values; DEL and NUL are rejected. Keys reject all ASCII C0 controls and DEL. ANSI escape sequences must be removed by the producer. Ordinary Unicode text and escaped Unicode are supported; no Unicode normalization or case folding of arbitrary object keys is performed.
- Required `timestamp`, `source`, `level`, `message` must be strings (not numbers, booleans or null). RFC3339 time with explicit timezone and at most 6 fractional digits, calendar-valid, normalized to UTC; years 0001–9999 after normalization. Source slug is lowercase ASCII, up to 100 bytes. PSR-3 levels case-insensitive and normalized to lowercase. Message up to 16384 bytes. Optional service/host up to 255 bytes, trace_id/request_id up to 128 bytes. Context must be an object; tags a list of at most 32 nonblank strings of at most 128 bytes. Unknown event fields are rejected. Known secrets are masked before durable storage as before.

## Numeric precision

Within context only, integer literals outside signed 64-bit PHP range are converted to their exact decimal string, as before; decimal/exponent numbers with more than 15 significant decimal digits (excluding leading/trailing zeros in the mantissa) or nonzero numbers that underflow to zero are stored as strings using the original HTTP literal. Other finite decimals use PHP binary64; exponent overflow/nonfinite numbers are rejected. Required string fields do not receive this coercion. Numeric context values that become strings will be JSON strings when searched/exported; use strings explicitly for precise decimal values and identifiers. JavaScript Number and binary floating-point SDK types may already have lost precision before serialization; use strings for exact values. JSONL import uses Python Decimal for parsing fractional values in context and may canonicalize the spelling while preserving value. Existing stored values are not rewritten.

## Optional time admission windows

```dotenv
INGEST_MAX_EVENT_AGE_SECONDS=0
INGEST_MAX_EVENT_FUTURE_SECONDS=0
```

Each setting accepts integers 0–315576000 seconds. Zero disables that side of the window. Example: age `86400`, future `300`. Compare normalized UTC timestamps with the server time captured after event parsing. Bounds are inclusive. All events must pass for a new sync or async operation; no partial acceptance. The policy runs after receipt/job lookup and before project/source admission, so valid replays are not rejected merely because a time window changed or the timestamp aged out. Already accepted jobs are not reevaluated against time policy. Historical imports may need the window disabled or adjusted. Existing credentials, HMAC and quotas still apply to replay. Newly invalid JSON structure is checked before receipt lookup, so an old request that violates the new structural rules may no longer be replayable through HTTP; reconcile existing receipts/jobs before upgrading producers.

## Safe error responses

`400 invalid_json`, `422 invalid_payload`, `413 event_too_large` retain the existing error/message/request_id shape. Validation errors can include optional `details`:

```json
{"error":"invalid_payload","message":"Event 1: Invalid timestamp.","request_id":"example","details":{"event_index":1,"field":"timestamp","reason":"invalid_field"}}
```

`event_index` is zero-based; `field` is a known top-level field when identifiable. `reason` is a stable machine-readable category (e.g. `duplicate_key`, `malformed_json`, `structure_limit`, `invalid_key`, `invalid_character`, `invalid_number`, `invalid_field`, `unknown_fields`, `event_size`, `batch_size`, `timestamp_window`). Some envelope errors have no event index. No arbitrary key names or input values are included. All batch failures are atomic. HTTP clients should not retry unchanged permanent validation failures.

## Durable jobs and upgrade

New job payloads contain `validation_version: 2`. Unversioned jobs accepted by 0.2.2 and older are read using version 1's structural limits; existing size, scope, redaction, UTF-8/NUL and durable lease checks still apply. The worker passes the version to the SQL encoder, so legacy events are not rejected solely by the new v2 limits. Unknown versions are rejected. No migration is required. Stop all old PHP-FPM and queue workers before deploying: 0.2.2 cannot decode v2 envelopes. A downgrade requires draining new jobs (or restoring a consistent old backup). Pending collector requests keep their exact original serialized bytes and key; new JSONL lines use strict duplicate detection and local checks before checkpoint advancement.

All four SDKs now validate serializable event shape, required fields, timestamps, control characters, structure and size before network delivery. The server remains authoritative for redaction, number lexemes, time policies, and admission. PHP's empty array context convenience still becomes an empty object; other languages use an object/map. SDK preflight does not replace server validation and cannot recover precision already lost in client-side floating-point values.

## Upgrade and checks

Back up database and configuration, stop old processes, overlay changed files onto 0.2.2, restart workers with the new runtime. Run `php bin/cajeer version`, `php bin/cajeer migrate:verify`, `php bin/cajeer db:verify`, `php bin/cajeer openapi:lint`.

```bash
php tests/event_validation.php
php tests/ingest.php
php tests/batch_ingestion.php
python3 tests/sdk_wire.py
python3 tests/archive_import.py
CAJEER_TEST_DATABASE=1 php tests/ingest_database.php # isolated PostgreSQL only
```

The real PostgreSQL suite tests rejection without writes, sync/async replay after policy changes, previously accepted job completion and fresh/future timestamps. The release gate retains PostgreSQL 17/18 × PHP 8.4/8.5 and actual upgrades from 0.1.0 and 0.2.2. Local tests and SDK wire fixtures are not a substitute for PostgreSQL certification.
