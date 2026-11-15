#!/usr/bin/env python3
"""Durable, single-file log collector for Linux/Unix. One state DB per input."""
from __future__ import annotations
import argparse
import fcntl
import glob
import hashlib
import json
import os
import re
import secrets
import signal
import sqlite3
import stat
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from zoneinfo import ZoneInfo
from cajeer_logs_client import CajeerLogsClient, encode, validate_event

ACCESS = re.compile(r'^(\S+) \S+ \S+ \[([^]]+)\] "([^"\r\n]*)" (\d{3}) (\d+|-)(?: "([^"\r\n]*)" "([^"\r\n]*)")?$')
ERROR = re.compile(r'^(\d{4}/\d{2}/\d{2} \d{2}:\d{2}:\d{2}) \[([a-z]+)\] (.*)$')
LEVEL = {'warn': 'warning', 'emerg': 'emergency', 'crit': 'critical'}


def parse_line(raw: bytes, fmt: str, source: str, zone: ZoneInfo, service: str | None = None) -> dict:
    text = raw.decode('utf-8').rstrip('\r\n')
    if not text.strip() or '\0' in text:
        raise ValueError('Empty or unsupported log line')
    event = {'source': source, 'timestamp': datetime.now(timezone.utc).isoformat(timespec='microseconds'), 'level': 'info', 'message': text}
    if service:
        event['service'] = service
    if fmt in ('jsonl', 'cajeer-jsonl'):
        def object_pairs(pairs):
            result = {}
            for key, value in pairs:
                if key in result: raise ValueError("Duplicate JSON key")
                result[key] = value
            return result
        def invalid_constant(_): raise ValueError("Unsupported JSON number")
        from decimal import Decimal
        def decimal_value(raw):
            raw = str(raw)
            value = float(raw)
            from math import isfinite
            if not isfinite(value): raise ValueError("Unsupported JSON number")
            digits = re.split("[eE]",raw,1)[0].replace("-","").replace(".","").strip("0")
            return raw if len(digits)>15 or (value==0 and digits) else value
        data = json.loads(text,object_pairs_hook=object_pairs,parse_constant=invalid_constant,parse_float=Decimal)
        if not isinstance(data, dict):
            raise ValueError('JSONL line must be an object')
        if fmt == 'cajeer-jsonl':
            # Explicit single-source archive: never silently merge distinct sources.
            if data.get('source') != source:
                raise ValueError('Archive source does not match configured source')
            # Archive metadata belongs to the old server; routing and IDs are new.
            data = {k: v for k, v in data.items() if k in ('timestamp','level','message','context','service','host','trace_id','request_id','tags')}
            if not {'timestamp','level','message'} <= data.keys():
                raise ValueError('Invalid Cajeer archive event')
        def context_numbers(value):
            if isinstance(value, Decimal): return decimal_value(value)
            if isinstance(value, list): return [context_numbers(v) for v in value]
            if isinstance(value, dict): return {k:context_numbers(v) for k,v in value.items()}
            return value
        if 'context' in data: data['context']=context_numbers(data['context'])
        event.update(data)
        event['source'] = source  # never trust input routing
    elif fmt == 'nginx-access':
        m = ACCESS.fullmatch(text)
        if not m:
            raise ValueError('Unsupported Nginx access format (use combined/common)')
        ip, stamp, request, status, size, referer, agent = m.groups()
        # Do not forward URL query/fragment, referrer or user agent; may contain secrets.
        pieces = request.split(' ')
        if len(pieces) != 3:
            raise ValueError('Invalid Nginx request line')
        method, target, protocol = pieces
        target = target.split('?', 1)[0].split('#', 1)[0]
        code = int(status)
        event.update(timestamp=datetime.strptime(stamp[:3] + str(('Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec').index(stamp[3:6])+1).zfill(2) + stamp[6:], '%d/%m/%Y:%H:%M:%S %z').astimezone(timezone.utc).isoformat(),
                     level='error' if code >= 500 else 'warning' if code >= 400 else 'info',
                     message=f'{method} {target} {protocol} {code}', context={'remote_addr': ip, 'method': method, 'path': target, 'status': code, 'bytes_sent': None if size == '-' else int(size)})
    elif fmt == 'nginx-error':
        m = ERROR.fullmatch(text)
        if not m:
            raise ValueError('Unsupported Nginx error format')
        stamp, level, message = m.groups()
        event.update(timestamp=datetime.strptime(stamp, '%Y/%m/%d %H:%M:%S').replace(tzinfo=zone).astimezone(timezone.utc).isoformat(), level=LEVEL.get(level, level), message=message)
    validate_event(event)
    return event


class CollectorError(ValueError):
    """Safe operational diagnostic; never includes source contents or credentials."""


class Collector:
    def __init__(self, path: Path, state: Path, client: CajeerLogsClient, source: str, fmt: str,
                 zone: ZoneInfo, service: str | None = None, rotated_glob: str | None = None, batch: int = 50):
        self.path, self.client, self.source, self.fmt, self.zone, self.service = path.absolute(), client, source, fmt, zone, service
        self.rotated_glob, self.batch = rotated_glob, batch
        if not re.fullmatch(r'[a-z0-9]+(?:-[a-z0-9]+)*', source) or not 1 <= batch <= 100:
            raise CollectorError('Invalid source or batch size')
        # Keep pending raw logs private. Refuse symlinks and pre-existing unsafe state files.
        state = state.absolute()
        self.state = state
        state.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        parent = state.parent.stat()
        if parent.st_uid != os.geteuid() or parent.st_mode & 0o022:
            raise CollectorError("State directory must be owned by collector and not group/world writable")
        fd = os.open(str(state) + '.lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
        self.lock = os.fdopen(fd, 'r+b')
        try:
            fcntl.flock(self.lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            state_fd = os.open(state, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
            try:
                if not stat.S_ISREG(os.fstat(state_fd).st_mode) or os.fstat(state_fd).st_mode & 0o077:
                    raise CollectorError('State database must be a private regular file (0600)')
            finally:
                os.close(state_fd)
            self.db = sqlite3.connect(state)
            self.db.execute('PRAGMA synchronous=FULL')
            self.db.execute('CREATE TABLE IF NOT EXISTS retired (dev INTEGER NOT NULL, inode INTEGER NOT NULL, mtime INTEGER NOT NULL, size INTEGER NOT NULL, PRIMARY KEY(dev,inode))')
            self.db.execute('CREATE TABLE IF NOT EXISTS checkpoint (id INTEGER PRIMARY KEY CHECK(id=1), identity TEXT NOT NULL, dev INTEGER, inode INTEGER, offset INTEGER NOT NULL, fingerprint TEXT, pending BLOB, pending_key TEXT, pending_end INTEGER, pending_fingerprint TEXT, pending_at REAL)')
            identity = json.dumps([str(self.path), client.base_url.rstrip('/'), client.api_prefix, hashlib.sha256(client.token.encode()).hexdigest(), source, fmt, str(zone), service], separators=(',', ':'))
            with self.db:
                self.db.execute('INSERT OR IGNORE INTO checkpoint(id,identity,offset) VALUES(1,?,0)', (identity,))
            if self.db.execute('SELECT identity FROM checkpoint').fetchone()[0] != identity:
                raise CollectorError('State belongs to another file, credential or parser; use original configuration')
        except BaseException:
            if hasattr(self, 'db'): self.db.close()
            self.lock.close()
            raise

    def close(self):
        self.db.close(); self.lock.close()

    def row(self):
        return self.db.execute('SELECT dev,inode,offset,fingerprint,pending,pending_key,pending_end,pending_fingerprint,pending_at FROM checkpoint').fetchone()

    @staticmethod
    def fingerprint(f, offset):
        f.seek(max(0, offset - 4096))
        return hashlib.sha256(f.read(min(offset, 4096))).hexdigest()

    def open_file(self, dev, inode):
        # Open first then check fstat (avoid path races). Refuse symlinks, compressed files and special devices.
        candidates = [str(self.path)]
        if dev is not None:
            candidates += sorted(glob.glob(self.rotated_glob or str(self.path) + '.*'))
        for candidate in candidates:
            if candidate.endswith(('.gz','.xz','.bz2','.zst')) or candidate == str(self.state) or candidate.startswith(str(self.state) + '.') or candidate.startswith(str(self.state) + '-'): continue
            try:
                fd = os.open(candidate, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
            except FileNotFoundError:
                continue
            f = os.fdopen(fd, 'rb')
            st = os.fstat(fd)
            if stat.S_ISREG(st.st_mode) and (dev is None or (st.st_dev, st.st_ino) == (dev, inode)):
                return f, st
            f.close()
        raise CollectorError('Original file is missing (rotation/truncation); retain uncompressed rotated logs and recover before advancing')

    def rotated_files(self):
        for candidate in glob.glob(self.rotated_glob or str(self.path) + '.*'):
            if candidate.endswith(('.gz','.xz','.bz2','.zst')) or candidate == str(self.state) or candidate.startswith(str(self.state) + '.') or candidate.startswith(str(self.state) + '-'): continue
            try: st = os.stat(candidate, follow_symlinks=False)
            except FileNotFoundError: continue
            if stat.S_ISREG(st.st_mode): yield st

    def step(self) -> int:
        dev, inode, offset, fingerprint, pending, key, end, end_hash, created = self.row()
        if pending is not None:
            # Server receipts expire after 24h. Never silently replay an ambiguous old batch.
            if time.time() - created >= 23 * 3600 or time.time() < created - 60:
                raise CollectorError('Pending batch exceeded safe retry window; reconcile server receipt manually')
            self.client.send_body(pending, key)
            with self.db:
                self.db.execute('UPDATE checkpoint SET offset=?, fingerprint=?, pending=NULL,pending_key=NULL,pending_end=NULL,pending_fingerprint=NULL,pending_at=NULL WHERE id=1', (end, end_hash))
            return len(json.loads(pending)['events'])
        with self.open_file(dev, inode)[0] as f:
            st = os.fstat(f.fileno())
            if dev is None:
                with self.db:
                    self.db.execute('UPDATE checkpoint SET dev=?,inode=?,fingerprint=? WHERE id=1', (st.st_dev, st.st_ino, self.fingerprint(f, 0)))
                    # Start at the current file, not historical archives already present at first run.
                    for candidate in self.rotated_files():
                        if (candidate.st_dev,candidate.st_ino) != (st.st_dev,st.st_ino):
                            self.db.execute('INSERT OR IGNORE INTO retired VALUES (?,?,?,?)',(candidate.st_dev,candidate.st_ino,candidate.st_mtime_ns,candidate.st_size))
            if st.st_size < offset or (fingerprint is not None and self.fingerprint(f, offset) != fingerprint):
                raise CollectorError('File truncated or rewritten; refusing to skip or duplicate data')
            f.seek(offset)
            events, end = [], offset
            max_line = 131072 if self.fmt == 'cajeer-jsonl' else 65536
            for _ in range(self.batch):
                raw = f.readline(max_line + 1)
                if len(raw) > max_line:
                    if events: break
                    raise CollectorError('Log line exceeds format limit; fix input before resuming')
                if not raw or not raw.endswith(b'\n'):
                    if raw and self.fmt == 'cajeer-jsonl' and not events:
                        raise CollectorError('Archive has an incomplete final line; repair input before resuming')
                    break  # incomplete tail is never acknowledged
                try:
                    event = parse_line(raw, self.fmt, self.source, self.zone, self.service)
                    body = encode(events + [event])
                except (ValueError, UnicodeError, TypeError, RecursionError):
                    if events: break
                    raise CollectorError(f'Invalid log line at byte offset {offset}; correct input before resuming') from None
                events.append(event); end = f.tell()
            if events:
                body = encode(events)
                end_hash = self.fingerprint(f, end)
                with self.db:
                    self.db.execute('UPDATE checkpoint SET pending=?,pending_key=?,pending_end=?,pending_fingerprint=?,pending_at=? WHERE id=1', (body,secrets.token_hex(16),end,end_hash,time.time()))
                return self.step()
            # Rotation: drain renamed inode first. Require complete final line and no late writes.
            try: current = self.path.stat(follow_symlinks=False)
            except FileNotFoundError: return 0
            if (current.st_dev, current.st_ino) != (st.st_dev, st.st_ino):
                if f.tell() != offset or os.fstat(f.fileno()).st_size != offset:
                    raise CollectorError('Rotated file has incomplete tail; finish line before rotation')
                if not stat.S_ISREG(current.st_mode): raise CollectorError('New input is not a regular file')
                # Wait for a quiet period before switching, so the usual rename/reopen can finish.
                if time.time() - os.fstat(f.fileno()).st_mtime < 2: return 0
                # If several rename rotations occurred during downtime, drain intermediate files too.
                retired = {(d,i):(m,n) for d,i,m,n in self.db.execute('SELECT dev,inode,mtime,size FROM retired')}
                candidates = {}
                for x in self.rotated_files():
                    ident=(x.st_dev,x.st_ino)
                    if ident in ((st.st_dev,st.st_ino),(current.st_dev,current.st_ino)): continue
                    if ident in retired:
                        if retired[ident] != (x.st_mtime_ns,x.st_size):
                            raise CollectorError('Retired inode changed or was reused; reconcile archived logs before resuming')
                        continue
                    candidates[ident]=x
                ordered = sorted(candidates.values(),key=lambda x:x.st_mtime_ns)
                if ordered and (ordered[0].st_mtime_ns <= st.st_mtime_ns or ordered[-1].st_mtime_ns > current.st_mtime_ns or len({x.st_mtime_ns for x in ordered}) != len(ordered)):
                    raise CollectorError('Ambiguous rotation order; preserve archives and reconcile before resuming')
                next_file = ordered[0] if ordered else current
                with self.db:
                    self.db.execute('INSERT OR REPLACE INTO retired VALUES (?,?,?,?)',(st.st_dev,st.st_ino,st.st_mtime_ns,st.st_size))
                    self.db.execute('UPDATE checkpoint SET dev=?,inode=?,offset=0,fingerprint=? WHERE id=1', (next_file.st_dev,next_file.st_ino,hashlib.sha256(b'').hexdigest()))
                return -1
            return 0


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--file', required=True, type=Path); parser.add_argument('--state', required=True, type=Path)
    parser.add_argument('--url', default=os.getenv('CAJEER_URL')); parser.add_argument('--source', required=True)
    parser.add_argument('--format', choices=['text','jsonl','cajeer-jsonl','nginx-access','nginx-error'], default='text')
    parser.add_argument('--timezone', default='UTC'); parser.add_argument('--service'); parser.add_argument('--rotated-glob')
    parser.add_argument('--batch', type=int, default=50); parser.add_argument('--signed', action='store_true'); parser.add_argument('--once', action='store_true')
    parser.add_argument('--interval', type=float, default=2); parser.add_argument('--api-prefix', default='/api/v1')
    args = parser.parse_args(argv)
    token = os.getenv('CAJEER_TOKEN')
    if not token or not args.url or not 0.1 <= args.interval <= 300: parser.error('Set CAJEER_TOKEN and CAJEER_URL; interval 0.1..300 seconds')
    collector = None; stopped = False
    def stop(*_):
        nonlocal stopped; stopped = True
    signal.signal(signal.SIGTERM, stop); signal.signal(signal.SIGINT, stop)
    try:
        collector = Collector(args.file,args.state,CajeerLogsClient(args.url,token,signed=args.signed,api_prefix=args.api_prefix),args.source,args.format,ZoneInfo(args.timezone),args.service,args.rotated_glob,args.batch)
        while not stopped:
            n = collector.step()
            if n > 0: print(f'Committed {n} events',flush=True)
            if n == 0:
                if args.once: break
                time.sleep(args.interval)
        return 0
    except Exception as e:
        # Never print a log line, token, URL, or remote body in diagnostics.
        detail = str(e) if isinstance(e, CollectorError) else type(e).__name__
        print(f'Collector stopped: {detail}. No unconfirmed checkpoint advanced.', file=sys.stderr)
        return 1
    finally:
        if collector: collector.close()

if __name__ == '__main__':
    sys.exit(main())
