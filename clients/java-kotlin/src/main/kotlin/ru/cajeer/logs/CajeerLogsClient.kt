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

/** JVM 17+, no external runtime dependencies. Synchronous delivery. */
data class LogEvent(val source: String, val level: String, val message: String,
    val timestamp: String = Instant.now().truncatedTo(ChronoUnit.MICROS).toString(), val service: String? = null,
    val host: String? = null, val context: Map<String, Any?> = emptyMap(),
    val traceId: String? = null, val requestId: String? = null, val tags: List<String> = emptyList()) {
    internal fun json(): String = Json.encode(linkedMapOf("source" to source, "level" to level, "message" to message,
        "timestamp" to timestamp, "service" to service, "host" to host, "context" to context,
        "trace_id" to traceId, "request_id" to requestId, "tags" to tags).filterValues { it != null })
}
data class IngestReceipt(val received: Int, val stored: Int, val eventIds: List<String>, val replayed: Boolean)
class DeliveryException(val status: Int?, val idempotencyKey: String) : IOException("CajeerLogs delivery failed (HTTP ${status ?: "unknown"}); retry same body and key")

class CajeerLogsClient @JvmOverloads constructor(baseUrl: String, private val token: String,
    private val signed: Boolean = false, private val timeoutSeconds: Long = 10,
    private val attempts: Int = 3, apiPrefix: String = "/api/v1", allowInsecureHttp: Boolean = false) {
    private val endpoint: URI
    private val client: HttpClient
    private val random = SecureRandom()
    init {
        val base = URI(baseUrl)
        require(base.scheme in listOf("http", "https") && base.host != null && base.userInfo == null && base.query == null && base.fragment == null && base.path in listOf("", "/")) { "Invalid base URL" }
        require(base.scheme == "https" || allowInsecureHttp || base.host in listOf("localhost", "127.0.0.1", "[::1]", "::1")) { "HTTPS required outside loopback" }
        require(apiPrefix.matches(Regex("/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*")) && token.matches(Regex("[A-Za-z0-9._~-]{1,512}")) && timeoutSeconds in 1..120 && attempts in 1..10) { "Invalid client configuration" }
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
    private fun send(json: String, count: Int, key: String): IngestReceipt {
        val body = json.toByteArray(UTF_8)
        require(count in 1..100 && body.size <= 1048576 && key.matches(Regex("[A-Za-z0-9][A-Za-z0-9._:-]{0,127}"))) { "Invalid body or idempotency key" }
        repeat(attempts) { attempt ->
            val request = HttpRequest.newBuilder(endpoint).timeout(Duration.ofSeconds(timeoutSeconds))
                .header("Authorization", "Bearer $token").header("Content-Type", "application/json").header("Idempotency-Key", key)
            if (signed) {
                val ts = Instant.now().epochSecond.toString(); val nonce = nonce()
                val canonical = listOf("cajeerlogs-ingest-v1", "POST", endpoint.path, ts, nonce, key, hex(MessageDigest.getInstance("SHA-256").digest(body))).joinToString("\n")
                val mac = Mac.getInstance("HmacSHA256").apply { init(SecretKeySpec(token.toByteArray(UTF_8), "HmacSHA256")) }
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
                if (status in listOf(200, 201) && response.body().size <= 65536) {
                    val r = Json.parse(String(response.body(), UTF_8)) as? Map<*, *>
                    val ids = r?.get("event_ids") as? List<*>
                    val replay = status == 200
                    if (r?.get("accepted") == true && r["received"] == count.toLong() && r["stored"] == (if (replay) 0L else count.toLong()) && r["rejected"] == 0L && r["replayed"] == replay && ids != null && ids.size == count && ids.all { it is String && it.isNotEmpty() }) {
                        return IngestReceipt(count, if (replay) 0 else count, ids.map { it as String }, replay)
                    }
                }
            } catch (_: IOException) { /* unknown outcome: same bytes and key */ }
              catch (_: IllegalArgumentException) { /* malformed receipt */ }
            if (attempt + 1 == attempts || wait > 30000 || (status != null && status !in listOf(200,201,408,429,500,502,503,504))) throw DeliveryException(status, key)
            Thread.sleep(wait + random.nextInt(100))
        }
        error("Unreachable")
    }
}

/** Small bounded JSON codec for the SDK's wire format. */
private object Json {
    fun encode(v: Any?, depth: Int = 0): String {
        require(depth <= 24)
        return when (v) {
            null -> "null"
            is String -> buildString { append('"'); for (c in v) when(c) { '"','\\' -> { append('\\'); append(c) }; else -> if (c.code < 32) append("\\u%04x".format(c.code)) else append(c) }; append('"') }
            is Boolean, is Byte, is Short, is Int, is Long -> v.toString()
            is Float -> { require(v.isFinite()); v.toString() }
            is Double -> { require(v.isFinite()); v.toString() }
            is Map<*,*> -> v.entries.joinToString(",", "{", "}") { require(it.key is String); encode(it.key, depth+1) + ":" + encode(it.value,depth+1) }
            is Iterable<*> -> v.joinToString(",", "[", "]") { encode(it,depth+1) }
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
