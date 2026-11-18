# CajeerLogs 0.6.1 — PHP / Monolog SDK Stable

## Scope

The implementation extends the existing `packages/php-sdk` PHP 8.4 PSR-3 and Monolog 3 clients; the previous synchronous constructor behavior and HMAC HTTP protocol are unchanged. `DurableBatchDelivery` is a real opt-in spool and batching transport, not an HTTP mock or a configuration-only adapter. It uses `POST /api/v1/ingest`, validates the API's event count, idempotency receipts and successful commit results, and reuses the existing HTTPS/HMAC transport.

## Operational model

1. `enqueue(array $event)` validates fields and event size, then writes canonical JSON to `ready/` using a unique private file, `fflush`/`fsync` and atomic rename. The enqueue call completes **only after** the event file is committed. Rejected/oversized events do not consume spool capacity.
2. At `batchSize` unbatched events (or explicit `flush()`), an exclusive state lock selects up to 100 events and <= 1 MiB of JSON. It atomically persists an immutable envelope with the exact batch JSON, SHA-256, event IDs and idempotency key under `batches/` **before issuing HTTP**. An independent flush lock serializes network delivery across processes, without locking out new producers during network IO.
3. Once the batch envelope is committed, its source event files are removed. If a process crashes between these steps, the next process removes leftover duplicates, favouring the frozen batch. If HTTP commits but the client dies before removing the envelope, the same body/key are retried; server-side idempotency controls the duplicate.
4. The batch is deleted **only after** a validated HTTP 200/201 receipt. 401, 429, 503, malformed results, TLS failure, timeouts and missing receipts cannot erase events. `flush()` returns an exact count of confirmed events. Broken disks, unsafe spool permissions, and exceeded capacity produce an exception instead of silently losing data.
5. In fail-closed mode (`spoolOnDeliveryFailure=false`), delivery failure raises `DeliveryException`; in spool-on-failure mode it leaves data queued and exposes `lastFailure()` for observability. Other unexpected exceptions always propagate; no transport error is recorded by the same SDK logger (recursion guard in Monolog handler).

## Explicit guarantees and limits

- `batchSize`: 1..100; `maxBatchBytes`: 64 KiB..1 MiB; `maxPendingEvents`: `batchSize`..100000. A single event larger than the configured batch limit is rejected before enqueuing.
- Queue files and lock files: 0600, spool directories: 0700. Must be in private persistent storage, not a web-served path or ephemeral writable container layer. The SDK rejects symlinked spool roots/entries and insecure permissions on POSIX systems. Files can include sensitive log context.
- File locks allow concurrent PHP producers; batches are delivered serially per spool to avoid duplicate in-flight delivery. Use a dedicated spool per application or process group and avoid unreliable network filesystems without correct flock/fsync/rename semantics.
- Only a **validated successful HTTP response** removes an envelope; durable pending is not proof that the server has stored events. Delivery is at-least-once. Exactly-once results depend on the server's idempotency retention; a replay after that window may store events twice.
- File `fsync` before rename reduces crash loss, but PHP cannot portably fsync directory entries on every platform. Storage may lose the most recent metadata on sudden power loss. Do not claim a full-fsync durability guarantee on unsupported filesystems.
- Size limits affect actual UTF-8 JSON bytes; packets split rather than breaching the API's 1 MiB request limit. No automatic timer exists: call `flush()` periodically or from a controlled shutdown path; `MonologHandler::close()` flushes pending batches.
- `maxPendingEvents` is a hard limit that protects disk utilization by event count; disk size still requires filesystem monitoring. Delivery failures and backlog are observable via `pendingEvents()`/`lastFailure()`. Never point this logger at its own transport errors.

## Install and use

Use PHP 8.4+ and Composer `psr/log:^3.0`, with `monolog/monolog:^3.0` for Monolog. Install `cajeer/logs-extension-sdk:0.6.1` from the matching path repository or release distribution. Existing synchronous integrations need no code changes. To activate durable batching:

```php
$client = new \Cajeer\Logs\Sdk\CajeerLogsClient($url, $token, signed: true);
$delivery = new \Cajeer\Logs\Sdk\DurableBatchDelivery(
    $client, '/var/lib/my-app/private-cajeer-spool', batchSize: 25,
    spoolOnDeliveryFailure: true,
);
$logger = new \Cajeer\Logs\Sdk\Logger($client, 'app', 'backend', $delivery);
$logger->info('Processed {count}', ['count' => 1]);
$confirmed = $logger->flush();
$pending = $delivery->pendingEvents();
$lastFailure = $delivery->lastFailure();
```

For Monolog 3, pass `delivery: $delivery` to `MonologHandler`. Multiple handlers may share one `DurableBatchDelivery` instance. `Logger::flush`, `MonologHandler::flush`, and `MonologHandler::close` use the same sender.

## Tests and deployment gate

```sh
php -l packages/php-sdk/src/DurableBatchDelivery.php
python3 tests/sdk_buffered_wire.py
python3 tests/sdk_wire.py
# Real Monolog 3 and PSR-3 adapter cases are enabled after Composer dependencies are installed:
composer install --no-interaction --prefer-dist
python3 tests/sdk_buffered_wire.py
```

`tests/sdk_buffered_wire.py` uses a genuine loopback HTTP listener, checks HMAC and raw request identity, and exercises process restarts, a lost response after successful ingest, concurrent producer processes, 503 outage/recovery, permanent 401 retention, spool capacity, invalid events and splitting under byte limits. The adapter test instantiates real Composer PSR-3 and Monolog 3 classes when `vendor/autoload.php` exists. CI runs it after Composer installation, making adapter coverage mandatory there. The release gate additionally enforces PHP 8.4/8.5, PostgreSQL 17/18 and the existing full async load certification requirements.

For production acceptance, validate crash recovery on the actual filesystem, server-side idempotency retention, TLS CA trust, permissions and disk-full handling, and measure throughput under realistic traffic. A passing loopback test is not a substitute for prolonged external system load tests or independent certification.

## Upgrade from 0.6.0

Overlay the changed files while retaining installation secrets and storage; no DB migrations were added. Update the SDK Composer dependency to `0.6.1`. Optional batching creates its own new persistent storage directory and does not change any existing synchronous log paths. Existing backup manifests from `0.6.0` remain supported. Run the release and API checks before restarting PHP workers.
