package ru.cajeer.logs

import java.io.IOException
import java.nio.charset.StandardCharsets
import java.util.ArrayDeque
import java.util.UUID
import java.util.concurrent.CompletableFuture
import java.util.concurrent.ExecutionException
import java.util.concurrent.TimeUnit
import java.util.concurrent.TimeoutException

/** Queue capacity is exhausted instead of blocking an application or game-loop thread. */
class QueueFullException : IOException("CajeerLogs JVM outbound queue capacity exceeded")
class QueueClosedException : IOException("CajeerLogs JVM outbound queue is closed")

/**
 * The server's commit outcome is unknown. Keep [body] and [idempotencyKey] together when
 * deciding whether to replay. Do not log the body: it can contain sensitive event context.
 * The original server idempotency retention window still applies to manual replay.
 */
class BatchDeliveryException(
    val body: String,
    val idempotencyKey: String,
    cause: Throwable
) : IOException("CajeerLogs batch delivery was not confirmed; preserve exact body and key", cause) {
    fun retryWith(client: CajeerLogsClient): IngestReceipt = client.ingest(body, idempotencyKey)
}

data class QueueSnapshot(
    val pendingEvents: Int,
    val queuedEvents: Int,
    val inFlightEvents: Int,
    val pendingBytes: Long,
    val acceptedEvents: Long,
    val confirmedEvents: Long,
    val failedEvents: Long,
    val isClosing: Boolean
)

/**
 * Memory-backed bounded producer/consumer for JVM applications (including Paper plugins).
 * submit() validates/serializes locally and NEVER performs network I/O on the caller thread.
 * A dedicated daemon owns HTTP batching/retries; futures expose confirmed receipts or an
 * explicit recovery record. This is NOT a durable disk spool: an abrupt JVM exit loses pending
 * memory-only events. Always drain at graceful shutdown or persist them in your own outbox.
 */
class CajeerLogsAsyncQueue @JvmOverloads constructor(
    private val client: CajeerLogsClient,
    private val maxPendingEvents: Int = 1024,
    private val maxPendingBytes: Long = 8L * 1024 * 1024,
    private val maxBatchEvents: Int = 100,
    private val maxBatchBytes: Int = 1024 * 1024,
    private val flushIntervalMillis: Long = 200,
    threadName: String = "cajeerlogs-jvm-delivery"
) : AutoCloseable {
    private data class Pending(val json: String, val size: Int, val acceptedAt: Long,
                               val future: CompletableFuture<IngestReceipt>)

    private val monitor = java.lang.Object()
    private val pending = ArrayDeque<Pending>()
    private var inFlight: List<Pending> = emptyList()
    private var outstandingCount = 0
    private var outstandingBytes = 0L
    private var accepted = 0L
    private var confirmed = 0L
    private var failed = 0L
    private var closing = false
    private var forceFlush = false
    private val worker: Thread

    init {
        require(maxPendingEvents in 1..1_000_000 && maxPendingBytes in 1..(1L shl 34)) { "Invalid queue limits" }
        require(maxBatchEvents in 1..100 && maxBatchBytes in 1024..1048576) { "Invalid batch limits" }
        require(flushIntervalMillis in 0..60000 && threadName.isNotBlank()) { "Invalid flush interval or thread name" }
        worker = Thread({ workLoop() }, threadName).apply { isDaemon = true; start() }
    }

    /** Nonblocking, thread-safe enqueue. A full queue rejects immediately and loses nothing silently. */
    fun submit(event: LogEvent): CompletableFuture<IngestReceipt> {
        val json = event.json() // Freeze mutable context before worker sees it.
        val size = json.toByteArray(StandardCharsets.UTF_8).size
        require(size + 16 <= maxBatchBytes) { "Event exceeds configured batch size" }
        val future = CompletableFuture<IngestReceipt>()
        synchronized(monitor) {
            if (closing) throw QueueClosedException()
            if (outstandingCount >= maxPendingEvents || outstandingBytes + size > maxPendingBytes) throw QueueFullException()
            pending.addLast(Pending(json, size, System.nanoTime(), future))
            outstandingCount++
            outstandingBytes += size
            accepted++
            monitor.notifyAll()
        }
        return future
    }

    /** Returns one independently completed future per event. Rejects at first capacity failure. */
    fun submitAll(events: Iterable<LogEvent>): List<CompletableFuture<IngestReceipt>> = events.map(::submit)

    /** Requests immediate dispatch of all currently queued events, without blocking the caller. */
    fun flushAsync(): CompletableFuture<Void> {
        val futures: Array<CompletableFuture<*>>
        synchronized(monitor) {
            forceFlush = true
            futures = (inFlight.asSequence() + pending.asSequence()).map { it.future }.toList().toTypedArray()
            monitor.notifyAll()
        }
        return CompletableFuture.allOf(*futures)
    }

    /** Wait for an acknowledged drain. Failed requests surface as BatchDeliveryException. */
    @JvmOverloads fun flushBlocking(timeoutMillis: Long = 30000) {
        require(timeoutMillis in 1..3600000)
        // A future can complete before the worker has retired its batch and
        // published the corresponding queue accounting. A flush is a barrier
        // for both receipt delivery and the observable accounting snapshot.
        val acceptedAtCall = synchronized(monitor) { accepted }
        val deadline = System.nanoTime() + TimeUnit.MILLISECONDS.toNanos(timeoutMillis)
        try { flushAsync().get(timeoutMillis, TimeUnit.MILLISECONDS) }
        catch (e: ExecutionException) { throw (e.cause ?: e) }
        catch (e: TimeoutException) { throw IOException("CajeerLogs flush timed out; delivery may still be in flight", e) }
        synchronized(monitor) {
            while (confirmed + failed < acceptedAtCall) {
                val remaining = deadline - System.nanoTime()
                if (remaining <= 0) {
                    throw IOException("CajeerLogs flush timed out waiting for queue accounting")
                }
                TimeUnit.NANOSECONDS.timedWait(monitor, remaining)
            }
        }
    }

    fun snapshot(): QueueSnapshot = synchronized(monitor) {
        QueueSnapshot(outstandingCount, pending.size, inFlight.size, outstandingBytes,
                      accepted, confirmed, failed, closing)
    }

    /**
     * Stop accepting work and wait for all buffered requests, up to [timeoutMillis].
     * A timeout does not cancel in-flight HTTP: the daemon continues draining. Check futures
     * for delivery failures, and do not exit the JVM before they are handled.
     */
    fun close(timeoutMillis: Long) {
        require(timeoutMillis in 1..3600000)
        if (Thread.currentThread() === worker) throw IllegalStateException("Cannot close queue from delivery worker")
        val drain = synchronized(monitor) {
            closing = true
            forceFlush = true
            val futures = (inFlight.asSequence() + pending.asSequence()).map { it.future }.toList().toTypedArray()
            monitor.notifyAll()
            CompletableFuture.allOf(*futures)
        }
        try {
            // The worker exits after draining; even failed requests release capacity.
            val deadline = System.nanoTime() + TimeUnit.MILLISECONDS.toNanos(timeoutMillis)
            try { drain.get(timeoutMillis, TimeUnit.MILLISECONDS) }
            catch (_: ExecutionException) { /* completions carry the exact batch failure */ }
            val left = deadline - System.nanoTime()
            if (left <= 0 && worker.isAlive) throw TimeoutException()
            worker.join(maxOf(1, TimeUnit.NANOSECONDS.toMillis(left)))
            if (worker.isAlive) throw TimeoutException()
        } catch (e: TimeoutException) {
            throw IOException("CajeerLogs close timed out; outstanding futures remain authoritative", e)
        }
    }

    override fun close() = close(30000)

    private fun workLoop() {
        while (true) {
            val batch: List<Pending> = synchronized(monitor) {
                var selected: List<Pending>? = null
                while (selected == null) {
                    if (pending.isEmpty()) {
                        if (closing) return
                        forceFlush = false
                        monitor.wait()
                        continue
                    }
                    val elapsed = System.nanoTime() - pending.first().acceptedAt
                    val due = TimeUnit.MILLISECONDS.toNanos(flushIntervalMillis) - elapsed
                    if (!closing && !forceFlush && pending.size < maxBatchEvents && due > 0) {
                        TimeUnit.NANOSECONDS.timedWait(monitor, due)
                        continue
                    }
                    val collected = ArrayList<Pending>()
                    var bytes = 13 // UTF-8 bytes of {"events":[]} plus comma accounting
                    while (pending.isNotEmpty() && collected.size < maxBatchEvents) {
                        val next = pending.first()
                        val additional = next.size + if (collected.isEmpty()) 0 else 1
                        if (bytes + additional > maxBatchBytes) break
                        collected.add(pending.removeFirst())
                        bytes += additional
                    }
                    // An individual event cannot exceed maxBatchBytes (checked in submit).
                    check(collected.isNotEmpty())
                    inFlight = collected
                    forceFlush = false
                    selected = collected
                }
                selected
            }
            val body = batch.joinToString(",", "{\"events\":[", "]}") { it.json }
            val key = "kotlin-" + UUID.randomUUID().toString()
            var result: IngestReceipt? = null
            var failure: Throwable? = null
            try { result = client.ingest(body, key) }
            catch (e: Throwable) { failure = e }
            // Complete futures before reporting the queue as drained.
            if (failure == null && result != null) {
                batch.forEach { it.future.complete(result) }
            } else {
                val error = BatchDeliveryException(body, key, failure ?: IOException("Unknown outcome"))
                batch.forEach { it.future.completeExceptionally(error) }
            }
            synchronized(monitor) {
                outstandingCount -= batch.size
                outstandingBytes -= batch.sumOf { it.size.toLong() }
                if (failure == null && result != null) confirmed += batch.size else failed += batch.size
                inFlight = emptyList()
                monitor.notifyAll()
            }
        }
    }
}
