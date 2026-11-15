import ru.cajeer.logs.*
fun main(args: Array<String>) {
    val c = CajeerLogsClient(args[0], args[1], signed=true, timeoutSeconds=2, attempts=2)
    val event = LogEvent("app", "info", "Привет", "2026-01-01T00:00:00Z")
    check(c.ingest(listOf(event,event), "kotlin-retry").stored == 2)
    check(c.ingest(event,"kotlin-replay").replayed)
    check(c.ingest(event,"kotlin-lost").replayed)
    try { c.ingest(listOf(event,event),"kotlin-duplicate"); error("Duplicate receipt accepted") } catch(e: DeliveryException) {}
    for ((key,status) in listOf("kotlin-denied" to 401, "kotlin-redirect" to 307, "kotlin-malformed" to 201)) {
        try { c.ingest(event,key); error("Expected failure") } catch(e: DeliveryException) { check(e.status==status) }
    }
    for (bad in listOf(event.copy(timestamp="2026-02-30T00:00:00Z"),event.copy(message="bad\u001b"),event.copy(source="APP"),event.copy(context=mapOf("n" to Double.NaN)),event.copy(tags=listOf("")))) {
        try { c.ingest(bad,"kotlin-invalid"); error("Invalid event accepted") } catch(e: IllegalArgumentException) {}
    }
    val v2 = CajeerLogsClient(args[0],args[1],signingKeyId="33333333-3333-4333-8333-333333333333",signingSecret="sk_"+"b".repeat(64))
    check(v2.ingest(event,"kotlin-v2").stored == 1)
    println("Kotlin SDK wire tests passed")
}
