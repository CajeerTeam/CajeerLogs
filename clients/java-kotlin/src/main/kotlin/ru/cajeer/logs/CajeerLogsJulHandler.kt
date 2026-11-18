package ru.cajeer.logs

import java.io.PrintWriter
import java.io.StringWriter
import java.text.MessageFormat
import java.time.temporal.ChronoUnit
import java.util.logging.ErrorManager
import java.util.logging.Handler
import java.util.logging.Level
import java.util.logging.LogRecord

/** java.util.logging adapter: no HTTP on the publishing / game-loop thread. */
class CajeerLogsJulHandler @JvmOverloads constructor(
    private val queue: CajeerLogsAsyncQueue,
    private val source: String,
    private val closeQueueOnClose: Boolean = false
) : Handler() {
    private val reentrant = ThreadLocal.withInitial { false }

    init { level = Level.ALL }

    private fun safeText(value: String, maxCodepoints: Int): String {
        val result = StringBuilder()
        value.codePoints().limit(maxCodepoints.toLong()).forEach { result.appendCodePoint(it) }
        return result.toString()
    }

    override fun publish(record: LogRecord?) {
        if (record == null || !isLoggable(record) || reentrant.get()) return
        reentrant.set(true)
        try {
            val context = linkedMapOf<String, Any?>()
            record.loggerName?.let { context["logger"] = it }
            val params = record.parameters
            val message = if (params != null && params.isNotEmpty()) {
                try { MessageFormat.format(record.message ?: "", *params) }
                catch (_: IllegalArgumentException) { record.message ?: "" }
            } else record.message ?: ""
            record.thrown?.let { thrown ->
                val trace = StringWriter().also { thrown.printStackTrace(PrintWriter(it)) }.toString()
                context["exception"] = safeText(trace, 4000)
            }
            val mapped = when {
                record.level.intValue() >= Level.SEVERE.intValue() -> "error"
                record.level.intValue() >= Level.WARNING.intValue() -> "warning"
                record.level.intValue() >= Level.INFO.intValue() -> "info"
                else -> "debug"
            }
            val text = safeText(message.ifBlank { "(empty log message)" }, 4000)
            queue.submit(LogEvent(source, mapped, text,
                timestamp = record.instant.truncatedTo(ChronoUnit.MICROS).toString(), context = context))
        } catch (e: Exception) {
            reportError("CajeerLogs JUL event was not queued", e, ErrorManager.WRITE_FAILURE)
        } finally {
            reentrant.set(false)
        }
    }

    /** Force a batch flush without blocking java.util.logging's publishing thread. */
    override fun flush() { queue.flushAsync() }
    override fun close() { if (closeQueueOnClose) queue.close() else queue.flushAsync() }
}
