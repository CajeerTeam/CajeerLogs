"""Real HTTP wire tests for all four SDKs and collector, with controlled server replies."""
import hashlib
import hmac
import json
import os
import shutil
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
from datetime import timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from zoneinfo import ZoneInfo

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT/'clients/python'))
from cajeer_logs_client import CajeerLogsClient, DeliveryError
from cajeer_logs_collector import Collector, parse_line
TOKEN='cl_'+'a'*64
calls={}; failures=[]; collector_fail=False

class Handler(BaseHTTPRequestHandler):
    def log_message(self,*args): pass
    def do_POST(self):
        global collector_fail
        raw=self.rfile.read(int(self.headers['Content-Length'])); key=self.headers.get('Idempotency-Key'); nonce=self.headers.get('X-Log-Nonce')
        try:
            assert self.path=='/api/v1/ingest' and self.headers['Authorization']=='Bearer '+TOKEN and key
            if nonce:
                canonical='\n'.join(['cajeerlogs-ingest-v1','POST',self.path,self.headers['X-Log-Timestamp'],nonce,key,hashlib.sha256(raw).hexdigest()])
                assert hmac.compare_digest(self.headers['X-Log-Signature'],hmac.new(TOKEN.encode(),canonical.encode(),hashlib.sha256).hexdigest())
            previous=calls.setdefault(key,[])
            if previous:
                assert previous[0][0]==raw
                if nonce: assert nonce not in [p[1] for p in previous]
            previous.append((raw,nonce))
            payload=json.loads(raw); n=len(payload['events']) if 'events' in payload else 1
            if key.endswith('-denied'): status=401
            elif key.endswith('-redirect'): status=307
            elif key.endswith('-retry') and len(previous)==1: status=503
            elif key.endswith('-replay'): status=200
            elif key.endswith('-malformed'): status=201
            elif collector_fail: status=503
            else: status=201
            body=json.dumps({'accepted':True,'received':n,'stored':0 if status==200 else n,'rejected':0,'event_ids':['id']*n,'replayed':status==200}).encode() if status in (200,201) and not key.endswith('-malformed') else b'{}'
            self.send_response(status)
            if status==307: self.send_header('Location','/capture')
            if status==503: self.send_header('Retry-After','0')
            self.send_header('Content-Length',str(len(body))); self.send_header('Content-Type','application/json'); self.end_headers(); self.wfile.write(body)
        except Exception as e:
            failures.append(repr(e)); self.send_error(500)

def run(*args): subprocess.run(list(map(str,args)),check=True,cwd=ROOT,timeout=120)
def check(value,label):
    assert value,label
    print('PASS',label,flush=True)

server=ThreadingHTTPServer(('127.0.0.1',0),Handler)
t=threading.Thread(target=server.serve_forever,daemon=True);t.start()
url=f'http://127.0.0.1:{server.server_port}'
try:
    client=CajeerLogsClient(url,TOKEN,signed=True,attempts=2,timeout=2)
    event={'timestamp':'2026-01-01T00:00:00Z','source':'app','level':'info','message':'Привет'}
    check(client.ingest([event,event],idempotency_key='python-retry')['stored']==2,'Python retry and confirmed batch')
    check(client.ingest(event,idempotency_key='python-replay')['replayed'],'Python accepts confirmed replay')
    try: client.ingest(event,idempotency_key='python-malformed'); raise AssertionError('expected invalid receipt error')
    except DeliveryError: check(True,'Python rejects false 201 receipt')
    for key,status in [('python-denied',401),('python-redirect',307)]:
        try: client.ingest(event,idempotency_key=key); raise AssertionError('expected error')
        except urllib.error.HTTPError as e: check(e.code==status,'Python rejects '+str(status))
    for bad in ({'timestamp':'2026-02-30T00:00:00Z'},{'message':123},{'context':None},{'tags':[None]},{'source':'APP'},{'message':'bad\x1b'},{'context':{'n':float('nan')}}):
        try: client.ingest(event|bad,idempotency_key='python-invalid'); raise AssertionError('Invalid event accepted')
        except ValueError: pass
    run('php',ROOT/'tests/sdk/php.php',url,TOKEN)
    with tempfile.TemporaryDirectory() as tmp:
        tmp=Path(tmp)
        tsc=shutil.which('tsc') or str(ROOT/'packages/ts-sdk/node_modules/.bin/tsc')
        run(tsc,'-p',ROOT/'packages/ts-sdk/tsconfig.json','--outDir',tmp)
        (tmp/'package.json').write_text('{"type":"module"}')
        shutil.copy(ROOT/'tests/sdk/node.mjs',tmp/'node.mjs')
        run('node',tmp/'node.mjs',url,TOKEN)
        kotlin=shutil.which('kotlinc')
        if kotlin:
            run(kotlin,ROOT/'clients/java-kotlin/src/main/kotlin/ru/cajeer/logs/CajeerLogsClient.kt',ROOT/'tests/sdk/WireTest.kt','-include-runtime','-d',tmp/'sdk.jar')
            run('java','-jar',tmp/'sdk.jar',url,TOKEN)
        else:
            run('gradle','-p',ROOT/'clients/java-kotlin','wireTest',f'-PwireUrl={url}',f'-PwireToken={TOKEN}')
        check(not any(key.endswith('-invalid') for key in calls),'all four SDKs reject invalid events before HTTP')
        for prefix in ('python','php','ts','kotlin'):
            check(len(calls[prefix+'-retry'])==2 and len(calls[prefix+'-denied'])==1 and len(calls[prefix+'-redirect'])==1 and len(calls[prefix+'-replay'])==1 and len(calls[prefix+'-malformed'])==2,prefix+' exact retry / permanent error / no redirect / replay / malformed receipt')
        log=tmp/'app.log'; state=tmp/'state.sqlite'; log.write_bytes(b'first\nsecond\npartial')
        c=CajeerLogsClient(url,TOKEN,signed=True,attempts=1,timeout=2)
        def open_collector(): return Collector(log,state,c,'app','text',ZoneInfo('UTC'),batch=2)
        col=open_collector(); collector_fail=True
        try:
            try: col.step(); raise AssertionError('expected failure')
            except DeliveryError: pass
            row=col.row(); check(row[2]==0 and row[4] is not None,'collector durable pending, checkpoint unchanged')
            pending_key=row[5]; pending_body=row[4]
        finally: col.close()
        collector_fail=False; col=open_collector()
        try:
            check(col.step()==2 and col.row()[2]==13 and calls[pending_key][-1][0]==pending_body,'collector restart retries identical bytes')
            check(col.step()==0,'partial line not committed')
            with log.open('ab') as f: f.write(b' end\n')
            check(col.step()==1,'completed line delivered')
            rotated=tmp/'app.log.1'; log.rename(rotated); log.write_bytes(b'new file\n')
            os.utime(rotated,(time.time()-5,time.time()-5))
            check(col.step()==-1 and col.step()==1,'rename rotation drains and switches inode')
            log.write_bytes(b'x\n');
            try: col.step(); raise AssertionError('expected truncate failure')
            except ValueError: check(True,'copytruncate detected; no silent advance')
        finally: col.close()
        check(parse_line(b'127.0.0.1 - - [01/Jan/2026:01:00:00 +0100] "GET /api?token=hidden HTTP/1.1" 502 12 "-" "agent"\n','nginx-access','app',ZoneInfo('UTC'))['context']['path']=='/api','Nginx parser strips query')
        check(parse_line(b'2026/01/01 01:00:00 [warn] example\n','nginx-error','app',ZoneInfo('Europe/Amsterdam'))['level']=='warning','Nginx error parser')
        check(parse_line(b'{"source":"foreign","message":"log","level":"info"}\n','jsonl','app',ZoneInfo('UTC'))['source']=='app','JSONL source cannot override routing')
    check(not failures,'all wire assertions passed')
finally:
    server.shutdown();server.server_close();t.join(timeout=5)
