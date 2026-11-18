package ru.cajeer.logs

import java.io.IOException
import java.net.URI
import java.net.http.HttpClient
import java.net.http.HttpRequest
import java.net.http.HttpResponse
import java.nio.charset.StandardCharsets.UTF_8
import java.security.MessageDigest
import java.security.SecureRandom
import java.time.Duration
import java.time.Instant
import java.time.temporal.ChronoUnit
import java.nio.ByteBuffer
import java.util.concurrent.Flow
import java.util.concurrent.CompletionStage
import java.util.concurrent.CompletableFuture
import java.util.concurrent.TimeUnit
import java.util.concurrent.TimeoutException
import java.util.concurrent.ExecutionException
import java.io.ByteArrayOutputStream
import javax.crypto.Mac
import javax.crypto.spec.SecretKeySpec

/** JVM 17+, no external runtime dependencies. Durable sync and async delivery. */
data class LogEvent @JvmOverloads constructor(val source: String, val level: String, val message: String,
    val timestamp: String = Instant.now().truncatedTo(ChronoUnit.MICROS).toString(), val service: String? = null,
    val host: String? = null, val context: Map<String, Any?> = emptyMap(),
    val traceId: String? = null, val requestId: String? = null, val tags: List<String> = emptyList()) {
    internal fun json(): String {
        require(source.matches(Regex("[a-z0-9]+(?:-[a-z0-9]+)*")) && source.length<=100)
        require(level.lowercase(java.util.Locale.ROOT) in setOf("debug","info","notice","warning","error","critical","alert","emergency"))
        fun text(v: String, limit: Int) = v.trim(' ', '\t', '\r', '\n', '\u0000', '\u000b').isNotEmpty() && v.toByteArray(UTF_8).size<=limit
        require(text(message,16384) && (service==null || text(service,255)) && (host==null || text(host,255)) && (traceId==null || text(traceId,128)) && (requestId==null || text(requestId,128)))
        require(tags.size<=32 && tags.all { text(it,128) })
        require(timestamp.matches(Regex("[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\\.[0-9]{1,6})?(?:Z|[+-](?:[01][0-9]|2[0-3]):[0-5][0-9])")) && !timestamp.startsWith("0000"))
        val stamp=try {
            val offset=if (timestamp.endsWith("Z")) "Z" else timestamp.takeLast(6)
            val local=java.time.LocalDateTime.parse(timestamp.dropLast(offset.length))
            val minutes=if(offset=="Z") 0 else (offset.substring(1,3).toInt()*60+offset.substring(4,6).toInt())*(if(offset[0]=='-') -1 else 1)
            local.minusMinutes(minutes.toLong())
        } catch(e: java.time.DateTimeException) { throw IllegalArgumentException("Invalid timestamp") }
        require(stamp.year in 1..9999)
        val value=linkedMapOf("source" to source, "level" to level, "message" to message,
        "timestamp" to timestamp, "service" to service, "host" to host, "context" to context,
        "trace_id" to traceId, "request_id" to requestId, "tags" to tags).filterValues { it != null }
        require(Json.encode(context).toByteArray(UTF_8).size<=32768)
        val result=Json.encode(value)
        require(result.toByteArray(UTF_8).size<=65536)
        return result
    }
}
data class IngestReceipt(val received: Int, val stored: Int, val eventIds: List<String>, val replayed: Boolean)
class DeliveryException(val status: Int?, val idempotencyKey: String) : IOException("CajeerLogs delivery failed (HTTP ${status ?: "unknown"}); retry same body and key")
data class AsyncReceipt(val received: Int, val jobId: String, val status: String, val replayed: Boolean)
data class JobState(val id: String, val status: String, val attempts: Int, val maxAttempts: Int, val eventIds: List<String>?)
class JobPendingException(val jobId: String) : IOException("Async job $jobId remains unconfirmed")
class JobFailedException(val jobId: String, val jobStatus: String) : IOException("Async job $jobId reached $jobStatus")
class JobStatusException(val httpStatus: Int?) : IOException("Job status failed (HTTP ${httpStatus ?: "unknown"})")
private fun validJobId(value: String): Boolean = value.matches(Regex("[1-9][0-9]{0,18}")) && value.toLongOrNull() != null
private val JOB_STATES = setOf("pending", "running", "completed", "dead", "cancelled")
private val EVENT_ID = Regex("[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}")

class CajeerLogsClient @JvmOverloads constructor(baseUrl: String, private val token: String,
    private val signed: Boolean = false, private val timeoutSeconds: Long = 10,
    private val attempts: Int = 3, apiPrefix: String = "/api/v1", allowInsecureHttp: Boolean = false,
    private val signingKeyId: String? = null, private val signingSecret: String? = null) {
    private val endpoint: URI
    private val client: HttpClient
    private val random = SecureRandom()
    init {
        val base = URI(baseUrl)
        require(base.scheme in listOf("http", "https") && base.host != null && base.userInfo == null && base.query == null && base.fragment == null && base.path in listOf("", "/")) { "Invalid base URL" }
        require(base.scheme == "https" || allowInsecureHttp || base.host in listOf("localhost", "127.0.0.1", "[::1]", "::1")) { "HTTPS required outside loopback" }
        require(apiPrefix.matches(Regex("/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*")) && token.matches(Regex("[A-Za-z0-9._~-]{1,512}")) && timeoutSeconds in 1..120 && attempts in 1..10) { "Invalid client configuration" }
        require((signingKeyId == null) == (signingSecret == null) && (signingKeyId == null || (signingKeyId.matches(Regex("[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}")) && signingSecret!!.matches(Regex("sk_[a-f0-9]{64}"))))) { "Invalid separate signing configuration" }
        endpoint = base.resolve("$apiPrefix/ingest")
        client = HttpClient.newBuilder().connectTimeout(Duration.ofSeconds(timeoutSeconds)).followRedirects(HttpClient.Redirect.NEVER).build()
    }
    private fun hex(bytes: ByteArray) = bytes.joinToString("") { "%02x".format(it.toInt() and 255) }
    private fun nonce() = hex(ByteArray(16).also(random::nextBytes))
    @JvmOverloads fun ingest(event: LogEvent, idempotencyKey: String = nonce()): IngestReceipt = send(event.json(), 1, idempotencyKey)
    @JvmOverloads fun ingest(events: List<LogEvent>, idempotencyKey: String = nonce()): IngestReceipt = send(events.joinToString(",", "{\"events\":[", "]}") { it.json() }, events.size, idempotencyKey)
    /** Raw v1 JSON (single event or {events:[...]}); useful for Java and custom serialization. */
    @JvmOverloads fun ingest(message: String, idempotencyKey: String = nonce()): IngestReceipt {
        val parsed = Json.parse(message) as? Map<*, *> ?: throw IllegalArgumentException("Expected event object")
        val count = if (parsed.containsKey("events")) (parsed["events"] as? List<*>)?.size ?: 0 else 1
        return send(message, count, idempotencyKey)
    }
    @JvmOverloads fun ingestAsync(event: LogEvent, idempotencyKey: String = nonce()): AsyncReceipt = sendAsync(event.json(), 1, idempotencyKey)
    @JvmOverloads fun ingestAsync(events: List<LogEvent>, idempotencyKey: String = nonce()): AsyncReceipt = sendAsync(events.joinToString(",", "{\"events\":[", "]}") { it.json() }, events.size, idempotencyKey)
    @JvmOverloads fun ingestAsync(message: String, idempotencyKey: String = nonce()): AsyncReceipt {
        val parsed = Json.parse(message) as? Map<*, *> ?: throw IllegalArgumentException("Expected event object")
        val count = if (parsed.containsKey("events")) (parsed["events"] as? List<*>)?.size ?: 0 else 1
        return sendAsync(message, count, idempotencyKey)
    }
    /** Replay exact UTF-8 bytes and the original key after an ambiguous network result. */
    fun sendAsyncBody(body: String, idempotencyKey: String): AsyncReceipt = ingestAsync(body, idempotencyKey)
    private fun sendAsync(json: String, count: Int, key: String): AsyncReceipt = post(json,count,key,true) as AsyncReceipt
    private fun send(json: String, count: Int, key: String): IngestReceipt = post(json,count,key,false) as IngestReceipt
    private fun post(json: String, count: Int, key: String, asyncMode: Boolean): Any {
        val body = json.toByteArray(UTF_8)
        require(count in 1..100 && body.size <= 1048576 && key.matches(Regex("[A-Za-z0-9][A-Za-z0-9._:-]{0,127}"))) { "Invalid body or idempotency key" }
        val url = if (asyncMode) URI(endpoint.toString() + "/async") else endpoint
        repeat(attempts) { attempt ->
            val request = HttpRequest.newBuilder(url).timeout(Duration.ofSeconds(timeoutSeconds))
                .header("Authorization", "Bearer $token").header("Content-Type", "application/json").header("Idempotency-Key", key)
            if (signed || signingKeyId != null) {
                val ts = Instant.now().epochSecond.toString(); val nonce = nonce()
                val prefix = if (signingKeyId == null) listOf("cajeerlogs-ingest-v1") else listOf("cajeerlogs-ingest-v2", signingKeyId)
                if (signingKeyId != null) request.header("X-Log-Key-Id", signingKeyId)
                val canonical = (prefix + listOf("POST", url.path, ts, nonce, key, hex(MessageDigest.getInstance("SHA-256").digest(body)))).joinToString("\n")
                val mac = Mac.getInstance("HmacSHA256").apply { init(SecretKeySpec((signingSecret ?: token).toByteArray(UTF_8), "HmacSHA256")) }
                request.header("X-Log-Timestamp", ts).header("X-Log-Nonce", nonce).header("X-Log-Signature", hex(mac.doFinal(canonical.toByteArray(UTF_8))))
            }
            var status: Int? = null; var wait = 250L * (1L shl attempt)
            try {
                // BodySubscriber caps response memory and completes within the request timeout.
                val future = client.sendAsync(request.POST(HttpRequest.BodyPublishers.ofByteArray(body)).build(), HttpResponse.BodyHandler { LimitedBody() })
                val response = try { future.get(timeoutSeconds, TimeUnit.SECONDS) }
                    catch (e: TimeoutException) { future.cancel(true); throw IOException("Delivery timeout", e) }
                    catch (e: ExecutionException) { throw IOException("Delivery failed", e) }
                    catch (e: InterruptedException) { future.cancel(true); throw e }
                status = response.statusCode()
                val retry = response.headers().firstValue("Retry-After").orElse("").toLongOrNull()
                if (retry != null && retry >= 0) wait = maxOf(wait, minOf(retry, 31) * 1000)
                if (status in listOf(200, if (asyncMode) 202 else 201) && response.body().size <= 65536) {
                    val r = Json.parse(String(response.body(), UTF_8)) as? Map<*, *>
                    val replay = status == 200
                    if (asyncMode) {
                        val id = r?.get("job_id") as? String
                        val state = r?.get("status") as? String
                        if (r?.get("accepted") == true && r["received"] == count.toLong() && r["replayed"] == replay && id != null && validJobId(id) && state in JOB_STATES) {
                            return AsyncReceipt(count, id, state!!, replay)
                        }
                    } else {
                        val ids = r?.get("event_ids") as? List<*>
                        if (r?.get("accepted") == true && r["received"] == count.toLong() && r["stored"] == (if (replay) 0L else count.toLong()) && r["rejected"] == 0L && r["replayed"] == replay && ids != null && ids.size == count && ids.all { it is String && it.matches(EVENT_ID) } && ids.toSet().size == count) {
                            return IngestReceipt(count, if (replay) 0 else count, ids.map { it as String }, replay)
                        }
                    }
                }
            } catch (_: IOException) { /* unknown outcome: same bytes and key */ }
              catch (_: IllegalArgumentException) { /* malformed receipt */ }
            if (attempt + 1 == attempts || wait > 30000 || (status != null && status !in listOf(200,201,408,429,500,502,503,504))) throw DeliveryException(status, key)
            Thread.sleep(wait + random.nextInt(100))
        }
        error("Unreachable")
    }
    /** Job lookup never follows redirects and validates committed event IDs. */
    @JvmOverloads fun jobStatus(id: String, expectedCount: Int? = null): JobState {
        require(validJobId(id) && (expectedCount == null || expectedCount in 1..100)) { "Invalid job ID or count" }
        val url = URI(endpoint.toString() + "/jobs/" + id)
        val request = HttpRequest.newBuilder(url).timeout(Duration.ofSeconds(timeoutSeconds))
            .header("Authorization", "Bearer $token").GET().build()
        val response = try {
            val future = client.sendAsync(request, HttpResponse.BodyHandler { LimitedBody() })
            try { future.get(timeoutSeconds, TimeUnit.SECONDS) }
            catch(e: TimeoutException) { future.cancel(true); throw JobStatusException(null) }
            catch(e: ExecutionException) { throw JobStatusException(null) }
            catch(e: InterruptedException) { future.cancel(true); throw e }
        } catch(e: IOException) { throw JobStatusException(null) }
        if (response.statusCode() != 200) throw JobStatusException(response.statusCode())
        val row = try { Json.parse(String(response.body(), UTF_8)) as? Map<*, *> } catch(e: IllegalArgumentException) { null }
        val status = row?.get("status") as? String
        val attempts = row?.get("attempts") as? Long
        val maximum = row?.get("max_attempts") as? Long
        require(row?.get("id") == id && status in JOB_STATES && attempts != null && maximum != null && attempts >= 0 && maximum >= 1 && attempts <= maximum && maximum <= Int.MAX_VALUE) { "Invalid job status" }
        val result = row["result"]
        val ids = if (status == "completed") {
            val r = result as? Map<*, *> ?: throw IllegalArgumentException("Missing completed job result")
            val list = r["event_ids"] as? List<*> ?: throw IllegalArgumentException("Missing event IDs")
            require(list.size in 1..100 && r["stored"] == list.size.toLong() && (expectedCount == null || list.size == expectedCount)
                && list.all { it is String && it.matches(EVENT_ID) } && list.toSet().size == list.size) { "Invalid completed job result" }
            list.map { it as String }
        } else {
            require(result == null) { "Unexpected unfinished job result" }
            null
        }
        return JobState(id, status!!, attempts.toInt(), maximum.toInt(), ids)
    }
    /** A timeout preserves the accepted job ID. Never submit another job automatically. */
    @JvmOverloads fun waitForJob(receipt: AsyncReceipt, timeoutMillis: Long = 30000, pollMillis: Long = 500): JobState {
        require(validJobId(receipt.jobId) && receipt.received in 1..100 && timeoutMillis in 1..3600000 && pollMillis in 50..30000) { "Invalid polling limits" }
        val deadline = System.nanoTime() + timeoutMillis * 1000000
        while (true) {
            try {
                val state = jobStatus(receipt.jobId, receipt.received)
                if (state.status == "completed") return state
                if (state.status in setOf("dead", "cancelled")) throw JobFailedException(receipt.jobId, state.status)
            } catch(e: JobStatusException) {
                if (e.httpStatus != null && e.httpStatus !in listOf(408,429,500,502,503,504)) throw e
            }
            val remaining = (deadline - System.nanoTime()) / 1000000
            if (remaining <= 0) throw JobPendingException(receipt.jobId)
            Thread.sleep(minOf(pollMillis, remaining))
        }
    }

}

/** Small bounded JSON codec for the SDK's wire format. */
private object Json {
    fun encode(v: Any?, depth: Int = 0, nodes: IntArray = intArrayOf(0)): String {
        require(depth <= 24 && ++nodes[0]<=8192)
        return when (v) {
            null -> "null"
            is String -> buildString { require(UTF_8.newEncoder().canEncode(v) && v.none { it.code in 0..8 || it.code in 11..12 || it.code in 14..31 || it.code==127 }); append('"'); for (c in v) when(c) { '"','\\' -> { append('\\'); append(c) }; else -> if (c.code < 32) append("\\u%04x".format(c.code)) else append(c) }; append('"') }
            is Boolean, is Byte, is Short, is Int, is Long -> v.toString()
            is Float -> { require(v.isFinite()); v.toString() }
            is Double -> { require(v.isFinite()); v.toString() }
            is Map<*,*> -> { require(v.size<=2048); v.entries.joinToString(",", "{", "}") { val key=it.key; require(key is String && key.toByteArray(UTF_8).size<=256 && key.none { c->c.code<32 || c.code==127 }); encode(key,0) + ":" + encode(it.value,depth+1,nodes) } }
            is Iterable<*> -> { var count=0; v.joinToString(",", "[", "]") { require(++count<=2048); encode(it,depth+1,nodes) } }
            else -> throw IllegalArgumentException("Unsupported JSON value")
        }
    }
    fun parse(text: String): Any? = Parser(text).parse()
    private class Parser(val s: String) {
        var i = 0
        fun parse(): Any? { require(s.length <= 1048576); val v = value(0); ws(); require(i == s.length); return v }
        fun ws() { while(i < s.length && s[i] in " \r\n\t") i++ }
        fun take(c: Char): Boolean { ws(); if(i < s.length && s[i] == c) { i++; return true }; return false }
        fun string(): String { require(take('"')); return buildString {
            while(i < s.length) { val c=s[i++]; if(c=='"') return@buildString; require(c.code>=32)
                if(c!='\\') append(c) else { require(i<s.length); when(val e=s[i++]) {
                    '"','\\','/' -> append(e); 'b' -> append('\b'); 'f' -> append('\u000c'); 'n' -> append('\n'); 'r' -> append('\r'); 't' -> append('\t');
                    'u' -> { require(i+4<=s.length); append(s.substring(i,i+4).toInt(16).toChar()); i+=4 }; else -> throw IllegalArgumentException("Invalid JSON escape") } }
            }; throw IllegalArgumentException("Unterminated string")
        } }
        fun value(depth: Int): Any? { require(depth<=32); ws(); require(i<s.length); return when(s[i]) {
            '"' -> string()
            '{' -> { i++; val m=linkedMapOf<String,Any?>(); if(!take('}')) { do { val k=string(); require(!m.containsKey(k) && take(':')); m[k]=value(depth+1) } while(take(',')); require(take('}')) }; m }
            '[' -> { i++; val a=mutableListOf<Any?>(); if(!take(']')) { do { a.add(value(depth+1)) } while(take(',')); require(take(']')) }; a }
            't','f','n' -> { val word=when(s[i]){'t'->"true";'f'->"false";else->"null"}; require(s.startsWith(word,i)); i+=word.length; when(word){"true"->true;"false"->false;else->null} }
            else -> { val start=i; while(i<s.length && s[i] in "-+0123456789.eE") i++; val n=s.substring(start,i); require(n.matches(Regex("-?(0|[1-9][0-9]*)(\\.[0-9]+)?([eE][+-]?[0-9]+)?"))); n.toLongOrNull() ?: n.toDouble().also { require(it.isFinite()) } }
        } }
    }
}

private class LimitedBody : HttpResponse.BodySubscriber<ByteArray> {
    private val result = CompletableFuture<ByteArray>()
    private val out = ByteArrayOutputStream()
    private lateinit var subscription: Flow.Subscription
    override fun getBody(): CompletionStage<ByteArray> = result
    override fun onSubscribe(s: Flow.Subscription) { subscription = s; s.request(1) }
    override fun onNext(items: List<ByteBuffer>) {
        for (b in items) { if (b.remaining() > 65536 - out.size()) { subscription.cancel(); result.completeExceptionally(IOException("Receipt too large")); return }; val bytes=ByteArray(b.remaining()); b.get(bytes); out.write(bytes) }; subscription.request(1)
    }
    override fun onError(t: Throwable) { result.completeExceptionally(t) }
    override fun onComplete() { result.complete(out.toByteArray()) }
}
