#!/usr/bin/env python3
"""Live CajeerLogs load and resilience tester (stdlib only, writes durable events).

No simulated server responses qualify as throughput evidence. In the fault phase a
local reverse proxy injects transport errors while forwarding other requests to
an actual CajeerLogs instance; all accepted writes are checked via the logs API.
"""
from __future__ import annotations

import argparse
from collections import Counter
from concurrent.futures import ThreadPoolExecutor, wait, FIRST_COMPLETED
from contextlib import contextmanager
from datetime import datetime, timezone
import hashlib
import hmac
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import math
import os
from pathlib import Path
import secrets
import socket
import ssl
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

VERSION = '0.8.3'
MAX_RESPONSE = 4 * 1024 * 1024
RETRYABLE = {429, 502, 503, 504}
PHASES = ('sustained', 'stress', 'soak', 'fault')


class TransportError(Exception):
    pass


class ProtocolError(Exception):
    pass


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def check_origin(origin):
    parsed = urllib.parse.urlsplit(origin)
    if (parsed.scheme not in ('http', 'https') or not parsed.hostname or parsed.username or parsed.password
            or parsed.path not in ('', '/') or parsed.query or parsed.fragment
            or (parsed.scheme == 'http' and parsed.hostname not in ('localhost', '127.0.0.1', '::1'))):
        raise ValueError('Expected a bare HTTPS origin; HTTP is allowed only on loopback')
    return origin.rstrip('/')


def opener_for(context):
    return urllib.request.build_opener(NoRedirect(), urllib.request.ProxyHandler({}), urllib.request.HTTPSHandler(context=context))


def fetch(opener, url, method, path, headers, body=None, timeout=10):
    req = urllib.request.Request(url + path, data=body, headers=headers, method=method)
    start = time.perf_counter()
    try:
        try:
            response = opener.open(req, timeout=timeout)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            status = response.status
            data = response.read(MAX_RESPONSE + 1)
            response_headers = dict(response.headers)
        if len(data) > MAX_RESPONSE:
            raise ProtocolError('Response exceeds size limit')
        if not data:
            value = None
        else:
            try:
                value = json.loads(data)
            except (UnicodeError, ValueError):
                raise ProtocolError('Non-JSON HTTP response') from None
        return status, value, time.perf_counter() - start, response_headers
    except (OSError, TimeoutError, urllib.error.URLError) as error:
        raise TransportError(type(error).__name__) from None


class Client:
    def __init__(self, origin, token, project, source, cafile=None, key_id=None, signing_secret=None):
        self.origin = check_origin(origin)
        self.token = token
        self.project = str(uuid.UUID(project))
        self.source = source
        if not token or not source or len(source) > 128:
            raise ValueError('Token and valid source are required')
        if (key_id is None) != (signing_secret is None):
            raise ValueError('Provide both signing key ID and secret')
        if key_id is not None:
            if str(uuid.UUID(key_id)) != key_id or len(signing_secret) != 67 or not signing_secret.startswith('sk_') or any(c not in '0123456789abcdef' for c in signing_secret[3:]):
                raise ValueError('Invalid signing credentials')
        self.key_id = key_id
        self.signing_secret = signing_secret
        self.context = ssl.create_default_context(cafile=cafile)
        self.opener = opener_for(self.context)

    def request(self, method, path, body=None, idem=None, timeout=10, origin=None):
        headers = {'Accept': 'application/json', 'Authorization': 'Bearer ' + self.token}
        if body is not None:
            ts = str(int(time.time()))
            nonce = secrets.token_hex(16)
            parts = ['cajeerlogs-ingest-v1'] if self.key_id is None else ['cajeerlogs-ingest-v2', self.key_id]
            signature = hmac.new((self.signing_secret or self.token).encode(), '\n'.join([
                *parts, method, path, ts, nonce, idem, hashlib.sha256(body).hexdigest()
            ]).encode(), hashlib.sha256).hexdigest()
            headers.update({'Content-Type': 'application/json', 'Idempotency-Key': idem,
                            'X-Log-Timestamp': ts, 'X-Log-Nonce': nonce, 'X-Log-Signature': signature})
            if self.key_id is not None:
                headers['X-Log-Key-Id'] = self.key_id
        return fetch(self.opener, origin or self.origin, method, path, headers, body, timeout)

    def retried(self, method, path, body=None, idem=None, deadline=None, origin=None, max_attempts=6):
        attempts = 0
        first = time.perf_counter()
        while attempts < max_attempts:
            attempts += 1
            remaining = (deadline - time.monotonic()) if deadline is not None else 30
            if remaining <= 0:
                raise TransportError('Request deadline expired')
            try:
                status, data, _, headers = self.request(method, path, body, idem, min(10, remaining), origin)
                if status not in RETRYABLE:
                    return status, data, time.perf_counter() - first, attempts
            except TransportError:
                if attempts == max_attempts:
                    raise
            else:
                if attempts == max_attempts:
                    raise ProtocolError('Retry budget exhausted (HTTP %s)' % status)
            if attempts < max_attempts:
                pause = min(.05 * 2 ** (attempts - 1), .8)
                if deadline is not None and time.monotonic() + pause >= deadline:
                    raise TransportError('Request deadline expired during retry')
                time.sleep(pause)
        raise TransportError('Retry budget exhausted')


class Histogram:
    """Exact nearest-rank percentiles at 1 ms resolution without sample growth."""
    def __init__(self):
        self.buckets = Counter()
        self.count = 0
        self.total_ms = 0
        self.maximum = 0

    def add(self, seconds):
        ms = max(0, math.ceil(seconds * 1000))
        self.buckets[ms] += 1
        self.count += 1
        self.total_ms += ms
        self.maximum = max(self.maximum, ms)

    def summarize(self):
        if not self.count:
            return {'count': 0}
        ranks = {p: math.ceil(self.count * p / 100) for p in (50, 95, 99)}
        seen = 0
        result = {}
        for value, count in sorted(self.buckets.items()):
            seen += count
            for p, rank in ranks.items():
                if seen >= rank and f'p{p}_ms' not in result:
                    result[f'p{p}_ms'] = value
        return {'count': self.count, 'mean_ms': round(self.total_ms / self.count, 2), **result, 'max_ms': self.maximum}


class FaultProxy(ThreadingHTTPServer):
    daemon_threads = True
    allow_reuse_address = True

    def __init__(self, upstream, context):
        self.upstream = upstream
        self.opener = opener_for(context)
        self.lock = threading.Lock()
        self.counters = Counter()
        self.writes = 0
        self.seen_idempotency = set()
        super().__init__(('127.0.0.1', 0), FaultHandler)

    def fault(self, key):
        with self.lock:
            # Fault once per idempotency key: retries must reach the actual server.
            if key in self.seen_idempotency:
                return None
            self.seen_idempotency.add(key)
            self.writes += 1
            mode = ('pre503', 'pre429', 'lost_ack', 'delay')[(self.writes - 1) % 4]
            self.counters[mode] += 1
        return mode


class FaultHandler(BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'

    def log_message(self, *_):
        pass

    def do_POST(self):
        self.forward()

    def do_GET(self):
        self.forward()

    def forward(self):
        if not (self.path.startswith('/api/v1/ingest') or self.path.startswith('/api/v1/logs')):
            self.send_error(404)
            return
        length_header = self.headers.get('Content-Length', '0')
        try:
            length = int(length_header)
        except ValueError:
            self.send_error(400)
            return
        if length < 0 or length > 1024 * 1024:
            self.send_error(413)
            return
        raw = self.rfile.read(length) if length else None
        mode = self.server.fault(self.headers.get('Idempotency-Key')) if self.command == 'POST' else None
        if mode in ('pre503', 'pre429'):
            self.reply(503 if mode == 'pre503' else 429, b'{"error":"injected"}')
            return
        if mode == 'delay':
            time.sleep(.1)
        excluded = {'connection', 'host', 'content-length', 'transfer-encoding', 'accept-encoding'}
        headers = {k: v for k, v in self.headers.items() if k.lower() not in excluded}
        req = urllib.request.Request(self.server.upstream + self.path, data=raw, method=self.command, headers=headers)
        try:
            try:
                response = self.server.opener.open(req, timeout=10)
            except urllib.error.HTTPError as error:
                response = error
            with response:
                status = response.status
                payload = response.read(MAX_RESPONSE + 1)
                if len(payload) > MAX_RESPONSE:
                    self.reply(502, b'{"error":"upstream_size"}')
                    return
                content_type = response.headers.get('Content-Type', 'application/json')
        except (OSError, urllib.error.URLError):
            self.reply(502, b'{"error":"upstream_transport"}')
            return
        with self.server.lock:
            self.server.counters['forwarded'] += 1
            if status in (200, 201, 202):
                self.server.counters['upstream_success'] += 1
        if mode == 'lost_ack':
            # Real upstream processed the exact request; kill its response on the wire.
            self.close_connection = True
            try:
                self.connection.shutdown(socket.SHUT_RDWR)
            except OSError:
                pass
            self.connection.close()
            return
        self.reply(status, payload, content_type)

    def reply(self, status, payload, content_type='application/json'):
        self.send_response(status)
        self.send_header('Content-Type', content_type)
        self.send_header('Content-Length', str(len(payload)))
        self.send_header('Connection', 'close')
        self.end_headers()
        try:
            self.wfile.write(payload)
        except (BrokenPipeError, ConnectionResetError):
            pass
        self.close_connection = True


@contextmanager
def injected_network(client):
    proxy = FaultProxy(client.origin, client.context)
    thread = threading.Thread(target=proxy.serve_forever, daemon=True)
    thread.start()
    try:
        yield f'http://127.0.0.1:{proxy.server_port}', proxy
    finally:
        proxy.shutdown()
        proxy.server_close()
        thread.join(timeout=5)


def make_event(client, request_id, sequence, stamp):
    return {'timestamp': stamp, 'source': client.source, 'level': 'info',
            'message': f'load-test {request_id} #{sequence}', 'request_id': request_id}


def write_one(client, marker, index, batch, async_percent, origin, deadline):
    n = batch
    async_mode = (index * 37 % 100) < async_percent
    stamp = datetime.now(timezone.utc).isoformat()
    events = [make_event(client, marker, index * batch + j, stamp) for j in range(n)]
    body = json.dumps(events[0] if n == 1 else {'events': events}, separators=(',', ':')).encode()
    path = '/api/v1/ingest' + ('/async' if async_mode else '')
    idem = marker + '-' + str(index)
    status, data, elapsed, attempts = client.retried('POST', path, body, idem, deadline, origin)
    if status not in ((200, 202) if async_mode else (200, 201)) or not isinstance(data, dict) or data.get('received') != n:
        raise ProtocolError('Unexpected ingest receipt, HTTP %s' % status)
    replay = status == 200
    if replay and not data.get('replayed'):
        raise ProtocolError('HTTP 200 must be an idempotency replay')
    if async_mode:
        if not isinstance(data.get('job_id'), str):
            raise ProtocolError('Missing asynchronous job ID')
        return (n, None, (data['job_id'], n), elapsed, attempts, replay, time.monotonic())
    ids = data.get('event_ids')
    if not isinstance(ids, list) or len(ids) != n or len(set(ids)) != n:
        raise ProtocolError('Invalid or duplicate receipt event IDs')
    return (n, ids, None, elapsed, attempts, replay, time.monotonic())


def drain_jobs(client, jobs, deadline):
    pending = dict(jobs)
    completed = []
    while pending:
        if time.monotonic() > deadline:
            raise ProtocolError('Async job drain timed out')
        for jid, n in list(pending.items()):
            status, value, _, _ = client.retried('GET', '/api/v1/ingest/jobs/' + urllib.parse.quote(jid, safe=''), deadline=deadline)
            if status != 200 or not isinstance(value, dict):
                raise ProtocolError('Job status unavailable')
            if value.get('status') == 'completed':
                result = value.get('result') or {}
                ids = result.get('event_ids')
                if not isinstance(ids, list) or len(ids) != n or len(set(ids)) != n:
                    raise ProtocolError('Async job stored wrong number of events')
                completed.extend(ids)
                pending.pop(jid)
            elif value.get('status') not in ('pending', 'running'):
                raise ProtocolError('Async job failed')
        if pending:
            time.sleep(.2)
    return completed


def verify_logs(client, marker, expected, deadline):
    seen = set()
    cursor = None
    cursors = set()
    pages = 0
    while True:
        query = {'project_id': client.project, 'request_id': marker, 'limit': 100}
        if cursor is not None:
            query['cursor'] = cursor
        path = '/api/v1/logs?' + urllib.parse.urlencode(query)
        status, page, _, _ = client.retried('GET', path, deadline=deadline)
        if status != 200 or not isinstance(page, dict) or not isinstance(page.get('items'), list):
            raise ProtocolError('Search verification failed')
        pages += 1
        for item in page['items']:
            if not isinstance(item, dict) or item.get('request_id') != marker or not isinstance(item.get('id'), str):
                raise ProtocolError('Wrong log in search response')
            eid = item['id']
            if eid in seen:
                raise ProtocolError('Duplicate event across search pages')
            seen.add(eid)
        cursor = page.get('next_cursor')
        if not cursor:
            break
        if not page['items'] or cursor in cursors:
            raise ProtocolError('Search pagination stalled')
        cursors.add(cursor)
        if pages > 20000:
            raise ProtocolError('Search page safety cap reached')
    if seen != expected:
        raise ProtocolError(f'Durable verification mismatch (expected={len(expected)}, found={len(seen)})')
    return pages


def phase_plan(args, phase):
    if phase == 'stress':
        return [(args.stress_seconds / 3, args.rate * factor) for factor in (1, 2, 4)]
    if phase == 'sustained':
        return [(args.sustained_seconds, args.rate)]
    if phase == 'soak':
        return [(args.soak_seconds, args.rate)]
    if phase == 'fault':
        return [(args.fault_seconds, args.rate)]
    raise ValueError('Unknown scenario')


def run_phase(client, phase, args):
    plan = phase_plan(args, phase)
    marker = 'perf-' + secrets.token_hex(9) + '-' + phase
    histogram = Histogram()
    read_histogram = Histogram()
    read_errors = Counter()
    minute_histograms = {}
    step_counts = []
    expected = set()
    jobs = {}
    errors = Counter()
    retries = 0
    replayed = 0
    delivered = 0
    attempts = 0
    scheduled = 0
    start = time.monotonic()
    verification_deadline = start + sum(seconds for seconds, _ in plan) + args.drain_seconds
    ctx = injected_network(client) if phase == 'fault' else None

    def execute(origin, proxy):
        nonlocal scheduled, delivered, retries, replayed, attempts
        read_stop = threading.Event()
        read_path = '/api/v1/logs?' + urllib.parse.urlencode({
            'project_id': client.project, 'request_id': marker, 'limit': 20})
        def reader():
            next_read = time.monotonic()
            while not read_stop.is_set():
                wait_time = next_read - time.monotonic()
                if wait_time > 0 and read_stop.wait(wait_time):
                    break
                now = time.monotonic()
                if read_stop.is_set():
                    break
                try:
                    status, data, elapsed, _ = client.retried('GET', read_path,
                        deadline=verification_deadline, origin=origin, max_attempts=3)
                    if status != 200 or not isinstance(data, dict) or not isinstance(data.get('items'), list):
                        raise ProtocolError('Concurrent search failed')
                    read_histogram.add(elapsed)
                except (ProtocolError, TransportError):
                    read_errors['failed'] += 1
                next_read = max(next_read + 1 / args.read_rps, now)
        reader_thread = threading.Thread(target=reader, daemon=True)
        reader_thread.start()
        try:
            with ThreadPoolExecutor(max_workers=args.concurrency) as executor:
                pending = set()
                count = 0
                for seconds, rate in plan:
                    initial_scheduled = scheduled
                    segment_start = time.monotonic()
                    segment_end = segment_start + seconds
                    next_tick = segment_start
                    while next_tick < segment_end:
                        if count >= args.max_requests:
                            raise ProtocolError('Maximum request cap reached before phase duration')
                        now = time.monotonic()
                        if now < next_tick:
                            time.sleep(min(next_tick - now, .05))
                            continue
                        # Never queue unlimited futures if the target cannot keep up.
                        if len(pending) >= args.concurrency * 2:
                            done, pending = wait(pending, timeout=0, return_when=FIRST_COMPLETED)
                            for future in done:
                                accept(future)
                            if len(pending) >= args.concurrency * 2:
                                errors['overload'] += 1
                                next_tick += 1 / rate
                                continue
                        fut = executor.submit(write_one, client, marker, count, args.batch,
                                              args.async_percent, origin, verification_deadline)
                        pending.add(fut)
                        scheduled += 1
                        count += 1
                        next_tick += 1 / rate
                        # Drain finished futures frequently to keep only bounded state.
                        done, pending = wait(pending, timeout=0, return_when=FIRST_COMPLETED)
                        for future in done:
                            accept(future)
                    step_counts.append({'target_rps': rate, 'seconds': seconds, 'scheduled': scheduled - initial_scheduled})
                    # Include the entire planned duration, not merely the last send time.
                    if time.monotonic() < segment_end:
                        time.sleep(segment_end - time.monotonic())
                for future in pending:
                    accept(future)
        finally:
            read_stop.set()
            reader_thread.join(timeout=12)
        return dict(proxy.counters) if proxy else {}

    def accept(future):
        nonlocal delivered, retries, replayed, attempts
        attempts += 1
        try:
            n, ids, job, elapsed, tries, replay, finished = future.result()
            histogram.add(elapsed)
            minute = max(0, int((finished - start) / 60))
            minute_histograms.setdefault(minute, Histogram()).add(elapsed)
            retries += tries - 1
            replayed += int(replay)
            delivered += n
            if ids:
                for eid in ids:
                    if eid in expected:
                        raise ProtocolError('Receipt contains globally duplicated event ID')
                    expected.add(eid)
            if job:
                if job[0] in jobs:
                    raise ProtocolError('Duplicate job ID')
                jobs[job[0]] = job[1]
        except (TransportError, ProtocolError, ValueError, KeyError, TypeError) as exc:
            errors[type(exc).__name__] += 1

    if ctx:
        with ctx as (origin, proxy):
            fault_stats = execute(origin, proxy)
    else:
        fault_stats = execute(client.origin, None)
    admission_seconds = time.monotonic() - start
    try:
        if jobs:
            ids = drain_jobs(client, jobs, verification_deadline)
            for eid in ids:
                if eid in expected:
                    raise ProtocolError('Duplicate async event ID')
                expected.add(eid)
        pages = verify_logs(client, marker, expected, verification_deadline)
        verification = 'passed'
    except (ProtocolError, TransportError) as exc:
        errors['verification_' + type(exc).__name__] += 1
        verification = 'failed'
        pages = 0
    target_duration = sum(seconds for seconds, _ in plan)
    error_count = sum(errors.values())
    error_rate = error_count / max(1, scheduled + errors.get('overload', 0))
    rate = scheduled / max(admission_seconds, .001)
    summary = histogram.summarize()
    p95_limit = args.fault_p95_ms if phase == 'fault' else args.p95_ms
    minute_stats = [{'minute': i, **hist.summarize()} for i, hist in sorted(minute_histograms.items())]
    # Short overload spikes cannot be masked by a long good soak interval.
    windows_ok = all(item['p95_ms'] <= p95_limit for item in minute_stats)
    steps_ok = all(item['scheduled'] >= item['target_rps'] * item['seconds'] * args.min_rate_ratio for item in step_counts)
    reads_ok = read_histogram.count > 0 and not read_errors and read_histogram.summarize()['p95_ms'] <= p95_limit
    # A scheduler that admitted work but never completed it cannot qualify as throughput.
    # Count only requests with durable HTTP receipts, not attempts waiting in the executor.
    target_rate = sum(rate * seconds for seconds, rate in plan) / target_duration
    effective_rate = (delivered / args.batch) / max(admission_seconds, .001)
    gates = {
        'completion_accounting': attempts == scheduled and histogram.count == (delivered // args.batch),
        'achieved_rate': effective_rate >= target_rate * args.min_rate_ratio * (
            1 - (args.fault_error_rate if phase == 'fault' else args.max_error_rate)),
        'durable_consistency': verification == 'passed' and len(expected) == delivered,
        'error_rate': error_rate <= (args.fault_error_rate if phase == 'fault' else args.max_error_rate),
        'p95_latency': summary.get('p95_ms', float('inf')) <= p95_limit,
        'offered_rate': scheduled >= sum(rate * seconds for seconds, rate in plan) * args.min_rate_ratio,
        'run_duration': admission_seconds >= target_duration * .98,
        'per_step_offered_rate': steps_ok,
        'minute_p95_latency': windows_ok,
        'concurrent_search': reads_ok,
    }
    if phase == 'fault':
        gates['faults_exercised'] = all(fault_stats.get(k, 0) > 0 for k in ('pre503', 'pre429', 'lost_ack', 'delay', 'upstream_success'))
        gates['ambiguous_commit_recovered'] = replayed > 0
    return {'scenario': phase, 'marker': marker, 'status': 'passed' if all(gates.values()) else 'failed',
            'duration_target_seconds': target_duration, 'admission_seconds': round(admission_seconds, 3),
            'verification_seconds': round(time.monotonic() - start - admission_seconds, 3),
            'requested_rate_per_second': [r for _, r in plan], 'step_schedule': step_counts,
            'minute_latency': minute_stats, 'concurrent_search_latency': read_histogram.summarize(),
            'concurrent_search_errors': dict(read_errors), 'scheduled_requests': scheduled,
            'completed_requests': attempts, 'durable_events': delivered, 'verified_events': len(expected) if verification == 'passed' else 0,
            'throughput_requests_per_second': round(rate, 3), 'effective_ingest_rps': round(effective_rate, 3), 'latency': summary,
            'errors': dict(errors), 'error_rate': round(error_rate, 6), 'retry_attempts': retries,
            'idempotent_replays': replayed, 'async_jobs': len(jobs), 'search_pages': pages,
            'fault_injection': fault_stats, 'gates': gates}


def validate_args(args):
    check_origin(args.url)
    if not math.isfinite(args.rate) or not math.isfinite(args.read_rps) or not .1 <= args.read_rps <= 20 or not 1 <= args.rate <= 2000 or not 1 <= args.concurrency <= 64 or not 1 <= args.batch <= 100:
        raise ValueError('Rate/concurrency/batch must be within 1..2000 / 1..64 / 1..100')
    if not math.isfinite(args.max_error_rate) or not math.isfinite(args.fault_error_rate) or not 0 <= args.async_percent <= 100 or not 0 <= args.max_error_rate <= 1 or not 0 <= args.fault_error_rate <= 1:
        raise ValueError('Percentages/rates out of range')
    if not math.isfinite(args.min_rate_ratio) or not .1 <= args.min_rate_ratio <= 1 or not 1 <= args.max_requests <= 2_000_000 or not 10 <= args.drain_seconds <= 3600:
        raise ValueError('Rate ratio, request cap or drain time out of range')
    if any(not math.isfinite(x) or not 0.5 <= x <= 86400 for x in (args.sustained_seconds, args.stress_seconds, args.soak_seconds, args.fault_seconds)):
        raise ValueError('Phase durations must be 0.5..86400 seconds')
    if min(args.p95_ms, args.fault_p95_ms) <= 0:
        raise ValueError('Latency gates must be positive')
    if args.certify:
        if urllib.parse.urlsplit(args.url).scheme != 'https':
            raise ValueError('Certification requires verified HTTPS')
        if args.scenario != 'all' or args.sustained_seconds < 60 or args.stress_seconds < 120 or args.soak_seconds < 900 or args.fault_seconds < 60:
            raise ValueError('Certification requires all phases (sustained>=60s, stress>=120s, soak>=900s, fault>=60s)')
        if args.async_percent != 100 or args.batch < 2:
            raise ValueError('Certification requires 100% async traffic and multi-event batches')
        if not args.archive:
            raise ValueError('Certification requires --archive to bind results to a verified distribution')


def get_release_metadata(args, client):
    import release
    meta = {'test_version': VERSION}
    if args.archive:
        path = Path(args.archive)
        version = release.verify(path)['version']
        if version != VERSION:
            raise ValueError('Archive must be version ' + VERSION)
        meta.update(archive_sha256=hashlib.sha256(path.read_bytes()).hexdigest(), version=version)
    if args.certify:
        status, ready, _, _ = client.request('GET', '/ready')
        if status != 200 or not isinstance(ready, dict) or ready.get('version') != VERSION or ready.get('status') != 'ok':
            raise ValueError('Live /ready version and health must match the certified distribution')
        meta['readiness_confirmed'] = True
    return meta


def atomic_json(path, data):
    target = Path(path)
    target.parent.mkdir(parents=True, exist_ok=True)
    temporary = target.with_name('.' + target.name + '-' + secrets.token_hex(5))
    try:
        with temporary.open('x', encoding='utf-8') as output:
            json.dump(data, output, indent=2, sort_keys=True)
            output.write('\n')
            output.flush()
            os.fsync(output.fileno())
        os.replace(temporary, target)
    finally:
        temporary.unlink(missing_ok=True)


def run(args):
    validate_args(args)
    client = Client(args.url, os.environ.get('CAJEER_TOKEN', ''), args.project, args.source,
                    args.ca_file, os.environ.get('CAJEER_SIGNING_KEY_ID'), os.environ.get('CAJEER_SIGNING_SECRET'))
    meta = get_release_metadata(args, client)
    report = {'format': 1, 'scope': 'load-performance', **meta, 'started_at': datetime.now(timezone.utc).isoformat(),
              'target_origin': client.origin, 'project_id': client.project, 'certification_requested': bool(args.certify),
              'certified': False, 'status': 'failed', 'thresholds': {'p95_ms': args.p95_ms,
              'fault_p95_ms': args.fault_p95_ms, 'max_error_rate': args.max_error_rate,
              'fault_error_rate': args.fault_error_rate, 'min_rate_ratio': args.min_rate_ratio}, 'phases': []}
    try:
        phases = PHASES if args.scenario == 'all' else (args.scenario,)
        for phase in phases:
            result = run_phase(client, phase, args)
            report['phases'].append(result)
            if args.output:
                atomic_json(args.output, report)
            if result['status'] != 'passed':
                break
        report['status'] = 'passed' if len(report['phases']) == len(phases) and all(p['status'] == 'passed' for p in report['phases']) else 'failed'
        report['certified'] = bool(args.certify and report['status'] == 'passed' and len(report['phases']) == 4)
    except (OSError, ProtocolError, TransportError, ValueError) as exc:
        report['error'] = type(exc).__name__ + ': ' + str(exc)
    finally:
        report['finished_at'] = datetime.now(timezone.utc).isoformat()
        if args.output:
            atomic_json(args.output, report)
    return report


def parser():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--url', required=True, help='HTTPS origin of disposable CajeerLogs instance')
    p.add_argument('--project', required=True, help='Disposable project UUID')
    p.add_argument('--source', required=True, help='Source authorized for CAJEER_TOKEN')
    p.add_argument('--ca-file', help='CA certificate for the real HTTPS target')
    p.add_argument('--scenario', choices=(*PHASES, 'all'), default='all')
    p.add_argument('--rate', type=float, default=2, help='Target ingest requests/sec (stress ramps x1,x2,x4)')
    p.add_argument('--read-rps', type=float, default=1, help='Concurrent search queries/sec during all phases')
    p.add_argument('--batch', type=int, default=3)
    p.add_argument('--concurrency', type=int, default=8)
    p.add_argument('--async-percent', type=int, default=20)
    p.add_argument('--sustained-seconds', type=float, default=60)
    p.add_argument('--stress-seconds', type=float, default=120)
    p.add_argument('--soak-seconds', type=float, default=900)
    p.add_argument('--fault-seconds', type=float, default=60)
    p.add_argument('--drain-seconds', type=int, default=180)
    p.add_argument('--max-requests', type=int, default=100000)
    p.add_argument('--p95-ms', type=int, default=2000)
    p.add_argument('--fault-p95-ms', type=int, default=4000)
    p.add_argument('--max-error-rate', type=float, default=.01)
    p.add_argument('--fault-error-rate', type=float, default=.01)
    p.add_argument('--min-rate-ratio', type=float, default=.95)
    p.add_argument('--archive', help='Full 0.6.2 release distribution, for SHA256 binding')
    p.add_argument('--certify', action='store_true', help='Require full durations, actual /ready version and complete 4-phase gate')
    p.add_argument('--output', help='Atomic JSON evidence report path')
    p.add_argument('--confirm-disposable', action='store_true', help='Required: write durable logs to a disposable instance/project')
    return p


def main():
    p = parser()
    args = p.parse_args()
    if not args.confirm_disposable or os.environ.get('CAJEER_LOAD_TEST') != '1':
        p.error('Requires --confirm-disposable and CAJEER_LOAD_TEST=1 (writes real logs)')
    try:
        result = run(args)
    except (ValueError, OSError, TransportError, ProtocolError) as exc:
        p.exit(2, 'Load test configuration failed: ' + str(exc) + '\n')
    print(json.dumps(result, indent=2, sort_keys=True))
    if result['status'] != 'passed':
        raise SystemExit(1)


if __name__ == '__main__':
    main()
