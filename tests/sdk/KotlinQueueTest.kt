import ru.cajeer.logs.*
import java.io.IOException
import java.util.concurrent.CompletableFuture
import java.util.concurrent.CompletionException
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicInteger
import java.util.logging.Level
import java.util.logging.Logger
import kotlin.system.measureNanoTime

private fun check(value: Boolean, name: String) { require(value) { name }; println("PASS Kotlin $name") }
private fun await(future: CompletableFuture<IngestReceipt>): IngestReceipt = future.get(20, TimeUnit.SECONDS)
private fun event(message: String) = LogEvent("app", "info", message)

fun main(args: Array<String>) {
    val url=args[0]; val token=args[1]
    val client=CajeerLogsClient(url,token,signed=true,timeoutSeconds=4,attempts=3,allowInsecureHttp=true)
    val q=CajeerLogsAsyncQueue(client,maxPendingEvents=500,maxBatchEvents=100,flushIntervalMillis=4000)
    val entries=(1..25).map { q.submit(event("batch-${it}")) }
    check(entries.none { it.isDone },"enqueue returns before HTTP")
    q.flushBlocking()
    check(entries.all { await(it).received==25 },"one actual batch acknowledged with valid HMAC")
    check(q.snapshot().pendingEvents==0 && q.snapshot().confirmedEvents==25L,"accounting after delivery")

    val retry=await(q.submit(event("retry")).also { q.flushAsync() }) // 503 then success
    check(retry.received==1,"retry after 503 without blocking caller")
    val lost=await(q.submit(event("lost")).also { q.flushAsync() })
    check(lost.replayed,"lost HTTP response recovered with idempotency key")
    val pending=q.submit(event("permanent")); q.flushAsync()
    try { await(pending); error("401 incorrectly acknowledged") }
    catch (e: java.util.concurrent.ExecutionException) {
        val failed=e.cause as? BatchDeliveryException ?: throw e
        check(failed.body.contains("permanent") && failed.idempotencyKey.startsWith("kotlin-"),"permanent error exposes exact replay payload/key")
        check(!failed.message.orEmpty().contains("permanent"),"batch contents not exposed in exception message")
    }
    val oneTryClient=CajeerLogsClient(url,token,signed=true,timeoutSeconds=4,attempts=1,allowInsecureHttp=true)
    val uncertain=CajeerLogsAsyncQueue(oneTryClient,flushIntervalMillis=10)
    val ambiguous=uncertain.submit(event("manual-replay")); uncertain.flushAsync()
    try { await(ambiguous); error("expected first-attempt uncertainty") }
    catch (e: java.util.concurrent.ExecutionException) {
        val failed=e.cause as BatchDeliveryException
        check(failed.retryWith(client).replayed,"manual recovery replays exact JSON bytes and key")
    }
    uncertain.close()
    q.close()
    check(q.snapshot().pendingEvents==0 && q.snapshot().failedEvents==1L,"failed batches release capacity")
    try { q.submit(event("closed")); error("closed queue accepted") }
    catch (_: QueueClosedException) { check(true,"closed queue rejects new work") }

    // Capacity counts IN-FLIGHT events, not just dequeued items.
    val slow=CajeerLogsAsyncQueue(client,maxPendingEvents=2,maxPendingBytes=100000,maxBatchEvents=1,flushIntervalMillis=0)
    val one=slow.submit(event("hold"))
    val two=slow.submit(event("hold-two"))
    try { slow.submit(event("overflow")); error("queue unbounded") }
    catch (_: QueueFullException) { check(true,"backpressure rejects without blocking") }
    check(slow.snapshot().pendingEvents==2,"queued + in-flight counted toward capacity")
    slow.flushBlocking()
    check(await(one).received==1 && await(two).received==1,"bounded queue drained")
    slow.close()

    val timer=CajeerLogsAsyncQueue(client,flushIntervalMillis=60)
    check(await(timer.submit(event("timer"))).received==1,"periodic flush without explicit call")
    timer.close()

    val concurrent=CajeerLogsAsyncQueue(client,maxPendingEvents=600,maxBatchEvents=50,flushIntervalMillis=25)
    val all=java.util.Collections.synchronizedList(mutableListOf<CompletableFuture<IngestReceipt>>())
    val producers=(0 until 6).map { worker -> Thread {
        repeat(30) { idx -> all.add(concurrent.submit(event("producer-$worker-$idx"))) }
    }.also { it.start() } }
    producers.forEach(Thread::join)
    concurrent.flushBlocking()
    check(all.size==180 && all.all { await(it).received in 1..50 },"six concurrent producers, exactly 180 confirmed events")
    val afterConcurrentFlush=concurrent.snapshot()
    check(afterConcurrentFlush.confirmedEvents==180L && afterConcurrentFlush.pendingEvents==0 &&
          afterConcurrentFlush.inFlightEvents==0,"concurrent event accounting")

    val logger=Logger.getLogger("sdk-test")
    logger.useParentHandlers=false
    logger.level=Level.ALL
    val handler=CajeerLogsJulHandler(concurrent,"app")
    logger.addHandler(handler)
    logger.info("jul logger")
    logger.log(Level.SEVERE,"exception",IllegalStateException("trace"))
    handler.flush()
    concurrent.flushBlocking()
    check(concurrent.snapshot().confirmedEvents==182L,"java.util.logging handler forwards events on worker")
    logger.removeHandler(handler)
    handler.close()
    concurrent.close()
    println("Kotlin async queue integration passed")
}
