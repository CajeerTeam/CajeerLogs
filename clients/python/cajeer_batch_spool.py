"""Durable, bounded synchronous ingestion for CajeerLogs Python applications.

Events are committed to a private SQLite outbox before a producer succeeds.  A
batch is assembled and committed with immutable wire bytes and idempotency key
*before* its first HTTP request.  A lost receipt is replayed only within the
configured safe idempotency window.  Flushers are serialized across processes;
producers can concurrently enqueue through SQLite transactions.
"""
from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import secrets
import sqlite3
import stat
import threading
import time
from typing import Any

from cajeer_logs_client import CajeerLogsClient, encode, validate_event


class SpoolError(RuntimeError):
    """The outbox cannot safely accept or deliver events."""


class SpoolFull(SpoolError):
    """No new event was persisted: the configured outbox capacity is full."""


class SpoolBusy(SpoolError):
    """Another process is currently flushing the same outbox."""


class SpoolExpired(SpoolError):
    """The first send is older than the permitted automatic-replay window."""


class _ProcessFlushLock:
    """Portable, process-wide advisory lock; SQLite still protects producers."""
    def __init__(self, path: Path):
        flags = os.O_RDWR | os.O_CREAT | getattr(os, 'O_NOFOLLOW', 0)
        fd = os.open(path, flags, 0o600)
        try:
            info = os.fstat(fd)
            if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or
                    (os.name == 'posix' and (info.st_uid != os.geteuid() or info.st_mode & 0o077))):
                raise SpoolError('Unsafe flush lock file')
            self._fd = fd
            self._held = False
        except BaseException:
            os.close(fd)
            raise

    def acquire(self):
        if os.name == 'nt':
            import msvcrt
            os.lseek(self._fd, 0, os.SEEK_SET)
            try:
                msvcrt.locking(self._fd, msvcrt.LK_NBLCK, 1)
            except OSError as exc:
                raise SpoolBusy('Another flush is in progress') from exc
        else:
            import fcntl
            try:
                fcntl.flock(self._fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError as exc:
                raise SpoolBusy('Another flush is in progress') from exc
        self._held = True

    def release(self):
        if not self._held:
            return
        if os.name == 'nt':
            import msvcrt
            os.lseek(self._fd, 0, os.SEEK_SET)
            msvcrt.locking(self._fd, msvcrt.LK_UNLCK, 1)
        else:
            import fcntl
            fcntl.flock(self._fd, fcntl.LOCK_UN)
        self._held = False

    def close(self):
        try:
            self.release()
        finally:
            os.close(self._fd)


class DurableBatchSpool:
    """SQLite-backed producer queue and synchronous bulk flusher.

    A producer returns only after its event is committed.  `flush()` returns
    the number of events acknowledged by CajeerLogs (201 or verified 200
    replay). Delivery exceptions never remove unconfirmed bytes.  This is
    at-least-once delivery with server-side idempotency, not exactly-once across
    an expired server receipt retention window.
    """

    def __init__(self, path: str | Path, client: CajeerLogsClient, *,
                 max_events: int = 10000, max_bytes: int = 64 * 1024 * 1024,
                 batch_events: int = 100, batch_bytes: int = 1048576,
                 replay_window_seconds: int = 23 * 3600):
        if (not isinstance(client, CajeerLogsClient) or type(max_events) is not int or
                not 1 <= max_events <= 1_000_000 or type(max_bytes) is not int or
                not 65536 <= max_bytes <= 1073741824 or type(batch_events) is not int or
                not 1 <= batch_events <= 100 or type(batch_bytes) is not int or
                not 65536 <= batch_bytes <= 1048576 or type(replay_window_seconds) is not int or
                not 60 <= replay_window_seconds <= 23 * 3600):
            raise ValueError('Invalid durable batching configuration')
        path = Path(path).absolute()
        path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        parent = path.parent.stat()
        if (not stat.S_ISDIR(parent.st_mode) or path.parent.is_symlink() or
                (os.name == 'posix' and (parent.st_uid != os.geteuid() or parent.st_mode & 0o077))):
            raise SpoolError('Spool directory must be owned and private (0700)')
        self.client = client
        self.max_events, self.max_bytes = max_events, max_bytes
        self.batch_events, self.batch_bytes = batch_events, batch_bytes
        self.replay_window_seconds = replay_window_seconds
        self._mutex = threading.RLock()
        self._flushing = threading.Lock()
        self._closed = False
        self.db: sqlite3.Connection | None = None
        self._flush_lock: _ProcessFlushLock | None = None
        try:
            flags = os.O_CREAT | os.O_RDWR | getattr(os, 'O_NOFOLLOW', 0)
            fd = os.open(path, flags, 0o600)
            try:
                self._private_regular(fd, 'spool database')
            finally:
                os.close(fd)
            self._flush_lock = _ProcessFlushLock(Path(str(path) + '.flush.lock'))
            self.db = sqlite3.connect(path, timeout=10, isolation_level=None, check_same_thread=False)
            self.db.execute('PRAGMA busy_timeout=10000')
            self.db.execute('PRAGMA journal_mode=DELETE')
            self.db.execute('PRAGMA synchronous=FULL')
            self.db.execute('PRAGMA foreign_keys=ON')
            self.db.execute('CREATE TABLE IF NOT EXISTS identity (id INTEGER PRIMARY KEY CHECK(id=1), digest TEXT NOT NULL)')
            self.db.execute('''CREATE TABLE IF NOT EXISTS entries (
                seq INTEGER PRIMARY KEY AUTOINCREMENT, body BLOB NOT NULL, created_at REAL NOT NULL)''')
            self.db.execute('''CREATE TABLE IF NOT EXISTS batches (
                seq INTEGER PRIMARY KEY AUTOINCREMENT, key TEXT NOT NULL UNIQUE,
                body BLOB NOT NULL, body_sha256 TEXT NOT NULL, event_count INTEGER NOT NULL,
                created_at REAL NOT NULL, first_sent_at REAL)''')
            self.db.execute('''CREATE TABLE IF NOT EXISTS counters (
                id INTEGER PRIMARY KEY CHECK(id=1), delivered_events INTEGER NOT NULL DEFAULT 0,
                delivered_batches INTEGER NOT NULL DEFAULT 0,
                queued_events INTEGER NOT NULL DEFAULT 0 CHECK(queued_events>=0),
                queued_bytes INTEGER NOT NULL DEFAULT 0 CHECK(queued_bytes>=0))''')
            digest = hashlib.sha256(json.dumps([
                client.base_url.rstrip('/'), client.api_prefix,
                hashlib.sha256(client.token.encode('utf-8')).hexdigest(),
                client.signing_key_id,
                hashlib.sha256((client.hmac_secret or '').encode()).hexdigest(),
                client.signed,
            ], separators=(',', ':')).encode()).hexdigest()
            with self.db:
                self.db.execute('INSERT OR IGNORE INTO identity VALUES (1,?)', (digest,))
                self.db.execute('INSERT OR IGNORE INTO counters(id) VALUES (1)')
            if self.db.execute('SELECT digest FROM identity WHERE id=1').fetchone() != (digest,):
                raise SpoolError('Spool is bound to a different endpoint or signing identity')
            self._check_integrity()
        except BaseException:
            self.close()
            raise

    @staticmethod
    def _private_regular(fd: int, name: str):
        st = os.fstat(fd)
        if (not stat.S_ISREG(st.st_mode) or st.st_nlink != 1 or
                (os.name == 'posix' and (st.st_uid != os.geteuid() or st.st_mode & 0o077))):
            raise SpoolError(f'Unsafe {name}: expected private regular file')

    def _check_integrity(self):
        # Hold one consistent SQLite read snapshot, even while other processes
        # enqueue. Without this, two independent SELECTs can cross a COMMIT.
        with self._mutex:
            self.db.execute('BEGIN IMMEDIATE')
            try:
                if self.db.execute('PRAGMA quick_check').fetchone() != ('ok',):
                    raise SpoolError('Spool database failed SQLite integrity check')
                actual = self.db.execute('''SELECT (SELECT count(*) FROM entries)+COALESCE((SELECT sum(event_count) FROM batches),0),
                    (SELECT COALESCE(sum(length(body)),0) FROM entries)+(SELECT COALESCE(sum(length(body)),0) FROM batches)''').fetchone()
                if self.db.execute('SELECT queued_events,queued_bytes FROM counters WHERE id=1').fetchone() != actual:
                    raise SpoolError('Queue counters inconsistent with persisted events')
                for key, body, expected, count in self.db.execute(
                        'SELECT key,body,body_sha256,event_count FROM batches'):
                    if (not isinstance(body, bytes) or hashlib.sha256(body).hexdigest() != expected
                            or not 1 <= count <= 100 or not key):
                        raise SpoolError('Corrupt persisted batch; manual recovery required')
                self.db.commit()
            except BaseException:
                self.db.rollback()
                raise

    def close(self):
        with self._flushing, self._mutex:
            if self._closed:
                return
            self._closed = True
            if self.db is not None:
                self.db.close()
                self.db = None
            if self._flush_lock is not None:
                self._flush_lock.close()
                self._flush_lock = None

    def __enter__(self):
        return self

    def __exit__(self, *_):
        self.close()

    def enqueue(self, event: dict[str, Any]) -> int:
        """Persist one event before returning its local sequence number."""
        validate_event(event)
        body = encode(event)
        if len(body) + len(b'{"events":[]}') > self.batch_bytes:
            raise ValueError('Single event cannot fit configured batch_bytes')
        with self._mutex:
            db = self._db()
            db.execute('BEGIN IMMEDIATE')
            try:
                count, total = db.execute('SELECT queued_events,queued_bytes FROM counters WHERE id=1').fetchone()
                if count + 1 > self.max_events or total + len(body) + 16 * (count + 1) > self.max_bytes:
                    raise SpoolFull('Durable outbox capacity exhausted; event was not persisted')
                seq = db.execute('INSERT INTO entries(body,created_at) VALUES (?,?)',
                                 (body, time.time())).lastrowid
                db.execute('UPDATE counters SET queued_events=queued_events+1, queued_bytes=queued_bytes+? WHERE id=1', (len(body),))
                db.commit()
                return seq
            except BaseException:
                db.rollback()
                raise

    def _db(self) -> sqlite3.Connection:
        if self._closed or self.db is None:
            raise SpoolError('Durable spool is closed')
        return self.db

    def status(self) -> dict[str, int]:
        with self._mutex:
            db = self._db()
            pending = db.execute('SELECT count(*) FROM batches').fetchone()[0]
            queued, size, delivered, finished = db.execute(
                'SELECT queued_events,queued_bytes,delivered_events,delivered_batches FROM counters WHERE id=1').fetchone()
            return dict(queued_events=queued, pending_batches=pending,
                        queued_bytes=size, delivered_events=delivered, delivered_batches=finished)

    def _next_batch(self) -> tuple[int, str, bytes, str, int, float | None] | None:
        with self._mutex:
            db = self._db()
            db.execute('BEGIN IMMEDIATE')
            try:
                row = db.execute('''SELECT seq,key,body,body_sha256,event_count,first_sent_at
                    FROM batches ORDER BY seq LIMIT 1''').fetchone()
                if row is not None:
                    db.commit()
                    return row
                events = []
                ids = []
                total = len(b'{"events":[]}')
                for seq, raw in db.execute('SELECT seq,body FROM entries ORDER BY seq LIMIT ?',
                                           (self.batch_events,)):
                    if not isinstance(raw, bytes) or not raw.startswith(b'{'):
                        raise SpoolError('Corrupt persisted event')
                    proposed = total + len(raw) + (1 if ids else 0)
                    if proposed > self.batch_bytes:
                        if not ids:
                            raise SpoolError('First event cannot fit configured batch limit')
                        break
                    ids.append(seq)
                    events.append(raw)
                    total = proposed
                if not ids:
                    db.commit()
                    return None
                body = b'{"events":[' + b','.join(events) + b']}'
                key = secrets.token_hex(16)
                now = time.time()
                h = hashlib.sha256(body).hexdigest()
                seq = db.execute('''INSERT INTO batches(key,body,body_sha256,event_count,created_at)
                    VALUES (?,?,?,?,?)''', (key,body,h,len(ids),now)).lastrowid
                db.executemany('DELETE FROM entries WHERE seq=?', [(i,) for i in ids])
                db.execute('UPDATE counters SET queued_bytes=queued_bytes+? WHERE id=1',
                           (len(body)-sum(len(item) for item in events),))
                db.commit()
                return seq,key,body,h,len(ids),None
            except BaseException:
                db.rollback()
                raise

    def flush(self, *, max_batches: int | None = None) -> int:
        """Deliver queued events. On failure leave exact body/key for next flush."""
        if max_batches is not None and (type(max_batches) is not int or max_batches < 1):
            raise ValueError('max_batches must be a positive integer or None')
        if not self._flushing.acquire(blocking=False):
            raise SpoolBusy('This process is already flushing the spool')
        acquired = False
        delivered = 0
        batches = 0
        try:
            with self._mutex:
                self._db()
                self._flush_lock.acquire()
                acquired = True
            while max_batches is None or batches < max_batches:
                row = self._next_batch()
                if row is None:
                    break
                seq, key, body, expected, count, first = row
                if (hashlib.sha256(body).hexdigest() != expected or len(body) > 1048576
                        or not 1 <= count <= 100):
                    raise SpoolError('Corrupted batch; delivery blocked')
                now = time.time()
                if first is not None and (now - first >= self.replay_window_seconds or now < first - 60):
                    raise SpoolExpired('Unconfirmed batch exceeded safe replay window; reconcile manually')
                if first is None:
                    with self._mutex:
                        db = self._db()
                        db.execute('BEGIN IMMEDIATE')
                        try:
                            db.execute('UPDATE batches SET first_sent_at=? WHERE seq=? AND first_sent_at IS NULL', (now, seq))
                            db.commit()
                        except BaseException:
                            db.rollback()
                            raise
                # No SQLite transaction is held during network I/O.
                receipt = self.client.send_body(body, key)
                if receipt.get('received') != count:
                    raise SpoolError('Ingest receipt count does not match durable batch')
                with self._mutex:
                    db = self._db()
                    db.execute('BEGIN IMMEDIATE')
                    try:
                        changed = db.execute('DELETE FROM batches WHERE seq=? AND key=? AND body_sha256=?',
                                             (seq,key,expected)).rowcount
                        if changed != 1:
                            raise SpoolError('Batch changed during delivery')
                        db.execute('UPDATE counters SET queued_events=queued_events-?, queued_bytes=queued_bytes-?, delivered_events=delivered_events+?, delivered_batches=delivered_batches+1 WHERE id=1', (count,len(body),count))
                        db.commit()
                    except BaseException:
                        db.rollback()
                        raise
                delivered += count
                batches += 1
            return delivered
        finally:
            try:
                if acquired:
                    with self._mutex:
                        self._flush_lock.release()
            finally:
                self._flushing.release()
