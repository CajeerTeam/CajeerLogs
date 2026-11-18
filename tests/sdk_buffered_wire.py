#!/usr/bin/env python3
"""Real HTTP wire + restart + spool recovery tests for PHP durable batch SDK."""
import hashlib
import hmac
import json
import socket
import subprocess
import tempfile
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
TOKEN='cl_'+'a'*64
received={}
calls={}
failures=[]
healthy=False

class Handler(BaseHTTPRequestHandler):
    protocol_version='HTTP/1.1'
    def log_message(self,*args): pass
    def do_POST(self):
        try:
            assert self.path=='/api/v1/ingest'
            assert self.headers['Authorization']=='Bearer '+TOKEN
            key=self.headers['Idempotency-Key']
            body=self.rfile.read(int(self.headers['Content-Length']))
            nonce=self.headers['X-Log-Nonce']
            canonical='\n'.join(['cajeerlogs-ingest-v1','POST',self.path,self.headers['X-Log-Timestamp'],nonce,key,hashlib.sha256(body).hexdigest()])
            assert hmac.compare_digest(self.headers['X-Log-Signature'],hmac.new(TOKEN.encode(),canonical.encode(),hashlib.sha256).hexdigest())
            assert len(body)<=1048576
            event=json.loads(body)
            events=event['events'] if 'events' in event else [event]
            assert 1<=len(events)<=100
            assert all(e['source'] in ('sdk','app') for e in events)
            previous=calls.setdefault(key,[])
            if previous:
                assert all(x[0]==body and x[1]!=nonce for x in previous)
            previous.append((body,nonce))
            is_outage=any(e['message'].startswith('outage') for e in events)
            is_denied=any(e['message'].startswith('denied') for e in events)
            is_lost=any(e['message'].startswith('lost') for e in events)
            if is_denied: status=401
            elif is_outage and not healthy: status=503
            elif key in received: status=200
            else: status=201
            if status==201:
                received[key]=events
                if is_lost:
                    self.connection.shutdown(socket.SHUT_RDWR);self.connection.close();return
            data=(json.dumps(dict(accepted=True,received=len(events),stored=0 if status==200 else len(events),rejected=0,
                                  event_ids=[f'00000000-0000-4000-8000-{i+1:012x}' for i in range(len(events))],
                                  replayed=status==200)).encode() if status in (200,201) else b'{}')
            self.send_response(status)
            if status==503: self.send_header('Retry-After','0')
            self.send_header('Content-Type','application/json')
            self.send_header('Content-Length',str(len(data)))
            self.end_headers();self.wfile.write(data)
        except Exception as e:
            failures.append(repr(e));self.send_error(500)

def run(*args):
    p=subprocess.run(['php',str(ROOT/'tests/sdk/php_buffered.php'),*map(str,args)],cwd=ROOT,text=True,capture_output=True,timeout=30)
    if p.returncode: raise AssertionError('PHP buffered test failed: '+p.stdout+p.stderr)
    print(p.stdout.strip(),flush=True)

server=ThreadingHTTPServer(('127.0.0.1',0),Handler)
thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
url=f'http://127.0.0.1:{server.server_port}'
try:
    with tempfile.TemporaryDirectory() as temp:
        base=Path(temp)
        run('first',url,TOKEN,base/'resume')
        assert not calls
        run('resume',url,TOKEN,base/'resume')
        assert len(received)==1 and len(next(iter(received.values())))==3
        run('lost',url,TOKEN,base/'lost')
        lost=[v for v in calls.values() if len(v)>=2 and b'lost-one' in v[0][0]]
        assert len(lost)==1 and len(lost[0])==2,'replayed request must reuse bytes/key with fresh nonce'
        run('lost-restart',url,TOKEN,base/'lost-restart')
        lr=[(k,v) for k,v in calls.items() if b'lost-restart-one' in v[0][0]]
        assert len(lr)==1 and len(lr[0][1])==1 and lr[0][0] in received
        run('lost-recover',url,TOKEN,base/'lost-restart')
        assert len(calls[lr[0][0]])==2 and len(received[lr[0][0]])==2
        a=subprocess.Popen(['php',str(ROOT/'tests/sdk/php_buffered.php'),'concurrent',url,TOKEN,str(base/'concurrent'),'a'],cwd=ROOT,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
        b=subprocess.Popen(['php',str(ROOT/'tests/sdk/php_buffered.php'),'concurrent',url,TOKEN,str(base/'concurrent'),'b'],cwd=ROOT,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
        for proc in (a,b):
            out,err=proc.communicate(timeout=30)
            assert proc.returncode==0,err
        run('concurrent-flush',url,TOKEN,base/'concurrent')
        entries=[e['message'] for group in received.values() for e in group if e['message'].startswith('parallel-')]
        assert len(entries)==len(set(entries))==60,'concurrent writers did not persist all distinct events'
        run('outage',url,TOKEN,base/'outage')
        outage=[(k,v) for k,v in calls.items() if b'outage-one' in v[0][0]]
        assert len(outage)==1 and len(outage[0][1])==2 and outage[0][0] not in received
        healthy=True
        run('outage-recover',url,TOKEN,base/'outage')
        assert outage[0][0] in received and len(calls[outage[0][0]])==3
        run('permanent',url,TOKEN,base/'denied')
        run('invalid',url,TOKEN,base/'invalid')
        healthy=False
        run('capacity',url,TOKEN,base/'capacity')
        healthy=True
        run('byte-boundary',url,TOKEN,base/'bytes')
        segments=[len(v) for v in received.values() if any(e['message'].startswith('size-') for e in v)]
        assert sorted(segments)==[1,2],f'incorrect byte boundary grouping: {segments}'
        if (ROOT/'vendor/autoload.php').exists():
            p=subprocess.run(['php',str(ROOT/'tests/sdk/php_buffered_monolog.php'),url,TOKEN,str(base/'monolog')],cwd=ROOT,text=True,capture_output=True,timeout=30)
            if p.returncode: raise AssertionError('Real Monolog test failed: '+p.stdout+p.stderr)
            print(p.stdout.strip(),flush=True)
            logs=[e for group in received.values() for e in group if e['source']=='app']
            assert len(logs)==4
            assert logs[0]['message']=='User 42 logged in' and logs[1]['message']=='Exception 500'
            assert logs[2]['context']['channel']=='app-channel'
        assert not failures,failures
        assert all(len(entries)<=2 or b'outage-one' in entries[0][0] for entries in calls.values())
        print('PASS durable PHP batches, HTTP HMAC, byte limit, retries, restart and recovery')
finally:
    server.shutdown();server.server_close()
