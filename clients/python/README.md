# CajeerLogs Python SDK 0.6.2

Python 3.10–3.13, stdlib-only at runtime. `pip install ./clients/python`. The
package contains the synchronous/async HTTP client, stdlib `logging` handlers,
SQLite durable synchronous batching, async delivery spool, and file collector.

## HTTP client (existing API)

```python
import os
from datetime import datetime, timezone
from cajeer_logs_client import CajeerLogsClient

client = CajeerLogsClient(os.environ['CAJEER_URL'], os.environ['CAJEER_TOKEN'], signed=True)
result = client.ingest({
    'timestamp': datetime.now(timezone.utc).isoformat(timespec='microseconds'),
    'source': 'app', 'level': 'info', 'message': 'Started',
})
print(result['event_ids'])
```

`ingest`, `ingest_async`, `wait_for_job`, `send_body`, `send_async_body` remain
backward compatible. HTTP 201 or an authenticated, validated idempotent HTTP 200
receipt confirms synchronous storage. HTTP 202 is **only** an async job
admission; use `wait_for_job` to confirm actual completion. Bounded HTTP retries
reuse exact JSON bytes and idempotency key and re-sign with a fresh HMAC nonce.
Redirects are never followed, HTTPS is verified, and remote plaintext HTTP is
rejected by default. `DeliveryError`, `JobPending` and `JobFailed` are explicit.

## Durable `logging` with batching (new in 0.6.2)

```python
import logging
import os
from cajeer_logs_client import CajeerLogsClient
from cajeer_batch_spool import DurableBatchSpool
from cajeer_logging import CajeerLogsBufferedHandler

client = CajeerLogsClient(os.environ['CAJEER_URL'], os.environ['CAJEER_TOKEN'], signed=True)
# Directory must be private (0700) on Unix; data and locks must be private (0600).
spool = DurableBatchSpool('/var/lib/myapp/cajeer/outbox.sqlite', client,
                          max_events=10000, max_bytes=64*1024*1024,
                          batch_events=100, batch_bytes=1048576)
handler = CajeerLogsBufferedHandler(spool, source='app', service='backend',
                                    flush_interval=2.0, fail_on_enqueue_error=True)
logger = logging.getLogger('myapp')
logger.addHandler(handler)
logger.setLevel(logging.INFO)
logger.info('User %s signed in', 42, extra={'tenant': 'alpha'})

# During controlled shutdown: stop producer, drain, close, then close spool.
logger.removeHandler(handler)
handler.close()
try:
    spool.flush()  # Raises on failure and retains all unconfirmed data.
finally:
    spool.close()
```

`emit` commits each event to SQLite before returning. The background worker
flushes grouped events; it does not log its own failures. Inspect
`spool.status()`, `handler.last_delivery_error`, `handler.delivery_failures`,
`handler.last_enqueue_error`, `handler.enqueue_failures`. Set
`fail_on_enqueue_error=True` to propagate full/disk/validation errors; otherwise
stdlib `logging.handleError` is used. **Do not treat logging's default failure
handling as guaranteed acceptance.** `flush_interval=0` disables background
network activity and requires explicit flushing or the CLI. Closing the handler
stops its worker but does not delete the durable spool or guarantee remote ACK.

For unattended recovery, run the following with the **same endpoint, token,
signing settings and spool path** as the original process:

```sh
export CAJEER_URL=https://logs.example.com
export CAJEER_TOKEN=... # use secret manager, not CLI args
cajeer-logs-spool status --spool /var/lib/myapp/cajeer/outbox.sqlite --signed
cajeer-logs-spool flush --spool /var/lib/myapp/cajeer/outbox.sqlite --signed
```

Two producers can share a spool safely via SQLite transactions. Multiple
flushers are serialized across processes by a private advisory lock; a competing
flusher receives `SpoolBusy`. First attempts are recorded durably before HTTP.
After loss of a response, the original key/body are replayed, **never** rebuilt.
Once an ambiguous attempt exceeds the configurable safe replay window (23 hours
by default) the queue fails closed with `SpoolExpired`, requiring manual
reconciliation with the server. **Exactly once is not guaranteed** beyond the
server's idempotency retention. A non-retryable HTTP status (e.g. 401) raises
without dropping the batch. Rejected enqueues never count as accepted.
Do not use `enqueue` for an `{'events': [...]}` wrapper; it accepts a single
validated event per call. Batches are created automatically.

Use an app-controlled writable directory with sufficient disk space and
restricted access. Keep spool files across deploys and don't put the spool in
a repository, image layer, or container-ephemeral filesystem. For high-volume
apps, configure an operational alarm on `queued_events`, `queued_bytes` and
repeated delivery failures. Spool files may contain sensitive log context.

## Existing file collector and async durable spool

```sh
export CAJEER_URL=https://logs.example.com
# CAJEER_TOKEN must be injected via secret management.
cajeer-logs-collector --file /var/log/myapp/app.jsonl --state /var/lib/myapp/cajeer-collector.sqlite \
  --source app --format jsonl --signed
```

`cajeer_delivery_spool.DurableAsyncSpool` is still available for asynchronous
admission plus verified job-completion polling (Unix/Linux, `fcntl`). The file
collector supports `--async` with checkpoint-on-completion semantics and log
rotation handling. The new `DurableBatchSpool` is portable across Unix and
Windows when the respective OS file-locking APIs are available. For private
certificate authorities use `ca_file='/path/to/ca.pem'`; hostname verification
remains enabled. See [SDK Delivery Recovery](../../docs/SDK-Delivery-Recovery.md).
