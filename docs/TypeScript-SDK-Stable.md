# TypeScript / Node.js SDK Stable — 0.6.3

The production package `@cajeer/logs-sdk` (Node.js 20+, ESM) implements the real v1 ingestion protocol. `CajeerLogsClient` remains backward-compatible with 0.6.2: strict local event validation, 1 MiB request cap / 100 events, verified 201 and 200 receipts, HTTP 202 async admission and verified job polling. HMAC v1/v2 signatures cover method, URL path, timestamp, per-attempt nonce, idempotency key and **SHA-256 of the exact JSON bytes**. The SDK explicitly disables redirects and uses HTTPS outside loopback by default.

## Delivery and recovery

`DurableBatchDelivery` is an optional Node.js-only file-backed queue. The app must call `await DurableBatchDelivery.open(client, {directory})` and then `await queue.enqueue(event)` instead of `client.ingest(event)` to opt in. New events are validated and written to separate private files with fsync and atomic rename. Under a short cross-process lock, one batch manifest commits an immutable body, key and SHA-256 checksum. `flush()` calls the real client's `sendBody()`, validates the server's receipt, then deletes member files before deleting the manifest. A crash during any of these steps either leaves an event file to send or an immutable batch to replay. A post-commit/pre-ACK crash is safely recovered within the configured server idempotency window. `close()` stops background work; it never purges durable data.

Supported limits: `batchEvents` 1–100 (default 50), `maxEvents` (default 10000), `maxBytes` (default 64 MiB), `flushIntervalMs` (default 1000; set 0 to disable background delivery), `recoveryWindowMs` (default 23h, max 7d with explicit server retention). Every HTTP batch is split to the smaller of the configured event cap and 1 MiB. `enqueue()` throws `SpoolFullError` rather than dropping new events. `flush(maxBatches)` returns the number of server-confirmed events; it throws on the first failed batch without marking unacknowledged events delivered.

On transient 408/429/5xx or network errors, the underlying client uses bounded exponential backoff and a stable body/key; each signature includes a fresh nonce. Nonretryable responses (e.g. 401/403, 409, 307) leave the durable queue intact. Call `flush()` again after operator remediation. For an unknown delivery older than `recoveryWindowMs`, `SpoolExpiredError` prevents automatic replay and keeps all state intact. Recover only after inspecting server records/receipts for possible commit. Token rotation, changes to endpoint or signing configuration cause a scope mismatch and are rejected before new records can be added.

## Operational contract

- Local enqueue acknowledgement is **not** PostgreSQL commit. Only a successfully resolved `flush()` confirms server storage.
- Prefer a **single delivery worker per spool**; concurrent enqueue writers are safe on a local filesystem. Do not use NFS, shared network directories or untrusted/symlinked directories. Permission 0700 is enforced on POSIX systems. Put the queue outside document roots and back it up with encryption if it can contain sensitive logs.
- Do not silently delete or re-key a failed batch. Inspect `status()` and maintain an alert for growing queues and free disk space. Store pending files at least as long as the allowed recovery window.
- `client.ingestAsync()` remains an explicit separate API; it requires durable capture of body/key/receipt by the caller when cross-restart async recovery is needed. The new `DurableBatchDelivery` uses synchronous **committed** ingest and is not an async-job status store.
- AbortSignal is accepted on HTTP client methods; abort may leave an unknown POST outcome and should be recovered using the same body/key or this queue.

## CI / repeatable verification

```sh
npm --prefix packages/ts-sdk install --no-audit --no-fund
npm --prefix packages/ts-sdk run check
npm --prefix packages/ts-sdk pack
python3 tests/sdk_wire.py
python3 tests/sdk_async_wire.py
```

`packages/ts-sdk/test/durable.test.mjs` uses a real local HTTP receiver and verifies HMAC, batching, 503 outage recovery, lost ACK replay after restart, 401 preservation, scoped credentials, expired replay rejection, corrupt metadata handling and multiprocess enqueue (three Node processes). The CI packaging step compiles Node ESM and `.d.ts` before publishing. Server API and DB migrations are unchanged by 0.6.3.
