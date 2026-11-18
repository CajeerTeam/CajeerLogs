# CajeerLogs Kotlin/JVM SDK 0.6.4

**JVM 17+, Kotlin 2.0, Java `HttpClient`, no additional JSON/HTTP library**.
The existing `CajeerLogsClient` synchronous `ingest`, async job admission and status APIs remain compatible.

Build: `gradle -p clients/java-kotlin build`. Local Maven publication: `gradle -p clients/java-kotlin publishToMavenLocal` (artifact `ru.cajeer.logs:cajeer-logs-kotlin:0.6.4`). A runtime Kotlin stdlib is required, brought in by the Gradle/Maven metadata. The project does not automatically publish to any remote repository.

## Bounded, nonblocking submission

```kotlin
import ru.cajeer.logs.*

val client = CajeerLogsClient(System.getenv("CAJEER_URL"), System.getenv("CAJEER_TOKEN"), signed = true)
val queue = CajeerLogsAsyncQueue(client, maxPendingEvents = 2048, maxBatchEvents = 50, flushIntervalMillis = 200)
val receipt = queue.submit(LogEvent("app", "info", "Game server started"))
// No HTTP on the tick/main thread. Observe future exceptions on a separate executor.
receipt.whenCompleteAsync { result, error ->
    if (error != null) System.err.println("CajeerLogs delivery failed: ${error.javaClass.simpleName}")
}
// In plugin disable() or JVM shutdown, outside any critical/game-loop thread:
queue.close(30000)
```

`submit()` serializes locally then enqueues immediately or throws `QueueFullException`/`QueueClosedException`. `maxPendingEvents` and `maxPendingBytes` include both queued **and in-flight** work; `snapshot()` returns counters. The worker batches up to 100 events and 1 MiB and uses HTTP receipt validation, idempotency and HMAC from the existing client. `flushAsync()` signals immediate delivery and returns a future representing submissions accepted before that call. `flushBlocking(timeoutMillis)` and `close(timeoutMillis)` wait for outstanding work; never call them on a Minecraft tick or UI event loop. Follow up on **every failed future**.

If HTTP results remain ambiguous after configured retries, `BatchDeliveryException` exposes the exact `body` and `idempotencyKey` for safe explicit `retryWith(client)` or persistence to your own durable outbox; the exception text deliberately excludes the body. **This in-memory queue is not crash-durable**. A forced JVM termination can lose pending events. Do not equate enqueue with server persistence. For mandatory no-loss guarantees persist submissions before enqueue and reconcile on restart, respecting the server's idempotency retention limit.

For Java 17+:

```java
CajeerLogsClient client = new CajeerLogsClient(System.getenv("CAJEER_URL"), System.getenv("CAJEER_TOKEN"));
try (CajeerLogsAsyncQueue queue = new CajeerLogsAsyncQueue(client)) {
    java.util.concurrent.CompletableFuture<IngestReceipt> confirmed =
        queue.submit(new LogEvent("app", "info", "Started"));
    confirmed.whenCompleteAsync((receipt, error) -> { /* handle success/error */ });
}
```

The `CajeerLogsJulHandler` adapter accepts standard `java.util.logging.LogRecord`s, maps JUL severities to the CajeerLogs schema and formats exceptions and message parameters. It reports overflow via JUL `ErrorManager` rather than silently dropping it. For Paper/Minecraft call `handler.publish` or log via JUL normally; do not perform `flushBlocking`, `close` or `future.get()` on the server main thread. Call blocking cleanup on a separate executor with bounded shutdown time.

Test: `python3 tests/kotlin_sdk_stable.py` (JDK and Kotlin compiler or Gradle); CI additionally runs existing HTTP and async job wire tests. See [Kotlin SDK Stable](../../docs/Kotlin-SDK-Stable.md) and [SDK Delivery Recovery](../../docs/SDK-Delivery-Recovery.md).
