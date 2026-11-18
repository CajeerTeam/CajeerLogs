"""Real HTTP retry, lost-202, terminal-state and restart tests for all four SDKs."""
import hashlib
import hmac
import json
import shutil
import socket
import subprocess
import sys
import tempfile
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from zoneinfo import ZoneInfo

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'clients/python'))
from cajeer_logs_client import CajeerLogsClient, DeliveryError, JobFailed, JobPending
from cajeer_logs_collector import Collector
from cajeer_delivery_spool import DurableAsyncSpool, SpoolFull, SpoolError
TOKEN = 'cl_' + 'a' * 64
calls = {}
jobs = {}
lookup_count = {}
failures = []
collector_unblock = False


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *_): pass

    def respond(self, code, body, **headers):
        raw = json.dumps(body, separators=(',', ':')).encode()
        self.send_response(code)
        self.send_header('Content-Length', str(len(raw)))
        self.send_header('Content-Type', 'application/json')
        for name, value in headers.items(): self.send_header(name.replace('_', '-'), value)
        self.end_headers()
        self.wfile.write(raw)

    def do_POST(self):
        try:
            assert self.path == '/api/v1/ingest/async' and self.headers.get('Authorization') == 'Bearer ' + TOKEN
            key = self.headers['Idempotency-Key']
            body = self.rfile.read(int(self.headers['Content-Length']))
            sig = self.headers.get('X-Log-Signature')
            if sig:
                canonical = '\n'.join(['cajeerlogs-ingest-v1','POST',self.path,self.headers['X-Log-Timestamp'],self.headers['X-Log-Nonce'],key,hashlib.sha256(body).hexdigest()])
                assert hmac.compare_digest(sig, hmac.new(TOKEN.encode(),canonical.encode(),hashlib.sha256).hexdigest())
            previous = calls.setdefault(key, [])
            if previous: assert all(x[0] == body for x in previous) and (not sig or all(x[1] != self.headers.get('X-Log-Nonce') for x in previous))
            previous.append((body, self.headers.get('X-Log-Nonce')))
            if key.endswith('-async-redirect'): return self.respond(307, {}, Location='/wrong')
            if key.endswith('-async-401'): return self.respond(401, {})
            if key.endswith('-async-retry') and len(previous) == 1: return self.respond(503, {}, Retry_After='0')
            job = jobs.get(key)
            if job is None:
                job_id = str(10000 + len(jobs))
                count = len(json.loads(body).get('events', [json.loads(body)]))
                job = jobs[key] = (job_id, count)
                lookup_count[job_id] = 0
            job_id, count = job
            if key.endswith('-async-lost') and len(previous) == 1:
                self.connection.shutdown(socket.SHUT_RDWR)
                self.connection.close()
                return
            if key.endswith('-async-badreceipt'): return self.respond(202, {'accepted': True, 'received':count, 'job_id':'0', 'status':'pending', 'replayed':False})
            return self.respond(200 if len(previous)>1 else 202,
                {'accepted':True,'received':count,'job_id':job_id,'status':'pending','replayed':len(previous)>1},
                Location='/api/v1/ingest/jobs/'+job_id)
        except Exception as exc:
            failures.append('POST '+repr(exc)); self.send_error(500)

    def do_GET(self):
        try:
            assert self.headers.get('Authorization') == 'Bearer ' + TOKEN
            assert self.path.startswith('/api/v1/ingest/jobs/')
            id = self.path.rsplit('/', 1)[-1]
            found = next(((k,n) for k,n in jobs.items() if n[0]==id), None)
            if not found: return self.respond(404, {'error':'job_not_found'})
            key, (_, count) = found
            lookup_count[id] += 1
            if key.endswith('-async-pending') or (len(key)==32 and all(c in '0123456789abcdef' for c in key) and not collector_unblock): state='pending'
            elif key.endswith('-async-dead'): state='dead'
            elif key.endswith('-async-cancelled'): state='cancelled'
            elif lookup_count[id]==1: state='running'
            else: state='completed'
            ids = [f'00000000-0000-4000-8000-{i+1:012x}' for i in range(count)]
            result = {'stored':count,'event_ids':ids} if state=='completed' else None
            if key.endswith('-async-invalid') and state=='completed': result={'stored':count,'event_ids':ids[:1]}
            return self.respond(200,{'id':id,'status':state,'attempts':1,'max_attempts':8,'result':result,
                'created_at':'2026-11-17T00:00:00Z','available_at':'2026-11-17T00:00:00Z','completed_at':None,'last_error':None})
        except Exception as exc:
            failures.append('GET '+repr(exc)); self.send_error(500)


def run(*args):
    subprocess.run([str(a) for a in args], cwd=ROOT, check=True, timeout=120)


def test_python(url):
    c=CajeerLogsClient(url,TOKEN,signed=True,attempts=2,timeout=2)
    e={'timestamp':'2026-11-17T12:00:00Z','source':'app','level':'info','message':'async'}
    for key in ('py-async-lost','py-async-retry','py-async-replay'):
        r=c.ingest_async([e,e],idempotency_key=key)
        assert r['received']==2 and c.wait_for_job(r,timeout=2,poll_interval=0.05)['result']['stored']==2
        if key.endswith('replay'):
            replay=c.ingest_async([e,e],idempotency_key=key)
            assert replay['replayed'] and replay['job_id']==r['job_id']
    for kind in ('dead','cancelled'):
        r=c.ingest_async(e,idempotency_key=f'py-async-{kind}')
        try: c.wait_for_job(r,timeout=.2,poll_interval=.05); raise AssertionError('False completion')
        except JobFailed as exc: assert exc.status==kind
    r=c.ingest_async(e,idempotency_key='py-async-pending')
    try: c.wait_for_job(r,timeout=.15,poll_interval=.05); raise AssertionError('False completion')
    except JobPending as exc: assert exc.job_id==r['job_id']
    r=c.ingest_async([e,e],idempotency_key='py-async-invalid')
    try: c.wait_for_job(r,timeout=1,poll_interval=.05); raise AssertionError('Corrupt completion accepted')
    except ValueError: pass
    for kind in ('redirect','401'):
        try: c.ingest_async(e,idempotency_key=f'py-async-{kind}'); raise AssertionError('Permanent failure accepted')
        except __import__('urllib.error',fromlist=['HTTPError']).HTTPError as err: assert err.code==(307 if kind=='redirect' else 401)
    try: c.ingest_async(e,idempotency_key='py-async-badreceipt'); raise AssertionError('Invalid receipt accepted')
    except DeliveryError: pass
    print('PASS Python async lost response/retry/replay/poll/terminal/malformed/redirect')


def test_collector(url):
    global collector_unblock
    with tempfile.TemporaryDirectory() as d:
        f=Path(d)/'app.log'; db=Path(d)/'state.sqlite'; f.write_bytes(b'one\ntwo\n')
        c=CajeerLogsClient(url,TOKEN,attempts=2,timeout=1)
        def start(): return Collector(f,db,c,'app','text',ZoneInfo('UTC'),batch=2,async_mode=True,completion_timeout=.15,poll_interval=.05)
        col=start()
        try:
            try: col.step(); raise AssertionError('Collector advanced before job completed')
            except JobPending: pass
            row=col.row(); assert row[2]==0 and row[4] is not None and row[9] is not None
            key=row[5]; jobid=row[9]
            assert len(calls[key])==1
        finally: col.close()
        collector_unblock=True
        col=start()
        try:
            assert col.step()==2 and col.row()[2]==8 and col.row()[9] is None
            assert len(calls[key])==1 and lookup_count[jobid]>=2
        finally: col.close()
        print('PASS collector restart polls persisted job without re-enqueue or premature checkpoint')


def test_spool(url):
    with tempfile.TemporaryDirectory() as d:
        path=Path(d)/'outbox.sqlite'
        # A spool directory with group/world traversal permission is not private.
        public=Path(d)/'insecure'; public.mkdir(mode=0o755)
        try: DurableAsyncSpool(public/'outbox.sqlite',CajeerLogsClient(url,TOKEN))
        except SpoolError: pass
        else: raise AssertionError('World-readable spool directory accepted')
        c=CajeerLogsClient(url,TOKEN,signed=True,attempts=1,timeout=2)
        e={'timestamp':'2026-11-17T12:00:00Z','source':'app','level':'info','message':'durable spool'}
        with DurableAsyncSpool(path,c,max_jobs=1,max_bytes=1048576) as spool:
            key=spool.enqueue(e,idempotency_key='spool-async-lost')
            assert spool.enqueue(e,idempotency_key=key)==key
            try: spool.enqueue(e,idempotency_key='another'); raise AssertionError('Spool overflow')
            except SpoolFull: pass
            try: spool.flush_one(timeout=2,poll_interval=.05); raise AssertionError('Expected lost admission response')
            except DeliveryError: pass
            assert spool.status()['pending']==1
            try: DurableAsyncSpool(path,c); raise AssertionError('Multiple spool writers')
            except BlockingIOError: pass
        with DurableAsyncSpool(path,c,max_jobs=1,max_bytes=1048576) as spool:
            assert spool.flush_one(timeout=2,poll_interval=.05)
            assert spool.status()['pending']==0 and spool.status()['completed']==1 and spool.status()['pending_bytes']==0
            assert len(calls[key])==2 and calls[key][0][0]==calls[key][1][0]
            assert spool.enqueue(e,idempotency_key=key)==key and len(calls[key])==2
            assert spool.prune_completed(keep_last=0)==1
            dead=spool.enqueue(e,idempotency_key='spool-async-dead')
            try: spool.flush_one(timeout=.3,poll_interval=.05); raise AssertionError('Dead job accepted')
            except JobFailed as ex: assert ex.status=='dead'
            assert spool.status()['dead']==1 and spool.status()['pending']==0
        print('PASS durable spool lost response, restart, capacity, locking, terminal retention')

def main():
    server=ThreadingHTTPServer(('127.0.0.1',0),Handler)
    thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
    url=f'http://127.0.0.1:{server.server_port}'
    try:
        test_python(url)
        with tempfile.TemporaryDirectory() as d:
            d=Path(d)
            run('php',ROOT/'tests/sdk/async.php',url,TOKEN)
            tsc=shutil.which('tsc') or str(ROOT/'packages/ts-sdk/node_modules/.bin/tsc')
            run(tsc,'-p',ROOT/'packages/ts-sdk/tsconfig.json','--outDir',d)
            (d/'package.json').write_text('{"type":"module"}')
            shutil.copy(ROOT/'tests/sdk/async.mjs',d/'async.mjs')
            run('node',d/'async.mjs',url,TOKEN)
            kotlin=shutil.which('kotlinc')
            if kotlin:
                run(kotlin,ROOT/'clients/java-kotlin/src/main/kotlin/ru/cajeer/logs/CajeerLogsClient.kt',ROOT/'tests/sdk/AsyncWireTest.kt','-include-runtime','-d',d/'sdk.jar')
                run('java','-jar',d/'sdk.jar',url,TOKEN)
            else:
                run('gradle','-p',ROOT/'clients/java-kotlin','asyncWireTest',f'-PwireUrl={url}',f'-PwireToken={TOKEN}')
        test_collector(url)
        test_spool(url)
        assert not failures, failures
        for prefix in ('py','php','ts','kotlin'):
            key=prefix+'-async-lost'
            assert len(calls[key])==2 and calls[key][0][0]==calls[key][1][0]
            assert len(calls[prefix+'-async-redirect'])==1
        print('PASS all SDKs use exact bytes/key and never follow redirects')
    finally:
        server.shutdown();server.server_close();thread.join(timeout=5)

if __name__=='__main__': main()
