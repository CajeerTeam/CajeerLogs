#!/usr/bin/env python3
"""HTTP, SQLite, concurrent-process and stdlib logging acceptance for Python SDK."""
from __future__ import annotations

import hashlib
import hmac
import json
import logging
import os
from pathlib import Path
import socket
import sqlite3
import subprocess
import sys
import tempfile
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import urllib.error

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'clients/python'))
from cajeer_batch_spool import DurableBatchSpool, SpoolFull, SpoolError, SpoolExpired
from cajeer_logging import CajeerLogsBufferedHandler
from cajeer_logs_client import CajeerLogsClient

TOKEN = 'cl_' + 'a' * 64
writes = {}
request_log = {}
errors = []
healthy = False
lock = threading.Lock()


def event(message: str):
    return {'timestamp': '2026-11-18T12:00:00Z', 'source': 'app', 'level': 'info', 'message': message}


class Receiver(BaseHTTPRequestHandler):
    # No persistent fixture connections: cleanup must not depend on a client closing idle sockets.
    protocol_version = 'HTTP/1.0'

    def log_message(self, *_):
        pass

    def do_POST(self):
        try:
            assert self.path == '/api/v1/ingest'
            assert self.headers['Authorization'] == 'Bearer ' + TOKEN
            body = self.rfile.read(int(self.headers['Content-Length']))
            key = self.headers['Idempotency-Key']
            nonce = self.headers['X-Log-Nonce']
            canonical = '\n'.join(['cajeerlogs-ingest-v1','POST',self.path,
                                   self.headers['X-Log-Timestamp'], nonce, key,
                                   hashlib.sha256(body).hexdigest()])
            assert hmac.compare_digest(self.headers['X-Log-Signature'],
                                       hmac.new(TOKEN.encode(), canonical.encode(), hashlib.sha256).hexdigest())
            events = json.loads(body)['events']
            assert 1 <= len(events) <= 100 and len(body) <= 1048576
            with lock:
                previous = request_log.setdefault(key, [])
                assert all(old[0] == body and old[1] != nonce for old in previous)
                previous.append((body, nonce))
                kind = events[0]['message']
                if kind.startswith('denied-'):
                    status = 401
                elif kind.startswith('outage-') and not healthy:
                    status = 503
                elif key in writes:
                    status = 200
                else:
                    status = 201
                if status == 201:
                    writes[key] = events
                if status == 201 and kind.startswith('lost-'):
                    self.connection.shutdown(socket.SHUT_RDWR)
                    self.connection.close()
                    return
                ids = [f'00000000-0000-4000-8000-{i+1:012x}' for i in range(len(events))]
                payload = (dict(accepted=True,received=len(events),stored=len(events) if status==201 else 0,
                                rejected=0,replayed=status==200,event_ids=ids) if status in (200,201) else {})
            raw = json.dumps(payload).encode()
            self.send_response(status)
            self.send_header('Content-Type','application/json')
            self.send_header('Content-Length', str(len(raw)))
            self.end_headers()
            self.wfile.write(raw)
        except Exception as exc:
            errors.append(repr(exc))
            try: self.send_error(500)
            except OSError: pass


def client(url, **kwargs):
    return CajeerLogsClient(url,TOKEN,signed=True,attempts=1,timeout=1,**kwargs)


def test_batch(url, home):
    with DurableBatchSpool(home/'batch.sqlite',client(url),batch_events=3,max_events=5) as spool:
        for i in range(5): spool.enqueue(event('batch-'+str(i)))
        try: spool.enqueue(event('overflow'))
        except SpoolFull: pass
        else: raise AssertionError('Spool capacity must reject without enqueue')
        assert spool.status()['queued_events'] == 5
        assert spool.status()['queued_bytes'] > 0
        assert spool.flush() == 5
        assert spool.status()['queued_events'] == 0
        assert spool.status()['delivered_events'] == 5 and spool.status()['delivered_batches'] == 2
        assert spool.flush() == 0
    groups = [group for group in writes.values() if group[0]['message'].startswith('batch-')]
    assert sorted(map(len, groups)) == [2,3]


def test_lost_and_restart(url, home):
    path = home/'lost.sqlite'
    with DurableBatchSpool(path,client(url),batch_events=2) as spool:
        spool.enqueue(event('lost-one')); spool.enqueue(event('lost-two'))
        try: spool.flush(); raise AssertionError('A lost receipt was acknowledged')
        except Exception as exc: assert type(exc).__name__ in ('DeliveryError','HTTPError')
        assert spool.status()['pending_batches'] == 1
    with DurableBatchSpool(path,client(url)) as spool:
        assert spool.flush()==2 and spool.status()['queued_events']==0
    key = next(k for k,v in request_log.items() if b'lost-one' in v[0][0])
    assert len(request_log[key]) == 2 and len(writes[key]) == 2


def test_outage_and_expiry(url, home):
    global healthy
    path = home/'outage.sqlite'
    with DurableBatchSpool(path,client(url)) as spool:
        spool.enqueue(event('outage-one'))
        try: spool.flush(); raise AssertionError('Outage was not raised')
        except Exception as exc: assert type(exc).__name__ == 'DeliveryError'
        assert spool.status()['pending_batches'] == 1
        with spool.db:
            spool.db.execute('UPDATE batches SET first_sent_at=?', (time.time()-24*3600,))
        before = sum(len(v) for v in request_log.values())
        try: spool.flush(); raise AssertionError('Old ambiguous delivery replayed')
        except SpoolExpired: pass
        assert sum(len(v) for v in request_log.values()) == before
        with spool.db:
            spool.db.execute('UPDATE batches SET first_sent_at=?', (time.time(),))
        healthy=True
        assert spool.flush() == 1
    healthy=False
    with DurableBatchSpool(home/'denied.sqlite',client(url)) as spool:
        spool.enqueue(event('denied-permanent'))
        try: spool.flush(); raise AssertionError('401 must be surfaced')
        except urllib.error.HTTPError as exc: assert exc.code == 401
        assert spool.status()['queued_events']==1


def test_logging(url, home):
    with DurableBatchSpool(home/'logging.sqlite',client(url),batch_events=4) as spool:
        handler = CajeerLogsBufferedHandler(spool,'app','backend',flush_interval=0,
                                            fail_on_enqueue_error=True)
        logger = logging.getLogger('sdk.stable.' + os.urandom(4).hex())
        logger.handlers.clear();logger.propagate=False;logger.setLevel(logging.DEBUG)
        logger.addHandler(handler)
        logger.info('User %s entered',42,extra={'tenant': 'alpha', 'labels':['one','two']})
        try: raise RuntimeError('expected')
        except RuntimeError: logger.exception('Test exception')
        assert spool.status()['queued_events'] == 2
        assert handler.flush() == 2
        logger.removeHandler(handler);handler.close()
    batch = next(group for group in writes.values() if group[0]['message']=='User 42 entered')
    assert batch[0]['context']['tenant']=='alpha'
    assert batch[0]['service']=='backend' and batch[1]['context']['exception_type']=='RuntimeError'
    assert 'Traceback' in batch[1]['message']
    with DurableBatchSpool(home/'logfull.sqlite',client(url),max_events=1) as spool:
        handler=CajeerLogsBufferedHandler(spool,'app',flush_interval=0,fail_on_enqueue_error=True)
        r=logging.LogRecord('x',logging.INFO,'x.py',10,'hello',(),None)
        handler.emit(r)
        try: handler.emit(r);raise AssertionError('Failed enqueue silently discarded')
        except SpoolFull: assert handler.enqueue_failures==1
        handler.close()


def test_multiprocess(url, home):
    path = home/'multi.sqlite'
    with DurableBatchSpool(path,client(url),batch_events=100,max_events=150):
        pass
    source = '''import sys
from pathlib import Path
from cajeer_batch_spool import DurableBatchSpool
from cajeer_logs_client import CajeerLogsClient
url,path,n=sys.argv[1:]
c=CajeerLogsClient(url, 'cl_'+'a'*64, signed=True,attempts=1)
with DurableBatchSpool(Path(path),c,max_events=150) as spool:
    for i in range(40):
        spool.enqueue({'timestamp':'2026-11-18T12:00:00Z','source':'app','level':'info','message':f'process-{n}-{i}'})
'''
    env = dict(os.environ, PYTHONPATH=str(ROOT/'clients/python'))
    procs = [subprocess.Popen([sys.executable,'-c',source,url,str(path),str(n)],env=env,
                              stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for n in range(3)]
    for proc in procs:
        out,err=proc.communicate(timeout=30)
        assert proc.returncode == 0,(out,err)
    with DurableBatchSpool(path,client(url),batch_events=100,max_events=150) as spool:
        assert spool.status()['queued_events']==120
        assert spool.flush()==120
        assert spool.status()['queued_events']==0
    messages=[e['message'] for group in writes.values() for e in group if e['message'].startswith('process-')]
    assert len(messages)==len(set(messages))==120


def test_cli_and_background(url, home):
    path = home/'cli.sqlite'
    with DurableBatchSpool(path,client(url)) as spool:
        spool.enqueue(event('cli-first'))
    environment = dict(os.environ, CAJEER_URL=url, CAJEER_TOKEN=TOKEN,
                       PYTHONPATH=str(ROOT/'clients/python'))
    def cli(*args):
        result = subprocess.run([sys.executable, '-m', 'cajeer_spool_cli', *args,
                                 '--spool',str(path),'--signed'], env=environment,
                                capture_output=True,text=True,timeout=10)
        assert result.returncode == 0, result.stderr
        return json.loads(result.stdout)
    assert cli('status')['queued_events']==1
    assert cli('flush')['delivered_now']==1
    with DurableBatchSpool(home/'background.sqlite',client(url)) as spool:
        handler=CajeerLogsBufferedHandler(spool,'app',flush_interval=.05)
        record=logging.LogRecord('bg',logging.INFO,'bg.py',1,'background',(),None)
        handler.emit(record)
        deadline=time.monotonic()+4
        while time.monotonic()<deadline and spool.status()['queued_events']:
            time.sleep(.03)
        assert spool.status()['queued_events']==0, handler.last_delivery_error
        handler.close()


def test_security(url, home):
    public=home/'public';public.mkdir(mode=0o755)
    if os.name=='posix':
        try: DurableBatchSpool(public/'x.sqlite',client(url))
        except SpoolError: pass
        else: raise AssertionError('Public spool accepted')
    path=home/'identity.sqlite'
    with DurableBatchSpool(path,client(url)) as spool:
        spool.enqueue(event('secure'))
    try: DurableBatchSpool(path, CajeerLogsClient(url,'cl_'+'b'*64))
    except SpoolError: pass
    else: raise AssertionError('Different credential reuses prior queue')
    with sqlite3.connect(path) as db:
        db.execute('UPDATE entries SET body=?',(b'not-json',))
    try:
        with DurableBatchSpool(path,client(url)) as spool:
            spool.flush()
    except SpoolError:
        pass
    else:
        raise AssertionError('Corrupted entry sent')


def main():
    server = ThreadingHTTPServer(('127.0.0.1',0),Receiver)
    server.daemon_threads=True
    server.block_on_close=False
    thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
    url=f'http://127.0.0.1:{server.server_port}'
    try:
        with tempfile.TemporaryDirectory() as tmp:
            home=Path(tmp)
            for fn in (test_batch,test_lost_and_restart,test_outage_and_expiry,
                       test_logging,test_multiprocess,test_cli_and_background,test_security):
                fn(url,home)
                print('PASS',fn.__name__,flush=True)
            assert not errors, errors
            assert sum(len(v) for v in writes.values()) >= 128
            print('PASS durable Python SDK real HTTP, HMAC, concurrent processes, logging',flush=True)
    finally:
        stopper=threading.Thread(target=server.shutdown,daemon=True)
        stopper.start();stopper.join(timeout=5)
        server.server_close();thread.join(timeout=5)

if __name__=='__main__': main()
