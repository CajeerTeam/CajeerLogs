"""Durable stdlib logging handler backed by the real CajeerLogs HTTP client."""
from __future__ import annotations

from datetime import datetime, timezone
import logging
import threading

from cajeer_batch_spool import DurableBatchSpool, SpoolBusy


class CajeerLogsBufferedHandler(logging.Handler):
    """Persist every LogRecord before returning, deliver in background batches.

    `emit` performs only local SQLite I/O, never HTTP. `flush` explicitly sends
    pending events and surfaces delivery exceptions; background failures are
    accessible through `last_delivery_error` and `delivery_failures`. A full
    spool is never silently overwritten. Closing the handler stops its worker;
    all unconfirmed records remain on disk if network delivery is impossible.
    """

    def __init__(self, spool: DurableBatchSpool, source: str,
                 service: str | None = None, *, flush_interval: float = 2.0,
                 fail_on_enqueue_error: bool = False):
        super().__init__()
        if not isinstance(spool, DurableBatchSpool):
            raise TypeError('Expected DurableBatchSpool')
        if not 0 <= flush_interval <= 300:
            raise ValueError('flush_interval must be 0 (manual) or 0.05..300 seconds')
        if flush_interval != 0 and flush_interval < 0.05:
            raise ValueError('Invalid flush interval')
        self.spool = spool
        self.source = source
        self.service = service
        self.fail_on_enqueue_error = fail_on_enqueue_error
        self.last_delivery_error: Exception | None = None
        self.last_enqueue_error: Exception | None = None
        self.delivery_failures = 0
        self.enqueue_failures = 0
        self._closing = False
        self._stop = threading.Event()
        self._worker: threading.Thread | None = None
        self._reentry = threading.local()
        if flush_interval:
            self._worker = threading.Thread(target=self._work, args=(flush_interval,),
                                            name='cajeer-logs-flush', daemon=True)
            self._worker.start()

    def emit(self, record: logging.LogRecord):
        if getattr(self._reentry, 'active', False):
            return
        self._reentry.active = True
        try:
            from cajeer_logs_client import record_to_event
            event = record_to_event(record, self.source, self.service, self.format(record))
            self.spool.enqueue(event)
        except Exception as exc:
            self.last_enqueue_error = exc
            self.enqueue_failures += 1
            if self.fail_on_enqueue_error:
                raise
            self.handleError(record)
        finally:
            self._reentry.active = False

    def _work(self, interval: float):
        while not self._stop.wait(interval):
            try:
                self.spool.flush()
                self.last_delivery_error = None
            except SpoolBusy:
                # Another process is doing the delivery; not a transport error.
                continue
            except Exception as exc:
                self.last_delivery_error = exc
                self.delivery_failures += 1

    def flush(self):
        if self._closing:
            return 0
        try:
            result = self.spool.flush()
            self.last_delivery_error = None
            return result
        except Exception as exc:
            self.last_delivery_error = exc
            self.delivery_failures += 1
            raise

    def close(self):
        if self._closing:
            return
        self._closing = True
        self._stop.set()
        if self._worker is not None and threading.current_thread() is not self._worker:
            self._worker.join(timeout=1.0)
        # logging.shutdown() calls flush() before close(); intentionally do not
        # silently perform a second HTTP attempt or discard the durable queue.
        super().close()
