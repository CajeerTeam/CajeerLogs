#!/usr/bin/env python3
"""0.6.5 Nginx importer integration tests: real HTTP/HMAC, SQLite, rotations."""
from __future__ import annotations
import hashlib
import hmac
import json
import os
import shutil
import socket
import sys
import tempfile
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from zoneinfo import ZoneInfo

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'clients/python'))
from cajeer_logs_client import CajeerLogsClient, DeliveryError
from cajeer_logs_collector import Collector, CollectorError, parse_line

TOKEN = 'cl_' + 'b' * 64
ZONE = ZoneInfo('UTC')
SEEN = {}
FAIL = {'mode': ''}


class Ingest(BaseHTTPRequestHandler):
    def log_message(self, *_): pass

    def do_POST(self):
        body = self.rfile.read(int(self.headers['Content-Length']))
        key = self.headers.get('Idempotency-Key')
        nonce = self.headers.get('X-Log-Nonce')
        assert self.path == '/api/v1/ingest' and self.headers['Authorization'] == 'Bearer ' + TOKEN
        canonical = '\n'.join(('cajeerlogs-ingest-v1', 'POST', self.path, self.headers['X-Log-Timestamp'], nonce, key, hashlib.sha256(body).hexdigest()))
        assert hmac.compare_digest(self.headers['X-Log-Signature'], hmac.new(TOKEN.encode(), canonical.encode(), hashlib.sha256).hexdigest())
        if key in SEEN:
            previous_body, nonces = SEEN[key]
            assert previous_body == body and nonce not in nonces
            nonces.add(nonce)
            status = 200
        else:
            SEEN[key] = (body, {nonce})
            status = 201
        if FAIL['mode'] == '503':
            self.send_response(503); self.send_header('Content-Length', '0'); self.end_headers(); return
        if FAIL['mode'] == 'lost':
            FAIL['mode'] = ''
            self.connection.shutdown(socket.SHUT_RDWR); self.connection.close(); return
        data = json.loads(body)
        n = len(data['events'])
        response = json.dumps({'accepted': True, 'received': n, 'stored': n if status == 201 else 0,
                               'rejected': 0, 'replayed': status == 200,
                               'event_ids': [f'00000000-0000-4000-8000-{i+1:012x}' for i in range(n)]}).encode()
        self.send_response(status)
        self.send_header('Content-Length', str(len(response)))
        self.send_header('Content-Type', 'application/json')
        self.end_headers(); self.wfile.write(response)


def access(path, status=200):
    return f'127.0.0.1 - - [01/Jan/2026:13:00:00 +0000] "GET {path}?secret=do-not-send HTTP/1.1" {status} 12 "https://secret.local" "private-agent"\n'.encode()


def checked(value, label):
    assert value, label
    print('PASS', label, flush=True)


structured = {'time_iso8601': '2026-01-01T13:00:00+00:00', 'remote_addr': '2001:db8::1',
              'request': 'GET /account?token=private HTTP/1.1', 'status': '503',
              'body_bytes_sent': '42', 'request_time': '0.125',
              'http_referer': 'https://private.example', 'http_user_agent': 'secret-agent'}
event = parse_line((json.dumps(structured) + '\n').encode(), 'nginx-json', 'nginx', ZONE)
checked(event['context']['path'] == '/account' and event['context']['request_time_ms'] == 125 and event['level'] == 'error',
        'Nginx JSON parser: status, duration, UTC and sanitized URL')
checked('private' not in json.dumps(event) and 'secret-agent' not in json.dumps(event),
        'Nginx JSON parser does not transmit query, referer, agent')
structured2 = structured | {'request': None, 'request_method': 'POST', 'request_uri': '/api?token=other', 'server_protocol': 'HTTP/2', 'status': 404}
checked(parse_line(json.dumps(structured2).encode(), 'nginx-json', 'nginx', ZONE)['level'] == 'warning',
        'Nginx JSON alternative field mapping')
for bad in [b'{"status":200,"status":201}\n', json.dumps(structured | {'status': 999}).encode(),
            json.dumps(structured | {'request_time': 'nan'}).encode(),
            json.dumps(structured | {'time_iso8601': '2026-01-01T13:00:00'}).encode(),
            json.dumps(structured | {'request': 'GET bad-target HTTP/1.1'}).encode()]:
    try: parse_line(bad, 'nginx-json', 'nginx', ZONE)
    except ValueError: pass
    else: raise AssertionError('invalid Nginx JSON accepted')
checked(True, 'Nginx JSON rejects bad fields, duplicate keys and unsafe formats')
checked(parse_line(access('/a'), 'nginx-access', 'nginx', ZONE)['message'] == 'GET /a HTTP/1.1 200',
        'Nginx combined parser retains supported legacy format')

server = ThreadingHTTPServer(('127.0.0.1', 0), Ingest)
server.daemon_threads=True
server.block_on_close=False
thread = threading.Thread(target=server.serve_forever, daemon=True)
thread.start()
try:
    client = CajeerLogsClient(f'http://127.0.0.1:{server.server_port}', TOKEN, signed=True, timeout=1, attempts=1)
    with tempfile.TemporaryDirectory() as directory:
        root = Path(directory)
        path, state = root/'access.log', root/'checkpoint.sqlite'
        path.write_bytes(access('/first', 200) + access('/second', 502) + access('/third')[:-1])
        def open_collector(copytruncate=False):
            return Collector(path, state, client, 'nginx', 'nginx-access', ZONE,
                             batch=1, copytruncate=copytruncate)
        col = open_collector(True)
        try:
            FAIL['mode'] = '503'
            try: col.step()
            except DeliveryError: pass
            else: raise AssertionError('503 must retain the immutable pending batch')
            pending = col.row()
            checked(pending[2] == 0 and pending[4] and pending[5], 'durable pending batch before server acknowledgement')
        finally: col.close()
        FAIL['mode'] = 'lost'
        col = open_collector(True)
        try:
            try: col.step()
            except DeliveryError: pass
            else: raise AssertionError('lost response should retain pending batch')
        finally: col.close()
        col = open_collector(True)
        try:
            checked(col.step() == 1 and col.row()[2] == len(access('/first')), 'restart replays identical signed HTTP batch')
            checked(col.step() == 1 and col.row()[2] == len(access('/first'))+len(access('/second', 502)),
                    'confirmed incremental checkpoint advances after ingest')
            checked(col.step() == 0, 'incomplete final line remains uncommitted')
            # Actual logrotate copytruncate: archived copy is a different inode,
            # active inode was truncated and re-used for newly appended records.
            with path.open('ab') as f: f.write(b'\n')
            archive = root/'access.log.1'
            shutil.copyfile(path, archive)
            path.write_bytes(access('/after-truncate'))
            os.utime(archive, (time.time()-4, time.time()-4))
            checked(col.step() == -1, 'copytruncate detects matching uncompressed archive')
            checked(col.step() == 1, 'archive tail delivered before switching to live file')
            checked(col.step() == -1 and col.step() == 1, 'switch to active inode and resume after copytruncate')
            checked(col.step() == 0, 'copytruncate incremental steady state')
        finally: col.close()
        # Source path is still the same inode; snapshot archives are retired.
        col = open_collector(True)
        try:
            checked(col.step() == 0, 'collector restarts from committed post-copytruncate checkpoint')
            with path.open('ab') as f: f.write(access('/next'))
            checked(col.step() == 1, 'post-restart incremental event delivered')
        finally: col.close()
        checked(len(SEEN) >= 4 and all(b'do-not-send' not in body for body, _ in SEEN.values()),
                'real HTTP verifies HMAC, idempotency and URL-secret filtering')
    with tempfile.TemporaryDirectory() as directory:
        root = Path(directory)
        path, state = root/'access.log', root/'checkpoint.sqlite'
        path.write_bytes(access('/original'))
        col = Collector(path, state, client, 'nginx', 'nginx-access', ZONE, copytruncate=True)
        try:
            checked(col.step() == 1, 'original log checkpoint')
            path.write_bytes(access('/x')[:10])
            try: col.step()
            except CollectorError: pass
            else: raise AssertionError('missing copytruncate archive must fail closed')
            checked(col.row()[2] == len(access('/original')), 'no checkpoint advance without verified archive')
        finally: col.close()
    with tempfile.TemporaryDirectory() as directory:
        root = Path(directory)
        path, state = root/'access.log', root/'checkpoint.sqlite'
        path.write_bytes(access('/original'))
        col = Collector(path, state, client, 'nginx', 'nginx-access', ZONE, copytruncate=True)
        try:
            col.step()
            archive = root/'access.log.1'; archive.write_bytes(access('/original'))
            archive2 = root/'access.log.2'; archive2.write_bytes(access('/original'))
            path.write_bytes(access('/x')[:10])
            os.utime(archive, (time.time()-4,time.time()-4)); os.utime(archive2, (time.time()-4,time.time()-4))
            try: col.step()
            except CollectorError as error: checked('Ambiguous' in str(error), 'ambiguous matching archives halt collection')
            else: raise AssertionError('ambiguous copytruncate archives must fail')
        finally: col.close()
finally:
    stopper=threading.Thread(target=server.shutdown,daemon=True)
    stopper.start();stopper.join(timeout=5)
    server.server_close();thread.join(timeout=5)
print('PASS Nginx importer real HTTP and recovery suite complete')
