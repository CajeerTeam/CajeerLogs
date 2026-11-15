# Idempotency & Delivery Guarantees — 0.2.7

## Upgrade and runtime

Stop HTTP ingestion and all workers, take a backup on 0.2.6, overlay changed files, run `php bin/cajeer migrate`, `migrate:verify`, `db:verify` and restart the runtime. Migration 0014 adds a rotation-family pointer to `api_tokens`, an optional delivery digest to receipts/jobs and a count/retention snapshot to new jobs. It backfills valid historical chains, rejects inconsistent or cyclic rotation links atomically and preserves all original hashes, timestamps and job results. Migrations 0001–0013 are unchanged. Old runtimes must not write concurrently with 0.2.7. Backup 0.2.6 is supported by the normal restore migration path.

```dotenv
INGEST_IDEMPOTENCY_SECONDS=604800
```

Allowed 86400–2592000 (1–30 days), default 7 days. Both sync receipts and terminal async jobs use the value at acceptance. For sync the clock starts at the receipt transaction; for async at completion/cancellation. Pending, running and dead jobs do not expire; completed/cancelled metadata is purged by scheduler only after its stored retention. A dead job retains its key during manual retry; discard retains cancelled metadata for the original duration. Cleanup never deletes log events. Expired sync receipts are ignored even if the cleanup job has not run. Async keys remain reserved until the terminal job is actually purged. This is a minimum retention window, not unlimited exactly-once. Log retention may delete event rows before receipts expire; replay still returns the original IDs and never recreates retained/deleted data.

## Wire semantics

- Use a unique 1–128 character `Idempotency-Key` per logical delivery; keep the exact serialized UTF-8 JSON body, key and route. Sync without a key is intentionally not deduplicated. Async requires one.
- 201 = sync commit, 202 = durable async acceptance (not stored events), 200 = replay. Sync replay returns original `event_ids`, `stored:0`, `replayed:true`; async replay returns original job ID, current job status and original event count, even after payload removal.
- Body byte changes (even whitespace) or mode changes with the same live key return 409. Auth and HMAC policy, timestamp/nonce, HTTP envelope and request-rate limits still apply on every request. A fresh HMAC timestamp/nonce is required on every retry. A replay does not apply new event validation/time-window or admission policies and does not charge event quotas/counters or queue capacity a second time.
- New acceptance and receipt/job state are committed together with quota changes. TransactionManager retries only known aborted transactions; it does not automatically repeat ambiguous COMMIT. On network/503/unknown outcome, retry the same key/body/route from a fresh connection. A committed operation returns its original result; an aborted operation can be accepted once. Do not issue a new key solely because an HTTP response was lost.
- A 200 async replay of a dead/cancelled job is not successful storage. Poll status, resolve dead jobs with existing operator retry/discard commands. Never automatically create a replacement job after a dead result without investigating.

## Rotation and security

New keyed deliveries use the root UUID of the actual Bearer credential rotation chain. Rotation propagates that root atomically; independent issues, even for the same source/service, have separate namespaces. Family membership retains the same project, source and service account. Any active `logs:write` credential in that family (including a still-valid predecessor during overlap) may replay new deliveries and poll their jobs. Current credential auth, expiry, HMAC and scopes remain mandatory. No `logs:read` permission is conferred. Revoked/expired tokens cannot call the API. A separate signing-key rotation does not change the delivery namespace.

The original per-token request HMAC remains for compatibility. New delivery digest is HMAC-SHA256 of domain-separated raw body using a family-derived HKDF key from the effective security master (`SECURITY_TOTP_KEY`, or `SECURITY_HMAC_SECRET` fallback) and root credential hash. Existing unconfigured development setups use the root credential hash; a configured master protects the digest against database-only offline guessing. Keep the effective master consistent across all replicas and deployments; a change causes existing new-key digest comparison to fail closed (409), not duplicate the operation. The existing signing/TOTP master rotation procedure is still required. Raw body, key text and bearer secret are not stored in receipt metadata. The queue retains only its masked normalized payload.

Legacy receipts created through 0.2.6 keep their original expiry (normally 24 hours) and per-token HMAC. Legacy jobs keep original owner and seven-day terminal retention. The server can authenticate an identical legacy body using the original token. With a different token in the same family, it cannot verify that old digest, so returns 409 rather than accepting duplicate work. Legacy job polling requires original owner. Historic collisions across previously independent rotated token UUIDs are not automatically reconciled; ambiguous live keys fail closed. Use existing original credential during overlap or reconcile via an administrator before migration/expiry. This applies also to trusted legacy repository callers that do not use the new public ingestion delivery metadata.

## Client recovery

Persist body+key+route in a secure application outbox before attempting delivery. PHP `sendBody($body,$key)`, TypeScript `sendBody(body,key)`, Python `send_body(body,key)` and Kotlin `ingest(json,key)` allow reusing serialized JSON. All SDKs preserve body and key within retries, produce fresh HMAC nonces, use bounded exponential backoff/jitter and validate the full response, including unique canonical UUIDs. On permanent 4xx, including 409, do not retry automatically. A new client with a rotated credential can resume 0.2.7 deliveries with the same exact JSON and key; it must have the current signing key if v2 is required. Existing file collector persists exact pending bytes and keeps its conservative 23-hour manual recovery cutoff, compatible with legacy records and the minimum configured window. This is not a general-purpose durable queue in every SDK.

## Verification

```sh
php tests/delivery.php
CAJEER_TEST_DATABASE=1 php tests/delivery_database.php
python3 tests/sdk_wire.py
```

The database suite requires a disposable PostgreSQL database. It exercises migration from schema 0013, legacy sync/async results, rotation-family replay, unrelated credential/project isolation, cross-mode/body conflicts, revoke, queue completion, real concurrent PHP/PG sessions, configured retention and integrity audit. Wire tests use a controlled local server to drop a response, then return the same operation result; this does not replace actual PostgreSQL fault-injection. The existing transaction and queue integration suites cover rollback/lease/connection-loss; the release gate executes all required suites on PostgreSQL 17/18 and PHP 8.4/8.5. No production certification is asserted until those checks and production smoke actually pass.
