#!/usr/bin/env python3
"""Compile production Kotlin SDK and exercise real signed HTTP batches through the JVM.

No Gradle cache or fake Kotlin classes are required; tests run with JDK + kotlinc.
"""
from __future__ import annotations

import hashlib
import hmac
import json
import shutil
import socket
import subprocess
import tempfile
import threading
from collections import defaultdict
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SRC = ROOT / 'clients/java-kotlin/src/main/kotlin/ru/cajeer/logs'
TOKEN = 'cl_' + 'c' * 64
requests = defaultdict(list)
committed = {}
failures = []

class Receiver(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_POST(self):
        try:
            raw = self.rfile.read(int(self.headers['Content-Length']))
            key = self.headers['Idempotency-Key']
            nonce = self.headers['X-Log-Nonce']
            assert self.path == '/api/v1/ingest'
            assert self.headers['Authorization'] == 'Bearer ' + TOKEN
            canonical = '\n'.join(('cajeerlogs-ingest-v1', 'POST', self.path,
                self.headers['X-Log-Timestamp'], nonce, key, hashlib.sha256(raw).hexdigest()))
            assert hmac.compare_digest(self.headers['X-Log-Signature'],
                hmac.new(TOKEN.encode(), canonical.encode(), hashlib.sha256).hexdigest())
            obj = json.loads(raw)
            assert isinstance(obj, dict) and isinstance(obj.get('events'), list)
            events = obj['events']
            assert 1 <= len(events) <= 100 and len(raw) <= 1048576
            assert all(e['source'] == 'app' and e['level'] in ('info','error') for e in events)
            reqs = requests[key]
            if reqs:
                assert all(previous[0] == raw and previous[1] != nonce for previous in reqs)
            reqs.append((raw, nonce))
            names = [e['message'] for e in events]
            if 'hold' in names:
                threading.Event().wait(0.5)
            first = len(reqs) == 1
            if 'permanent' in names:
                status = 401
            elif 'retry' in names and first:
                status = 503
            elif 'manual-replay' in names and first:
                committed[key] = len(events)
                status = 503
            elif 'lost' in names and first:
                committed[key] = len(events)
                self.connection.shutdown(socket.SHUT_RDWR)
                self.connection.close()
                return
            else:
                status = 200 if key in committed else 201
                committed.setdefault(key,len(events))
            if status in (200,201):
                response = json.dumps({'accepted':True,'received':len(events),
                    'stored':0 if status==200 else len(events),'rejected':0,
                    'replayed':status==200,
                    'event_ids':['00000000-0000-4000-8000-%012x' % (i+1) for i in range(len(events))]}).encode()
            else:
                response = b'{}'
            self.send_response(status)
            self.send_header('Content-Type','application/json')
            self.send_header('Content-Length',str(len(response)))
            if status==503: self.send_header('Retry-After','0')
            self.end_headers()
            self.wfile.write(response)
        except Exception as exc:
            failures.append(repr(exc))
            try: self.send_error(500)
            except OSError: pass


def run(*args, cwd=None, timeout=90):
    subprocess.run([str(v) for v in args],cwd=cwd or ROOT,check=True,timeout=timeout)


def main():
    assert shutil.which('java') and shutil.which('javac') and (shutil.which('kotlinc') or shutil.which('gradle')), 'Java and Kotlin compiler or Gradle required'
    server=ThreadingHTTPServer(('127.0.0.1',0),Receiver)
    thread=threading.Thread(target=server.serve_forever,daemon=True)
    thread.start()
    url=f'http://127.0.0.1:{server.server_port}'
    try:
        with tempfile.TemporaryDirectory() as temp:
            path=Path(temp)
            jar=path/'sdk.jar'
            if shutil.which('kotlinc'):
                run('kotlinc', *sorted(SRC.glob('*.kt')), ROOT/'tests/sdk/KotlinQueueTest.kt', '-include-runtime','-d',jar)
                run('java','-jar',jar,url,TOKEN,timeout=120)
                run('javac','-cp',jar,'-d',path,ROOT/'tests/sdk/KotlinQueueJavaTest.java')
                run('java','-cp',f'{path}:{jar}','KotlinQueueJavaTest',url,TOKEN)
            else:
                build = ROOT / 'clients/java-kotlin'
                for task in ('queueWireTest', 'javaWireTest'):
                    run('gradle', '-p', build, task, f'-PwireUrl={url}', f'-PwireToken={TOKEN}', '--no-daemon', timeout=180)
        assert not failures,failures
        assert len(requests) >= 7
        allmessages=[]
        for key,req in requests.items():
            if key in committed:
                content=json.loads(req[0][0])
                allmessages.extend(e['message'] for e in content['events'])
        assert len(set(m for m in allmessages if m.startswith('producer-'))) == 180
        assert 'jul logger' in allmessages and 'exception' in allmessages
        assert any(len(req)>1 and b'"lost"' in req[0][0] for req in requests.values())
        print('PASS production JVM HTTP replay, HMAC, batching, concurrency and Java/JUL interop')
    finally:
        server.shutdown();server.server_close();thread.join(timeout=5)

if __name__=='__main__': main()
