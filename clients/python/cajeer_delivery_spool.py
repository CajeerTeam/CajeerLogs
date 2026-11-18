"""Durable opt-in Python async outbox. Zero third-party dependencies.

The SQLite transaction commits exact encoded bytes and idempotency key before HTTP.
HTTP 202 is NOT completion: the body is released only after a verified completed
job with the same event count. Never automatically retry dead/cancelled jobs.
One active process per spool is enforced using a private POSIX flock.
"""
from __future__ import annotations

import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import sqlite3
import stat
import time
from typing import Any

from cajeer_logs_client import CajeerLogsClient, JobPending, JobFailed, encode


class SpoolError(RuntimeError):
    """Unsafe or inconsistent local durable-delivery state."""


class SpoolFull(SpoolError):
    """The bounded local outbox is full. No event was queued."""


class DurableAsyncSpool:
    def __init__(self, path: Path, client: CajeerLogsClient, *, max_jobs: int = 1000, max_bytes: int = 64 * 1024 * 1024):
        if not isinstance(max_jobs, int) or not 1 <= max_jobs <= 100000 or not isinstance(max_bytes, int) or not 1048576 <= max_bytes <= 1073741824:
            raise ValueError('Invalid spool limits')
        self.client, self.max_jobs, self.max_bytes = client, max_jobs, max_bytes
        path = Path(path).absolute()
        path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        parent = path.parent.stat()
        if parent.st_uid != os.geteuid() or parent.st_mode & 0o077 or not stat.S_ISDIR(parent.st_mode):
            raise SpoolError('Spool directory must be owned and private')
        self._lock = None
        self.db = None
        try:
            fd = os.open(str(path) + '.lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
            self._lock = os.fdopen(fd, 'r+b')
            st = os.fstat(fd)
            if not stat.S_ISREG(st.st_mode) or st.st_nlink != 1 or st.st_uid != os.geteuid() or st.st_mode & 0o077:
                raise SpoolError('Unsafe spool lock')
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            fd = os.open(path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
            try:
                st = os.fstat(fd)
                if not stat.S_ISREG(st.st_mode) or st.st_nlink != 1 or st.st_uid != os.geteuid() or st.st_mode & 0o077:
                    raise SpoolError('Spool database must be a private regular file')
            finally:
                os.close(fd)
            self.db = sqlite3.connect(path, timeout=0, isolation_level=None)
            self.db.execute('PRAGMA synchronous=FULL')
            self.db.execute('PRAGMA journal_mode=DELETE')
            self.db.execute('PRAGMA foreign_keys=ON')
            self.db.execute('CREATE TABLE IF NOT EXISTS identity (id INTEGER PRIMARY KEY CHECK(id=1), digest TEXT NOT NULL)')
            self.db.execute('''CREATE TABLE IF NOT EXISTS deliveries (
                seq INTEGER PRIMARY KEY AUTOINCREMENT, key TEXT NOT NULL UNIQUE,
                body BLOB, body_hash TEXT NOT NULL, event_count INTEGER NOT NULL CHECK(event_count BETWEEN 1 AND 100),
                created_at REAL NOT NULL, first_sent_at REAL, job_id TEXT,
                state TEXT NOT NULL CHECK(state IN ('pending','completed','dead','cancelled')),
                finished_at REAL, result_ids TEXT)''')
            fingerprint = hashlib.sha256(json.dumps([client.base_url.rstrip('/'),client.api_prefix,hashlib.sha256(client.token.encode()).hexdigest()],separators=(',',':')).encode()).hexdigest()
            with self.db:
                self.db.execute('INSERT OR IGNORE INTO identity VALUES(1,?)',(fingerprint,))
            if self.db.execute('SELECT digest FROM identity WHERE id=1').fetchone() != (fingerprint,):
                raise SpoolError('Spool is bound to a different endpoint or credential')
        except BaseException:
            self.close()
            raise

    def close(self):
        if self.db is not None:
            self.db.close();self.db = None
        if self._lock is not None:
            self._lock.close();self._lock = None

    def __enter__(self): return self
    def __exit__(self, *_): self.close()

    def enqueue(self, events: dict[str, Any] | list[dict[str, Any]], *, idempotency_key: str | None = None) -> str:
        body = encode(events)
        key = idempotency_key or secrets.token_hex(16)
        if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._:-]{0,127}',key):
            raise ValueError('Invalid idempotency key')
        count = len(events) if isinstance(events,list) else len(events['events']) if 'events' in events else 1
        self.db.execute('BEGIN IMMEDIATE')
        try:
            # Key reuse is not a new enqueue, even after confirmed completion.
            previous = self.db.execute('SELECT body_hash, event_count FROM deliveries WHERE key=?',(key,)).fetchone()
            if previous is not None:
                if previous[0] != hashlib.sha256(body).hexdigest() or previous[1] != count:
                    raise SpoolError('Idempotency key was used for a different delivery')
                self.db.commit();return key
            jobs, size = self.db.execute("SELECT count(*),COALESCE(sum(length(body)),0) FROM deliveries WHERE state='pending'").fetchone()
            if jobs >= self.max_jobs or size + len(body) > self.max_bytes:
                raise SpoolFull('Pending spool capacity exhausted')
            self.db.execute("INSERT INTO deliveries(key,body,body_hash,event_count,created_at,state) VALUES (?,?,?,?,?,'pending')",(key,body,hashlib.sha256(body).hexdigest(),count,time.time()))
            self.db.commit()
            return key
        except BaseException:
            self.db.rollback();raise

    def status(self) -> dict[str, int]:
        counts = {state: 0 for state in ('pending','completed','dead','cancelled')}
        for state, n in self.db.execute('SELECT state,count(*) FROM deliveries GROUP BY state'):
            counts[state] = n
        counts['pending_bytes'] = self.db.execute("SELECT COALESCE(sum(length(body)),0) FROM deliveries WHERE state='pending'").fetchone()[0]
        return counts

    def flush_one(self, *, timeout: float = 30, poll_interval: float = .5) -> bool:
        """Process one pending delivery. False means none; True means committed.

        A JobPending/network error retains the full original bytes and key. The next
        invocation resumes the same job ID when known, otherwise replays the key.
        """
        row = self.db.execute("SELECT seq,key,body,event_count,first_sent_at,job_id FROM deliveries WHERE state='pending' ORDER BY seq LIMIT 1").fetchone()
        if row is None: return False
        seq, key, body, count, sent, job_id = row
        if not isinstance(body, bytes) or len(body) == 0 or not 1 <= count <= 100:
            raise SpoolError('Corrupted pending delivery')
        # Server idempotency receipts have finite retention. Once an ambiguous
        # first HTTP attempt ages out, replay cannot be proven safe. Fail closed.
        if job_id is None and sent is not None and (time.time() - sent >= 23*3600 or time.time() < sent-60):
            raise SpoolError('Ambiguous delivery passed safe replay window; reconcile manually')
        if job_id is None:
            if sent is None:
                with self.db:
                    self.db.execute('UPDATE deliveries SET first_sent_at=? WHERE seq=? AND first_sent_at IS NULL',(time.time(),seq))
            receipt = self.client.send_async_body(body,key)
            job_id = receipt['job_id']
            with self.db:
                self.db.execute("UPDATE deliveries SET job_id=? WHERE seq=? AND state='pending'",(job_id,seq))
        try:
            result = self.client.wait_for_job({'job_id':job_id,'received':count},timeout=timeout,poll_interval=poll_interval)
        except JobFailed as exc:
            with self.db:
                self.db.execute("UPDATE deliveries SET state=?,finished_at=? WHERE seq=? AND state='pending'",(exc.status,time.time(),seq))
            raise
        ids = result['result']['event_ids']
        with self.db:
            # Drop raw body only after confirmed PostgreSQL completed state.
            self.db.execute("UPDATE deliveries SET state='completed',body=NULL,finished_at=?,result_ids=? WHERE seq=? AND state='pending'",(time.time(),json.dumps(ids),seq))
        return True

    def prune_completed(self, *, keep_last: int = 1000) -> int:
        if not 0 <= keep_last <= 100000: raise ValueError('Invalid retention limit')
        with self.db:
            rows = self.db.execute("SELECT seq FROM deliveries WHERE state='completed' ORDER BY seq DESC LIMIT -1 OFFSET ?",(keep_last,)).fetchall()
            for (seq,) in rows: self.db.execute("DELETE FROM deliveries WHERE seq=? AND state='completed'",(seq,))
        return len(rows)
