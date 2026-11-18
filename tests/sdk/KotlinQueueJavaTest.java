import ru.cajeer.logs.*;
import java.util.concurrent.TimeUnit;

public class KotlinQueueJavaTest {
    public static void main(String[] args) throws Exception {
        CajeerLogsClient client = new CajeerLogsClient(args[0], args[1], true, 3, 2, "/api/v1", true, null, null);
        try (CajeerLogsAsyncQueue queue = new CajeerLogsAsyncQueue(client)) {
            IngestReceipt receipt = queue.submit(new LogEvent("app", "info", "java-constructor"))
                .get(15, TimeUnit.SECONDS);
            if (receipt.getReceived() != 1 || queue.snapshot().getConfirmedEvents() != 1L)
                throw new AssertionError("Java interface lost event");
            System.out.println("PASS Java 17+ overloads, CompletableFuture, AutoCloseable");
        }
    }
}
