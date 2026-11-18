import ru.cajeer.logs.*
fun main(args: Array<String>) {
    val c=CajeerLogsClient(args[0],args[1],signed=true,timeoutSeconds=2,attempts=2)
    val e=LogEvent("app","info","async","2026-11-17T12:00:00Z")
    for (key in listOf("kotlin-async-lost","kotlin-async-retry","kotlin-async-replay")) {
        val r=c.ingestAsync(listOf(e,e),key)
        check(r.received==2 && c.waitForJob(r,2000,50).eventIds?.size==2)
        if(key=="kotlin-async-replay") {
            val replay=c.ingestAsync(listOf(e,e),key)
            check(replay.replayed && replay.jobId==r.jobId)
        }
    }
    for(kind in listOf("dead","cancelled")) {
        val r=c.ingestAsync(e,"kotlin-async-"+kind)
        try {c.waitForJob(r,250,50);error("False completion")}
        catch(ex: JobFailedException) {check(ex.jobStatus==kind)}
    }
    val pending=c.ingestAsync(e,"kotlin-async-pending")
    try {c.waitForJob(pending,150,50);error("False completion")}
    catch(ex: JobPendingException) {check(ex.jobId==pending.jobId)}
    val bad=c.ingestAsync(listOf(e,e),"kotlin-async-invalid")
    try {c.waitForJob(bad,1000,50);error("False completion")}
    catch(ex: IllegalArgumentException) {}
    for((kind,status) in listOf("redirect" to 307,"401" to 401,"badreceipt" to 202)) {
        try {c.ingestAsync(e,"kotlin-async-"+kind);error("False acceptance")}
        catch(ex: DeliveryException) {check(ex.status==status)}
    }
    println("Kotlin async recovery HTTP tests passed")
}
