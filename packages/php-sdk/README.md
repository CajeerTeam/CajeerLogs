# CajeerLogs PHP / Monolog SDK 0.6.1

PHP 8.4+, `psr/log` 3, optional `monolog/monolog` 3. Composer package name is unchanged: `cajeer/logs-extension-sdk`. The SDK uses HTTPS PHP streams (`allow_url_fopen=On`, OpenSSL and trusted CA), with no PHP-side database requirement. Install the SDK as a Composer path package pointing to `packages/php-sdk`, or install the published artifact of the same version. Do not expose the bearer token or signing secret to frontend JavaScript.

## Existing synchronous mode (unchanged)

```php
use Cajeer\Logs\Sdk\{CajeerLogsClient, Logger, MonologHandler};

$client = new CajeerLogsClient(getenv('CAJEER_URL'), getenv('CAJEER_TOKEN'), signed: true);
(new Logger($client, 'app', 'backend'))->info('User {user} logged in', ['user' => 42]);

$monolog = new \Monolog\Logger('app');
$monolog->pushHandler(new MonologHandler($client, 'app'));
$monolog->warning('This is delivered synchronously');
```

A synchronous `ingest()` returns a validated acknowledgement. `ingestAsync()` returns a durable queue job receipt: **202 does not mean committed log events**. `waitForJob()` checks job completion. When `DeliveryException` occurs, the application must retry the same JSON bytes using the same idempotency key; use `sendBody()` or `sendAsyncBody()` for resumed raw deliveries. See [async recovery](../../docs/SDK-Delivery-Recovery.md).

## Durable microbatches (new in 0.6.1)

```php
use Cajeer\Logs\Sdk\{CajeerLogsClient, DurableBatchDelivery, Logger, MonologHandler};

$client = new CajeerLogsClient(getenv('CAJEER_URL'), getenv('CAJEER_TOKEN'), signed: true);
$delivery = new DurableBatchDelivery(
    client: $client,
    spoolDirectory: '/var/lib/my-service/cajeer-sdk',  // must be private, non-web-accessible
    batchSize: 25,           // 1..100 events
    maxPendingEvents: 10000, // bounded on-disk backlog
    maxBatchBytes: 1048576,  // <= 1 MiB HTTP request
    spoolOnDeliveryFailure: true,
);

$logger = new Logger($client, 'app', 'backend', delivery: $delivery);
$logger->info('Imported {count} rows', ['count' => 25]);

$monolog = new \Monolog\Logger('application');
$handler = new MonologHandler($client, 'app', 'backend', delivery: $delivery);
$monolog->pushHandler($handler);
$monolog->error('Application error', ['error' => new \RuntimeException('example')]);

// Required for partially filled batches in long-running applications.
$handler->close();  // or $delivery->flush() / $logger->flush()
if ($delivery->pendingEvents() !== 0) {
    // Report backlog via a DIFFERENT logging sink and retry later.
}
```

`enqueue()` always validates and persists the event **before returning**. It flushes automatically when at least `batchSize` unbatched events exist. A partially filled batch remains queued until an explicit `flush()`/Monolog `close()`. Make that call at predictable application lifecycle boundaries (including worker shutdown) or schedule periodic flushes in long-running processes. Do not rely on destructors or shutdown callbacks for crash recovery. Multiple PHP processes may use the same spool directory; file locks prevent duplicate concurrent flushes. Each immutable batch contains the exact request bytes and original idempotency key and is retried unchanged after process restarts. Transport requests are HMAC signed per attempt with a fresh nonce.

**Failure policy:** the default (`spoolOnDeliveryFailure: false`) throws `DeliveryException` but retains undelivered events on disk. `true` keeps network failures spooled without throwing; inspect `lastFailure()` and `pendingEvents()` to detect backlog. Storage errors, corrupt spool entries, unsafe permissions and `maxPendingEvents` overflow always throw, even in spool mode. `flush()` returns the count of events with validated HTTP delivery confirmations; a full spool is **not** a success condition. Persisted files may contain log context and sensitive operational information; avoid logging credentials and rotate/archive the private storage as appropriate.

Durable **sync** batching uses `/api/v1/ingest`, not `/ingest/async`: HTTP 200/201 must include a valid event receipt; the SDK will not discard a batch on HTTP 202 or malformed acknowledgement. The receiver's idempotency retention must exceed the maximum offline/recovery period for safe replay. Without that condition, exactly-once event storage cannot be guaranteed. Files are fsynced before atomic renaming; durability of directory metadata after sudden power loss depends on the underlying filesystem, so use supported local disks with suitable crash guarantees (not ephemeral containers or unsafe shared mounts).

For operation, permissions, failure modes, test commands and recovery, see [PHP / Monolog SDK Stable](../../docs/PHP-Monolog-SDK-Stable.md).
