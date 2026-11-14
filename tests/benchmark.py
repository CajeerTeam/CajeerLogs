"""Exercise the benchmark client against a controlled HTTP fixture, not PostgreSQL."""
import hashlib
import hmac
import importlib.util
import json
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import threading
import urllib.parse
import uuid
import unittest

spec=importlib.util.spec_from_file_location('benchmark',Path(__file__).resolve().parents[1]/'scripts/benchmark.py')
bench=importlib.util.module_from_spec(spec);spec.loader.exec_module(bench)
TOKEN='fixture-only-token'; PROJECT='11111111-1111-4111-8111-111111111111'

class Fixture(BaseHTTPRequestHandler):
    lock=threading.Lock(); receipts={}; jobs={}; events=[]; nonces=set(); corrupt=False
    def log_message(self,*args): pass
    def reply(self,status,value):
        body=json.dumps(value).encode();self.send_response(status);self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
    def do_POST(self):
        raw=self.rfile.read(int(self.headers['Content-Length']));key=self.headers['Idempotency-Key'];nonce=self.headers['X-Log-Nonce'];ts=self.headers['X-Log-Timestamp']
        mac=hmac.new(TOKEN.encode(),'\n'.join(['cajeerlogs-ingest-v1','POST',self.path,ts,nonce,key,hashlib.sha256(raw).hexdigest()]).encode(),hashlib.sha256).hexdigest()
        with self.lock:
            if self.headers['Authorization']!='Bearer '+TOKEN or mac!=self.headers['X-Log-Signature'] or nonce in self.nonces: return self.reply(401,{})
            self.nonces.add(nonce)
            if key in self.receipts:
                old,stored=self.receipts[key]
                if old!=raw:return self.reply(409,{})
                return self.reply(200,stored|{'replayed':True})
            data=json.loads(raw); events=data.get('events',[data]);ids=[]
            for event in events:
                eid=str(uuid.uuid4());ids.append(eid);self.events.append({'id':eid,'request_id':event['request_id']})
            response={'received':len(events),'replayed':False}
            if self.path.endswith('/async'):
                jid=str(len(self.jobs)+1);self.jobs[jid]={'status':'completed','result':{'event_ids':ids,'stored':len(ids)}};response['job_id']=jid;status=202
            else:response['event_ids']=ids;status=201
            self.receipts[key]=(raw,response);self.reply(status,response)
    def do_GET(self):
        with self.lock:
            if self.path.startswith('/api/v1/ingest/jobs/'):return self.reply(200,self.jobs[self.path.split('/')[-1]])
            q=urllib.parse.parse_qs(urllib.parse.urlsplit(self.path).query);items=sorted([x for x in self.events if x['request_id']==q['request_id'][0]],key=lambda x:x['id'],reverse=True)
            if 'cursor' in q:items=[x for x in items if x['id']<q['cursor'][0]]
            if self.corrupt: items=[]
            self.reply(200,{'items':items[:100],'next_cursor':items[99]['id'] if len(items)>100 else None})

class BenchmarkTest(unittest.TestCase):
    def test_percentiles(self):
        self.assertEqual(bench.percentiles([.001,.002,.003])['p95_ms'],3)
        self.assertEqual(bench.percentiles([]),{'count':0})
    def test_reject_remote_plain_http(self):
        with self.assertRaises(ValueError):bench.run('http://example.test',TOKEN,PROJECT,'app')
    def test_http_concurrent_replay_and_pagination(self):
        server=ThreadingHTTPServer(('127.0.0.1',0),Fixture);thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
        try:
            url=f'http://127.0.0.1:{server.server_port}';r=bench.run(url,TOKEN,PROJECT,'app',requests=9,batch=20,concurrency=4)
            self.assertEqual(r['events_verified'],123);self.assertEqual(r['idempotent_replays_verified'],9)
            self.assertEqual(r['latency']['async']['count'],3);self.assertEqual(r['latency']['single']['count'],3);self.assertEqual(r['latency']['batch']['count'],3)
            self.assertGreaterEqual(r['latency']['search']['count'],3)
            Fixture.corrupt=True
            with self.assertRaisesRegex(RuntimeError,'Stored event set differs'):bench.run(url,TOKEN,PROJECT,'app',requests=3,batch=2,concurrency=2)
        finally:Fixture.corrupt=False;server.shutdown();server.server_close();thread.join()

if __name__=='__main__':unittest.main()
