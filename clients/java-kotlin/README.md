# CajeerLogs Java/Kotlin SDK 0.2.6

JVM 17+, Kotlin 2.0; стандартный `java.net.http.HttpClient`, без сторонних HTTP/JSON-библиотек. `gradle -p clients/java-kotlin build` (Gradle 8.10.2) собирает JAR, который можно подключить к Java/Kotlin-приложению. Kotlin stdlib требуется в runtime.

```kotlin
import ru.cajeer.logs.CajeerLogsClient
import ru.cajeer.logs.LogEvent

val client = CajeerLogsClient(System.getenv("CAJEER_URL"), System.getenv("CAJEER_TOKEN"), signed = true)
val result = client.ingest(LogEvent("app", "info", "Started"), "job-42")
println(result.eventIds)
```

Для Java доступны перегруженные `ingest(String json, String idempotencyKey)` и `ingest(LogEvent, String)`; метод с одним String теперь принимает **JSON события**, а не произвольный текст. `LogEvent` генерирует UTC timestamp с микросекундной точностью. Single/batch, HMAC, повторяемые ошибки, ограничение ответа и `DeliveryException` со статусом/ключом. Вызовы синхронные; в Minecraft/plugin event loop отправляйте через выделенный executor или локальный файловый коллектор. Не блокируйте игровой tick. [SDK & Integrations](../../docs/SDK-Integrations.md).
