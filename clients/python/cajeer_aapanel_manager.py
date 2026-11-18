#!/usr/bin/env python3
"""aaPanel multi-site collector. Local-only operator config; existing ingest API.

No aaPanel admin credentials, root privileges, database access or new server API.
Each site/kind retains its own original durable Collector checkpoint.
"""
from __future__ import annotations
import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import signal
import stat
import sys
import time
from datetime import datetime, timezone
from concurrent.futures import ThreadPoolExecutor
from zoneinfo import ZoneInfo

from cajeer_logs_client import CajeerLogsClient
from cajeer_logs_collector import Collector, CollectorError

DOMAIN = re.compile(r'(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+', re.ASCII)
SOURCE = re.compile(r'[a-z0-9]+(?:-[a-z0-9]+)*', re.ASCII)
SUFFIXES = (('.error.log', 'error', 'nginx-error'), ('.json.log', 'json', 'nginx-json'), ('.log', 'access', 'nginx-access'))
MAX_STREAMS = 1000

class ManagerError(ValueError):
    pass


def utc():
    return datetime.now(timezone.utc).isoformat(timespec='seconds')


def private_dir(path: Path, create: bool = False):
    if create:
        path.mkdir(mode=0o700, parents=True, exist_ok=True)
    st = path.lstat()
    if not stat.S_ISDIR(st.st_mode) or st.st_uid != os.geteuid() or (st.st_mode & 0o077):
        raise ManagerError('Operator state directory must be owned by current user and mode 0700')


def read_private_json(path: Path):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    try:
        st = os.fstat(fd)
        if not stat.S_ISREG(st.st_mode) or st.st_uid != os.geteuid() or st.st_mode & 0o077 or st.st_size > 2_000_000:
            raise ManagerError('Configuration/status must be a private regular file (0600)')
        with os.fdopen(fd, 'r', encoding='utf-8') as f:
            return json.load(f)
    except BaseException:
        try: os.close(fd)
        except OSError: pass
        raise


def write_private_json(path: Path, value):
    data = (json.dumps(value, sort_keys=True, indent=2, ensure_ascii=True) + '\n').encode()
    fd = os.open(path.parent / ('.' + path.name + '.tmp.' + str(os.getpid())), os.O_CREAT | os.O_EXCL | os.O_WRONLY | os.O_NOFOLLOW, 0o600)
    temp = path.parent / ('.' + path.name + '.tmp.' + str(os.getpid()))
    try:
        with os.fdopen(os.dup(fd), 'wb', closefd=True) as out:
            out.write(data)
            out.flush()
            os.fsync(out.fileno())
        os.close(fd)
        os.replace(temp, path)
        dirfd = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
        try: os.fsync(dirfd)
        finally: os.close(dirfd)
    finally:
        try: os.close(fd)
        except OSError: pass
        try: temp.unlink()
        except FileNotFoundError: pass


def hold_lock(path: Path, wait=False):
    fd = os.open(path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    if not stat.S_ISREG(os.fstat(fd).st_mode) or (os.fstat(fd).st_mode & 0o077):
        os.close(fd); raise ManagerError('Lock must be private')
    try: fcntl.flock(fd, fcntl.LOCK_EX | (0 if wait else fcntl.LOCK_NB))
    except OSError:
        os.close(fd); raise ManagerError('Manager already running') from None
    return fd


def source_for(domain: str, kind: str):
    prefix = re.sub('[^a-z0-9]+', '-', domain).strip('-')[:43].strip('-')
    digest = hashlib.sha256(domain.encode()).hexdigest()[:10]
    return f'aap-{prefix}-{digest}-{kind}'


def discover(logs_dir: Path):
    """No recursion: refuse linked files, partial names, arbitrary directories."""
    if logs_dir.is_symlink() or not logs_dir.is_dir():
        raise ManagerError('aaPanel logs root must be a real directory')
    result = []
    with os.scandir(logs_dir) as iterator:
        for item in iterator:
            if item.is_symlink() or not item.is_file(follow_symlinks=False):
                continue
            for suffix, kind, fmt in SUFFIXES:
                if not item.name.endswith(suffix):
                    continue
                domain = item.name[:-len(suffix)].lower()
                if not DOMAIN.fullmatch(domain) or item.name != domain + suffix:
                    break
                result.append({'id': hashlib.sha256(f'{domain}\0{kind}'.encode()).hexdigest()[:20],
                               'site': domain, 'kind': kind, 'format': fmt,
                               'filename': item.name, 'source': source_for(domain, kind), 'enabled': False, 'timezone': 'UTC'})
                break
    if len(result) > MAX_STREAMS:
        raise ManagerError('Too many sites/log streams (limit 1000)')
    return sorted(result, key=lambda v: (v['site'], v['kind']))


def validate(config):
    if not isinstance(config, dict) or config.get('schema') != 1 or not isinstance(config.get('streams'), list):
        raise ManagerError('Unsupported manager configuration')
    logs_dir = Path(config.get('logs_dir', ''))
    state_dir = Path(config.get('state_dir', ''))
    if not logs_dir.is_absolute() or not state_dir.is_absolute() or state_dir == logs_dir:
        raise ManagerError('Absolute, separate log and state directories required')
    private_dir(state_dir)
    if logs_dir.is_symlink() or not logs_dir.is_dir():
        raise ManagerError('Invalid aaPanel logs directory')
    if len(config['streams']) > MAX_STREAMS:
        raise ManagerError('Too many configured streams')
    ids, sources, names = set(), set(), set()
    for item in config['streams']:
        if not isinstance(item, dict) or not isinstance(item.get('site'), str) or not DOMAIN.fullmatch(item['site']):
            raise ManagerError('Invalid site')
        suffixes = {kind: (suffix, fmt) for suffix, kind, fmt in SUFFIXES}
        if item.get('kind') not in suffixes:
            raise ManagerError('Invalid stream kind')
        suffix, fmt = suffixes[item['kind']]
        if item.get('filename') != item['site'] + suffix or item.get('format') != fmt:
            raise ManagerError('Refusing unmanaged log path/format')
        if item.get('id') != hashlib.sha256(f"{item['site']}\0{item['kind']}".encode()).hexdigest()[:20]:
            raise ManagerError('Invalid stream identity')
        if not isinstance(item.get('source'), str) or len(item['source']) > 128 or not SOURCE.fullmatch(item['source']):
            raise ManagerError('Invalid source slug')
        try: ZoneInfo(item.get('timezone', 'UTC'))
        except (ValueError, TypeError, KeyError, OSError): raise ManagerError('Invalid stream timezone') from None
        if type(item.get('enabled')) is not bool:
            raise ManagerError('Invalid enabled flag')
        if item['id'] in ids or item['source'] in sources or item['filename'] in names:
            raise ManagerError('Duplicate stream, filename or ingest source')
        ids.add(item['id']); sources.add(item['source']); names.add(item['filename'])
    return logs_dir, state_dir


def load(path):
    config = read_private_json(path)
    validate(config)
    return config


def sync_config(config, enable_new=False):
    logs_dir, _ = validate(config)
    entries = {x['id']: x for x in config['streams']}
    added = []
    for item in discover(logs_dir):
        if item['id'] not in entries:
            item['enabled'] = bool(enable_new)
            entries[item['id']] = item
            added.append(item['id'])
    if added:
        config['streams'] = sorted(entries.values(), key=lambda v: (v['site'], v['kind']))
        validate(config)
    return added


def state_file(config, item):
    return Path(config['state_dir']) / ('stream-' + item['id'] + '.sqlite')


def measured(config, item, state=None):
    logpath = Path(config['logs_dir']) / item['filename']
    result = {'id': item['id'], 'site': item['site'], 'kind': item['kind'], 'source': item['source'],
              'enabled': item['enabled'], 'present': False, 'file_bytes': None, 'checkpoint_bytes': None,
              'lag_bytes': None, 'pending': False, 'last_success': None, 'delivered': 0, 'error': None}
    if state:
        result.update({key: state.get(key) for key in ('last_success','delivered','error')})
    try:
        fd = os.open(logpath, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        try:
            st = os.fstat(fd)
            if not stat.S_ISREG(st.st_mode):
                raise ManagerError('Not a regular log file')
            result['present'] = True; result['file_bytes'] = st.st_size
            dbpath = state_file(config, item)
            if dbpath.exists():
                import sqlite3
                # A read-only connection does not interfere with collector leases.
                db = sqlite3.connect(f'file:{dbpath}?mode=ro', uri=True, timeout=1)
                try:
                    row = db.execute('SELECT dev,inode,offset,pending FROM checkpoint WHERE id=1').fetchone()
                    if row:
                        result['checkpoint_bytes'] = row[2]
                        result['pending'] = row[3] is not None
                        if (row[0],row[1]) == (st.st_dev,st.st_ino):
                            result['lag_bytes'] = max(0, st.st_size - row[2])
                finally: db.close()
            else: result['checkpoint_bytes'] = 0; result['lag_bytes'] = st.st_size
        finally: os.close(fd)
    except (OSError, ValueError, ManagerError) as e:
        if not result['error']:
            result['error'] = 'File unavailable or unreadable' if isinstance(e, OSError) else str(e)
    return result


def safe_error(exc: Exception):
    # Never disclose URLs, tokens, raw log records or HTTP response bodies.
    if isinstance(exc, CollectorError): return str(exc)
    if isinstance(exc, ManagerError): return str(exc)
    return type(exc).__name__


def execute(config_path: Path, once: bool, auto_discover: bool, interval: float, batch: int,
            cycles: int | None = None, client=None, workers: int = 4):
    if not 1 <= workers <= 16:
        raise ManagerError('Invalid worker count')
    config = load(config_path)
    _, state_dir = validate(config)
    if client is None:
        token, url = os.getenv('CAJEER_TOKEN'), os.getenv('CAJEER_URL')
        if not token or not url:
            raise ManagerError('Set CAJEER_TOKEN and CAJEER_URL in process environment')
        client = CajeerLogsClient(url, token, attempts=1, signed=os.getenv('CAJEER_SIGNED', '1') != '0',
                                  signing_key_id=os.getenv('CAJEER_SIGNING_KEY_ID'),
                                  hmac_secret=os.getenv('CAJEER_SIGNING_SECRET'))
    lock = hold_lock(state_dir / '.manager.lock')
    monitor_file = state_dir / 'monitor.json'
    try:
        local = read_private_json(monitor_file) if monitor_file.exists() else {}
    except BaseException:
        os.close(lock)
        raise
    stopped = False
    def stop(*_):
        nonlocal stopped
        stopped = True
    original_term = signal.getsignal(signal.SIGTERM); original_int = signal.getsignal(signal.SIGINT)
    signal.signal(signal.SIGTERM, stop); signal.signal(signal.SIGINT, stop)
    problem = False
    try:
        iteration = 0
        while not stopped:
            config = load(config_path)
            if auto_discover:
                guard = hold_lock(config_path.with_name(config_path.name+'.lock'), wait=True)
                try:
                    config = load(config_path)
                    # Keep newly found logs disabled until explicitly enabled.
                    if sync_config(config): write_private_json(config_path, config)
                finally: os.close(guard)
            previous = local.get('streams', {})
            details, activity = {}, False
            def process(item):
                path = Path(config['logs_dir']) / item['filename']
                try:
                    if path.is_symlink() or not path.is_file():
                        raise ManagerError('Log missing or unsafe (symlink/special file)')
                    collector = Collector(path, state_file(config, item), client, item['source'], item['format'],
                                          ZoneInfo(item.get('timezone', 'UTC')), service=item['site'], batch=batch, copytruncate=True)
                    try:
                        count = 0
                        # Bound per-site work to prevent one busy vhost starving others.
                        for _ in range(20):
                            n = collector.step()
                            if n == 0: break
                            if n > 0: count += n
                    finally:
                        collector.close()
                    return count, None
                except Exception as exc:
                    return 0, safe_error(exc)
            enabled = [item for item in config['streams'] if item['enabled']]
            with ThreadPoolExecutor(max_workers=workers, thread_name_prefix='aa-log') as pool:
                futures = {item['id']: pool.submit(process, item) for item in enabled}
                for item in config['streams']:
                    old = dict(previous.get(item['id'], {}))
                    if not item['enabled']:
                        details[item['id']] = measured(config, item, old)
                        continue
                    count, error = futures[item['id']].result()
                    activity = activity or bool(count)
                    problem = problem or error is not None
                    old['delivered'] = int(old.get('delivered') or 0) + count
                    if count: old['last_success'] = utc()
                    old['error'] = error
                    details[item['id']] = measured(config, item, old)
            local = {'schema': 1, 'updated_at': utc(), 'pid': os.getpid(), 'streams': details}
            write_private_json(state_dir / 'monitor.json', local)
            iteration += 1
            if cycles is not None and iteration >= cycles: break
            if once:
                if not activity: break
            else:
                if not activity: time.sleep(interval)
        return 1 if problem else 0
    finally:
        signal.signal(signal.SIGTERM, original_term)
        signal.signal(signal.SIGINT, original_int)
        os.close(lock)


def main(argv=None):
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--config', required=True, type=Path, help='Private JSON manager configuration')
    sub = p.add_subparsers(dest='command', required=True)
    init = sub.add_parser('init'); init.add_argument('--logs-dir', type=Path, required=True); init.add_argument('--state-dir', type=Path, required=True)
    discover_p = sub.add_parser('discover'); discover_p.add_argument('--enable-new', action='store_true')
    sub.add_parser('list'); status_parser = sub.add_parser('status'); status_parser.add_argument('--max-age', type=int, default=120)
    sett = sub.add_parser('set'); sett.add_argument('--id', required=True); sett.add_argument('--source'); sett.add_argument('--timezone'); selection = sett.add_mutually_exclusive_group(); selection.add_argument('--enable', action='store_true'); selection.add_argument('--disable', action='store_true')
    run = sub.add_parser('run'); run.add_argument('--workers', type=int, default=4); run.add_argument('--once', action='store_true'); run.add_argument('--auto-discover', action='store_true'); run.add_argument('--batch', type=int, default=50); run.add_argument('--interval', type=float, default=2)
    args = p.parse_args(argv)
    path = args.config.absolute()
    try:
        private_dir(path.parent)
        if args.command == 'init':
            if path.exists() or path.is_symlink(): raise ManagerError('Config exists; refusing to overwrite')
            config = {'schema': 1, 'logs_dir': str(args.logs_dir.resolve(strict=True)),
                      'state_dir': str(args.state_dir.absolute()), 'streams': []}
            private_dir(Path(config['state_dir']), create=True)
            validate(config)
            write_private_json(path, config)
            print(json.dumps({'created': str(path)}))
            return 0
        if args.command in ('set', 'discover'):
            guard = hold_lock(path.with_name(path.name+'.lock'), wait=True)
            try:
                config = load(path)
                if args.command == 'discover':
                    added = sync_config(config, args.enable_new)
                    if added: write_private_json(path, config)
                    print(json.dumps({'added': len(added), 'stream_ids': added}))
                else:
                    found = next((v for v in config['streams'] if v['id'] == args.id), None)
                    if found is None: raise ManagerError('Unknown stream id')
                    if args.timezone is not None:
                        if state_file(config, found).exists() and args.timezone != found.get('timezone','UTC'):
                            raise ManagerError('Timezone is bound to checkpoint: migrate state explicitly')
                        found['timezone'] = args.timezone
                    if args.source is not None:
                        if state_file(config, found).exists() and args.source != found['source']:
                            raise ManagerError('Source is bound to checkpoint: disable and migrate state explicitly')
                        found['source'] = args.source
                    if args.enable: found['enabled'] = True
                    if args.disable: found['enabled'] = False
                    validate(config); write_private_json(path, config)
                    print(json.dumps({'id': found['id'], 'enabled': found['enabled'], 'source': found['source']}))
            finally: os.close(guard)
            return 0
        config = load(path)
        if args.command == 'list':
            print(json.dumps(config['streams'], indent=2)); return 0
        if args.command == 'status':
            state = Path(config['state_dir']) / 'monitor.json'
            old = read_private_json(state) if state.exists() else {}
            values = {v['id']: measured(config, v, (old.get('streams') or {}).get(v['id'])) for v in config['streams']}
            if not 1 <= args.max_age <= 86400:
                raise ManagerError('max-age must be 1..86400 seconds')
            try:
                age = max(0, int(time.time() - datetime.fromisoformat(old['updated_at']).timestamp()))
            except (KeyError, ValueError, TypeError):
                age = None
            stale = age is None or age > args.max_age
            healthy = not stale and all(not v['enabled'] or (v['present'] and v['error'] is None) for v in values.values())
            print(json.dumps({'updated_at': old.get('updated_at'), 'age_seconds': age,
                              'stale': stale, 'healthy': healthy, 'streams': values}, indent=2))
            return 0 if healthy else 1
        if args.command == 'run':
            if not 1 <= args.batch <= 100 or not 1 <= args.workers <= 16 or not 0.1 <= args.interval <= 300:
                raise ManagerError('batch 1..100, workers 1..16 and interval 0.1..300 required')
            return execute(path, args.once, args.auto_discover, args.interval, args.batch, workers=args.workers)
    except (ManagerError, OSError, ValueError, json.JSONDecodeError) as exc:
        print('aaPanel manager: ' + safe_error(exc), file=sys.stderr)
        return 1
    return 64

if __name__ == '__main__':
    sys.exit(main())
