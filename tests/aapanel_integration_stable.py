#!/usr/bin/env python3
"""Production aaPanel multi-site lifecycle and HTTP integration (no external services)."""
from __future__ import annotations
import json
import os
from pathlib import Path
import socket
import stat
import subprocess
import sys
import tempfile
import threading
import time
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'clients/python'))
from cajeer_logs_client import CajeerLogsClient
from cajeer_aapanel_manager import discover, execute, load, measured, source_for, ManagerError

TOKEN = 'cl_' + '9' * 64
RECEIVED = {}
FAIL_SOURCE = {'source': None}

class API(BaseHTTPRequestHandler):
    def log_message(self, *args): pass
    def do_POST(self):
        if self.path != '/api/v1/ingest' or self.headers.get('Authorization') != 'Bearer ' + TOKEN:
            self.send_response(401); self.end_headers(); return
        body = self.rfile.read(int(self.headers['Content-Length']))
        data = json.loads(body)
        events = data['events']; source = events[0]['source']; key = self.headers['Idempotency-Key']
        if source == FAIL_SOURCE['source']:
            self.send_response(503); self.send_header('Content-Length', '0'); self.end_headers(); return
        if key in RECEIVED:
            assert RECEIVED[key] == body
            status = 200
        else:
            RECEIVED[key] = body
            status = 201
        reply = json.dumps({'accepted': True, 'received': len(events), 'stored': len(events) if status == 201 else 0,
                            'rejected': 0, 'replayed': status == 200,
                            'event_ids': [f'00000000-0000-4000-8000-{j+1:012x}' for j in range(len(events))]}).encode()
        self.send_response(status); self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(reply)))
        self.end_headers(); self.wfile.write(reply)


def line(name):
    return f'127.0.0.1 - - [01/Jan/2026:13:00:00 +0000] "GET /{name}?secret=hidden HTTP/1.1" 200 20 "https://private.example" "private-agent"\n'.encode()

def shell(*args, success=True):
    result = subprocess.run([sys.executable, str(ROOT / 'clients/python/cajeer_aapanel_manager.py'), *args],
                            capture_output=True, text=True, timeout=30)
    assert (result.returncode == 0) == success, (result.returncode, result.stderr)
    return json.loads(result.stdout) if result.stdout else None


def check(pred, description):
    assert pred, description
    print('PASS', description, flush=True)

server = ThreadingHTTPServer(('127.0.0.1', 0), API)
server.daemon_threads=True
server.block_on_close=False
thread = threading.Thread(target=server.serve_forever, daemon=True)
thread.start()
try:
    client = CajeerLogsClient(f'http://127.0.0.1:{server.server_port}', TOKEN, attempts=1, timeout=1)
    with tempfile.TemporaryDirectory() as tmp:
        root = Path(tmp)
        private = root/'private'; private.mkdir(mode=0o700)
        logs = root/'logs'; logs.mkdir()
        config = private/'aapanel.json'; state = root/'states'
        (logs/'alpha.example.log').write_bytes(line('one')+line('two'))
        (logs/'alpha.example.error.log').write_text('2026/01/01 13:01:00 [error] site error\n')
        (logs/'beta.example.log').write_bytes(line('beta'))
        (logs/'junk.log').write_bytes(line('junk'))
        (logs/'other.example.log.gz').write_bytes(line('gz'))
        (logs/'linked.example.log').symlink_to(logs/'beta.example.log')
        shell('--config', str(config), 'init', '--logs-dir', str(logs), '--state-dir', str(state))
        check(stat.S_IMODE(config.stat().st_mode)==0o600 and stat.S_IMODE(state.stat().st_mode)==0o700,
              'operator config 0600 and state directory 0700')
        listing = shell('--config', str(config), 'discover')
        check(listing['added'] == 3, 'auto discovers only real per-site access/error logs')
        data = load(config); check(len(set(x['source'] for x in data['streams']))==3, 'independent source slugs')
        check(all(x['enabled'] is False for x in data['streams']), 'new sources require explicit enrollment')
        check(shell('--config', str(config), 'discover')['added']==0, 'idempotent discovery')
        check(shell('--config', str(config), 'status', success=False)['stale'],
              'status fails health check before first manager run')
        shell('--config', str(config), 'set', '--id', data['streams'][0]['id'], '--timezone', 'Invalid/Zone', success=False)
        check(load(config) == data, 'invalid timezone rejected without modifying config')
        check(shell('--config', str(config), 'list') == data['streams'], 'CLI list exposes current settings')
        for entry in data['streams']:
            shell('--config', str(config), 'set', '--id', entry['id'], '--enable')
        data = load(config)
        beta = next(x for x in data['streams'] if x['site']=='beta.example')
        FAIL_SOURCE['source'] = beta['source']
        check(execute(config, True, False, .1, 2, client=client)==1,
              'a failing site does not block the other sites; failure reflected in exit status')
        status = shell('--config', str(config), 'status', success=False)
        check(status['streams'][beta['id']]['error'] is not None, 'per-site failure visible in status JSON')
        others = [v for v in status['streams'].values() if v['id'] != beta['id']]
        check(all(v['error'] is None and v['last_success'] for v in others),
              'unaffected streams committed independently')
        check(len(RECEIVED)==2, 'other access and error logs delivered to ingest API')
        FAIL_SOURCE['source'] = None
        check(execute(config, True, False, .1, 2, client=client)==0, 'failed site resumes without reimporting peers')
        check(len(RECEIVED)==3, 'one HTTP transmission per site/kind with stable idempotency')
        check(all(b'hidden' not in body and b'private-agent' not in body for body in RECEIVED.values()),
              'access URL query and user-agent not leaked')
        status = shell('--config', str(config), 'status')
        check(status['healthy'] is True and status['stale'] is False, 'fresh monitor reports healthy state')
        check(all(x['lag_bytes']==0 and x['error'] is None for x in status['streams'].values()),
              'monitoring exposes per-site zero backlog and success')
        check(execute(config, True, False, .1, 2, client=client)==0 and len(RECEIVED)==3,
              'restart leaves all site checkpoints intact')
        check(status['updated_at'] is not None and all(v['delivered'] >= 1 for v in status['streams'].values()),
              'durable monitoring cumulative event counters')
        # Rotation on one stream must not disturb other site/state.
        primary = logs/'alpha.example.log'; rotated = logs/'alpha.example.log.1'
        primary.rename(rotated)
        primary.write_bytes(line('new-after-rename'))
        os.utime(rotated, (time.time()-4, time.time()-4))
        check(execute(config, True, False, .1, 2, client=client)==0 and len(RECEIVED)==4,
              'rename rotation on one site retains its independent checkpoint')
        # New site is discovered but not automatically ingested until enabled.
        (logs/'gamma.example.log').write_bytes(line('gamma'))
        new = shell('--config', str(config), 'discover')
        check(new['added']==1, 'newly created aaPanel site discovered after first setup')
        check(execute(config, True, False, .1, 2, client=client)==0 and len(RECEIVED)==4,
              'new site remains disabled until operator enables its source')
        shell('--config', str(config), 'set', '--id', new['stream_ids'][0], '--enable')
        check(execute(config, True, False, .1, 2, client=client)==0 and len(RECEIVED)==5,
              'newly enabled site starts independent ingestion')
        # Source changes cannot silently re-route existing checkpointed logs.
        shell('--config', str(config), 'set', '--id', beta['id'], '--source', 'other-source', success=False)
        # A failed attempt is rejected without losing its checkpoint.
        check(load(config)['streams'] == shell('--config', str(config), 'list'), 'failed re-route leaves config unchanged')
        php = subprocess.run(['php', str(ROOT/'bin/cajeer'), 'import:aapanel', '--config', str(config), 'list'],
                             capture_output=True, text=True)
        check(php.returncode == 0 and json.loads(php.stdout)==load(config)['streams'],
              'PHP CLI dispatches managed aaPanel mode; legacy collector remains untouched')
        # Disabled source is retained in monitoring but does not ingest.
        shell('--config', str(config), 'set', '--id', beta['id'], '--disable')
        (logs/'beta.example.log').write_bytes(line('beta')+line('unconsumed'))
        check(execute(config, True, False, .1, 2, client=client)==0 and len(RECEIVED)==5,
              'disabled site does not send events')
        # Refuse unsafe configuration filesystem and path substitutions.
        os.chmod(config, 0o644)
        shell('--config', str(config), 'list', success=False)
        os.chmod(config, 0o600)
        (logs/'gamma.example.log').unlink()
        (logs/'gamma.example.log').symlink_to(logs/'beta.example.log')
        check(execute(config, True, False, .1, 2, client=client)==1,
              'symlinked active log fails closed without affecting other sources')
    print('aaPanel multi-site HTTP certification passed', flush=True)
finally:
    # A live HTTP/1.1 fixture may retain an idle request handler. Never let
    # cleanup of a disposable fixture stall the integration process indefinitely.
    stopper=threading.Thread(target=server.shutdown,daemon=True)
    stopper.start();stopper.join(timeout=5)
    server.server_close();thread.join(timeout=5)
