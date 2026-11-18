# Kotlin/JVM SDK 0.6.4 — operations and acceptance

This release implements a working producer/consumer sender on top of the existing validated synchronous v1 HTTP client, with `java.util.logging` and Java interoperability. It is **bounded and nonblocking for submitters**; one background daemon handles network I/O. No Kotlin coroutine dependency or fake server mode is required.

## Operational contract

- `CajeerLogsAsyncQueue.submit(event)` validates and freezes an event before placing it in memory; returns `CompletableFuture<IngestReceipt>` immediately. It does not confirm an event's presence in PostgreSQL.
- `maxPendingEvents` (default 1024) and `maxPendingBytes` (default 8 MiB) cap queued **plus in-flight** requests. A full queue raises `QueueFullException`. Configure backpressure handling in the caller, including throttling or durable upstream persistence.
- Batches are sent with the existing `CajeerLogsClient.ingest()` method: maximum 100 events and 1 MiB, fresh HMAC nonce on each retry and immutable body/key for the whole batch. Each event future completes after validated server acknowledgement (or with an explicit failure); one batch receipt is shared between its event futures.
- `flushAsync` requests immediate delivery of the accepted snapshot; `flushBlocking` waits up to a caller-supplied timeout; `close(timeoutMillis)` prevents further enqueues and drains queued work until its deadline. On timeout the daemon continues trying, and the caller must check outstanding futures. Completed failures are tracked in `snapshot()` and should not be silently ignored.
- `BatchDeliveryException` contains the exact batch body and key if the transport outcome is uncertain; persist/reconcile them before any manual recovery. `retryWith()` is safe only within the server's idempotency window and only with unchanged credentials and the same origin. No new key should be generated to replay uncertain work.
- **No local persistent outbox is provided by this JVM queue.** A crash or forceful shutdown can discard queued/in-flight events. If durable logging is required, persist the original events and confirmed receipts in application-owned storage, or use an external durable collector. No guarantee of no-loss can be inferred from the nonblocking API alone.

## `java.util.logging` adapter

```kotlin
val sender = CajeerLogsAsyncQueue(client)
val handler = CajeerLogsJulHandler(sender, "minecraft", closeQueueOnClose = false)
val logger = java.util.logging.Logger.getLogger("server")
logger.addHandler(handler)
logger.warning("Example warning")
// On graceful shutdown, from an off-main-thread lifecycle hook:
logger.removeHandler(handler)
handler.close()  // schedules flush
sender.close(30000)  // bounded drain, never on Paper tick thread
```

`publish` enqueues without HTTP network calls. Exception stack traces are capped; output is UTF-8-safe. `flush()` schedules work, rather than waiting on a logging thread. JUL's `ErrorManager` reports queue overflow/invalid payload. `closeQueueOnClose` is false by default so an adapter does not unexpectedly close another component's shared sender.

## Verification

Run `python3 tests/kotlin_sdk_stable.py`. The integration test compiles the actual Kotlin production sources, starts a real local HTTP receiver that verifies HMAC and receipts, then tests batching, 503, lost ACK, failed 401, explicit replay, bounded capacity, time-driven flush, concurrent producers and JUL. A separate Java 17 test exercises the real overloads, futures and `AutoCloseable`. The existing synchronous and asynchronous SDK wire suites remain part of CI. Docker/PostgreSQL E2E and publishing to a remote Maven repository must be verified separately before external stable certification.
