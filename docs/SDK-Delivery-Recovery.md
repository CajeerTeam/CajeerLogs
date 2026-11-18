# CajeerLogs 0.5.8 — Delivery Guarantees & SDK Recovery

The production server's existing PostgreSQL transaction remains authoritative. This release extends **working SDK clients**, not the database schema. There are **no new migrations**; 0001–0024 are unchanged.

## Wire contract

- `POST /api/v1/ingest` returns HTTP **201** with unique event IDs after the PostgreSQL commit; HTTP **200** returns the same receipt with `stored=0, replayed=true` for an identical keyed replay.
- `POST /api/v1/ingest/async` returns HTTP **202** with `accepted`, `received`, decimal-string `job_id`, `status`, `replayed=false` when the job is **durably admitted**. It does **not** confirm event insertion. A keyed replay returns HTTP **200**, `replayed=true` and the same job ID.
- `GET /api/v1/ingest/jobs/{id}` requires an authorized current Bearer credential in the original delivery family and returns durable `pending`, `running`, `completed`, `dead` or `cancelled` state. Only `completed` includes the committed `{stored, event_ids}` result. The SDK checks that all IDs are well formed and unique and that `stored` equals the admitted count.
- On an ambiguous **POST** failure (lost response, timeout, retryable HTTP 408/429/500/502/503/504), retry only the **identical UTF-8 bytes, endpoint, Idempotency-Key**. New HMAC timestamp/nonce/signature are permitted and required for each attempt; never reserialize an event after restarting a process. Do **not** treat a network failure as a confirmed rejection or generate a new key.
- Once an async job ID is known, **poll GET**, do not issue a second enqueue. `dead` and `cancelled` are terminal *failures* requiring explicit operator action. Timed-out polling is **pending**, never success. HTTP 401/403/404, redirects, invalid job result and invalid JSON do not authorize silent retry with a new key.

The server-side idempotency window is finite (at least 24 hours for new deliveries). For an ambiguous POST older than 23 hours, automatic resend is refused by durable collectors/outboxes; operator reconciliation is required. A known job ID can still be polled while retained. Do not claim universal exactly-once delivery across systems; these guarantees cover one authenticated, retained PostgreSQL delivery namespace and its transactional result.

## Python SDK

```python
from cajeer_logs_client import CajeerLogsClient, JobPending, JobFailed
client = CajeerLogsClient('https://logs.example.com', token, signed=True)
receipt = client.ingest_async([event1, event2], idempotency_key='stable-key-123')
try:
    status = client.wait_for_job(receipt, timeout=30, poll_interval=.5)
    assert status['result']['stored'] == 2
except JobPending:
    # Preserve receipt and retry wait_for_job() later (without a new POST).
    pass
except JobFailed:
    # Investigate dead-letter or cancelled job; never silently re-enqueue.
    raise
```

For process-restart protection, use the opt-in, private SQLite outbox:

```python
from pathlib import Path
from cajeer_delivery_spool import DurableAsyncSpool, SpoolFull
with DurableAsyncSpool(Path('/var/lib/cajeer-outbox/app.sqlite'), client,
                       max_jobs=1000, max_bytes=64 * 1024 * 1024) as spool:
    key = spool.enqueue([event1, event2])  # fsync-backed commit BEFORE HTTP
    spool.flush_one(timeout=30, poll_interval=.5)
    print(spool.status())
```

`enqueue` is local and durable; run `flush_one` in a managed worker loop. `flush_one` returns `False` if empty, `True` on **verified** completion, raises `JobPending` on temporary polling timeout, `JobFailed` on terminal error and `DeliveryError` on uncertain admission. All retain the necessary state. A dead/cancelled delivery remains visible for investigation. `prune_completed(keep_last=1000)` removes only old completed metadata; payload is already cleared immediately on verified completion. The spool is bound to the original endpoint, API prefix and token fingerprint and refuses concurrent writers, symlinks, hardlinks, group/world-writable state and mismatched credentials. The durable directory must be privately owned. Never store it under the public document root or on ephemeral container storage.

The existing file collector can optionally use async confirmation:

```sh
cajeer-logs-collector --file /var/log/myapp/app.jsonl \
  --state /var/lib/cajeer-collector/app-async.sqlite \
  --source app --format jsonl --async \
  --completion-timeout 30 --poll-interval .5
```

The collector stores the original body, key and accepted job ID in SQLite. It advances its source-file checkpoint **only after** `completed`. A crash before the accepted ID is persisted results in an identical keyed replay; a crash after it is persisted resumes GET without reenqueuing. A state file already bound to sync mode cannot silently be reinterpreted as async; use a distinct state file for opt-in mode, or drain the existing collector and migrate deliberately.

## TypeScript SDK

```ts
import { CajeerLogsClient, JobPendingError, JobFailedError } from '@cajeer/logs-sdk'
const sdk = new CajeerLogsClient('https://logs.example.com', token, { signed: true })
const receipt = await sdk.ingestAsync([event1, event2], 'stable-key-123')
try {
  const state = await sdk.waitForJob(receipt, 30000, 500)
  console.log(state.result?.event_ids)
} catch (e) {
  if (e instanceof JobPendingError) { /* persist receipt, poll later */ }
  else if (e instanceof JobFailedError) { /* operator recovery */ }
  else throw e
}
```

`sendAsyncBody(exactJsonText, key)` supports an application-managed durable outbox; never change its bytes on replay. `jobStatus(jobId, expectedCount)` is available for resuming a saved receipt. The SDK uses bounded fetch responses, manual redirect mode, per-request timeout and strict event ID validation. Use application-provided durable persistence for browser and Node restart recovery; none is silently implied.

## PHP SDK

```php
$receipt = $client->ingestAsync([$event1, $event2], 'stable-key-123');
try {
    $status = $client->waitForJob($receipt, 30.0, 0.5);
} catch (\Cajeer\Logs\Sdk\JobPendingException $e) {
    // Persist the receipt and resume GET later.
} catch (\Cajeer\Logs\Sdk\JobFailedException $e) {
    // Inspect the dead/cancelled job.
}
```

`sendAsyncBody($exactJsonBytes,$key)`, `jobStatus($jobId,$expectedCount)` and `waitForJob` are available; composer PSR-4 autoloads the three new exception classes.

## Kotlin SDK

```kotlin
val receipt = client.ingestAsync(listOf(event1, event2), "stable-key-123")
try {
    val state = client.waitForJob(receipt, 30_000, 500)
    println(state.eventIds)
} catch (e: JobPendingException) {
    // Keep receipt, poll again later.
} catch (e: JobFailedException) {
    // Operator action required.
}
```

`sendAsyncBody` accepts immutable serialized JSON; `jobStatus` validates state and committed IDs. The JVM client uses bounded HTTP response bodies and redirect refusal. Persist immutable request bytes/key or the accepted job ID in the application's durable storage when process restart recovery is required.

## Verification

`python3 tests/sdk_wire.py` (existing sync/HMAC tests) and `python3 tests/sdk_async_wire.py` (four real HTTP clients, lost response after accepted POST, 503 retry, identical replay, terminal failures, invalid IDs/results, redirect denial, SQLite collector restart and outbox restart) are required in the release CI SDK job. Tests use an actual loopback HTTP server and real PHP/Python/Node/JVM HTTP requests; they do not claim PostgreSQL E2E coverage. The existing PostgreSQL integration and upgrade gates additionally test server-side durable idempotency and transaction boundaries. Full PostgreSQL/Docker certification is still required before changing the release channel from `public-preview`.
