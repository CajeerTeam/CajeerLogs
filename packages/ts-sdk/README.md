# CajeerLogs TypeScript / Node.js SDK 0.6.3

**Node.js 20+**, ESM, typed declarations, native `fetch`/Web Crypto. The SDK does not run in the browser with a write token. Runtime has no third-party dependencies.

```sh
npm install
npm run check
npm pack
# install the generated cajeer-logs-sdk-0.6.3.tgz in your server application
```

## Immediate HTTP ingestion (backward compatible)

```ts
import { CajeerLogsClient } from '@cajeer/logs-sdk'
const client = new CajeerLogsClient(process.env.CAJEER_URL!, process.env.CAJEER_TOKEN!, {
  signed: true, attempts: 3, timeoutMs: 10_000
})
const receipt = await client.ingest({
  timestamp: new Date().toISOString(), source: 'app', level: 'info', message: 'Started'
})
console.log(receipt.event_ids) // verified server commit
```

`ingest()` accepts a single event, an array of events, or `{events}` (max 100, max 1 MiB). `ingestAsync()` returns a durable admission receipt (HTTP 202), **not** a completed DB commit; use `waitForJob()` or `jobStatus()`. `sendBody()`/`sendAsyncBody()` replay identical JSON bytes with the original idempotency key. All HTTP methods accept an optional `AbortSignal` as the last argument; on cancellation, unknown POST outcomes still require idempotent recovery.

## Durable local batching (Node.js only)

```ts
import { CajeerLogsClient, DurableBatchDelivery } from '@cajeer/logs-sdk'
const client = new CajeerLogsClient(process.env.CAJEER_URL!, process.env.CAJEER_TOKEN!, { signed: true })
const queue = await DurableBatchDelivery.open(client, {
  directory: '/var/lib/myapp/cajeer-outbox',
  batchEvents: 50, maxEvents: 10_000, maxBytes: 64 * 1024 * 1024,
  flushIntervalMs: 1000
})
await queue.enqueue({timestamp: new Date().toISOString(),source:'app',level:'info',message:'Started'})
// Only local fsync is confirmed at this point; the event may not yet be in PostgreSQL.
await queue.flush()  // awaits verified, committed server receipts and removes acknowledged entries
console.log(await queue.status())
await queue.close() // stops timer; pending events remain for recovery on next open()
```

`enqueue()` returns **after a durable local write** and throws `SpoolFullError` before accepting an event if configured limits are reached. Background delivery attempts HTTP only when a periodic timer is enabled; failures remain on disk and are visible via `status().lastError`. To monitor server confirmation, call `flush()` and handle its errors. Periodically monitor `events`, `batches` and available disk space; configure an alert when the queue grows.

Bodies, event membership, immutable keys and checksums are saved before sending. On HTTP timeout or lost ACK the same JSON bytes/key replay with a fresh HMAC nonce. `SpoolExpiredError` deliberately leaves the batch and events on disk; reconcile against the server instead of guessing a new key. `SpoolCorruptError` blocks opening a damaged spool or a spool associated with changed credentials. `close()` never discards pending records.

Store queues on a **private local filesystem** (0700 directory, private files, encrypted backups if necessary); avoid NFS/shared network drives. Multiple processes can append to one spool, but use **one designated delivery worker** for predictable throughput. Keep enough disk headroom for pending batch manifests. Do not copy or delete pending files during recovery; do not rotate a write token with pending batches without an explicit migration/reconciliation plan. The default replay safety limit is **23 hours**, below the legacy idempotency window; extend `recoveryWindowMs` only when the server's configured retention and credential continuity are verified. Neither an HTTP 202 acknowledgement nor local enqueue is proof of completed storage.

See [TypeScript SDK Stable](../../docs/TypeScript-SDK-Stable.md), [SDK & Integrations](../../docs/SDK-Integrations.md) and [Delivery Recovery](../../docs/SDK-Delivery-Recovery.md).
