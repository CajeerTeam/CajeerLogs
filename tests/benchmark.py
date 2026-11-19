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
TOKEN='fixture-only-token'; PROJECT='11111111-1111-4111-8111-111111111111'; KEY='22222222-2222-4222-8222-222222222222'; SECRET='sk_'+'a'*64

class Fixture(BaseHTTPRequestHandler):
    lock=threading.Lock(); receipts={}; jobs={}; events=[]; nonces=set(); corrupt=False; corrupt_payload=False; corrupt_export=False
    def log_message(self,*args): pass
    def reply(self,status,value,headers=None):
        body=json.dumps(value).encode();self.send_response(status);self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(body)))
        for k,v in (headers or {}).items():self.send_header(k,v)
        self.end_headers();self.wfile.write(body)
    def do_POST(self):
        raw=self.rfile.read(int(self.headers['Content-Length']));key=self.headers['Idempotency-Key'];nonce=self.headers['X-Log-Nonce'];ts=self.headers['X-Log-Timestamp']
        key_id=self.headers.get('X-Log-Key-Id'); prefix=['cajeerlogs-ingest-v1'] if not key_id else ['cajeerlogs-ingest-v2',KEY]
        mac=hmac.new((SECRET if key_id else TOKEN).encode(),'\n'.join([*prefix,'POST',self.path,ts,nonce,key,hashlib.sha256(raw).hexdigest()]).encode(),hashlib.sha256).hexdigest()
        with self.lock:
            if key_id not in (None,KEY) or self.headers['Authorization']!='Bearer '+TOKEN or mac!=self.headers['X-Log-Signature'] or nonce in self.nonces: return self.reply(401,{})
            self.nonces.add(nonce)
            if key in self.receipts:
                old,stored=self.receipts[key]
                if old!=raw:return self.reply(409,{})
                return self.reply(200,stored|{'replayed':True})
            data=json.loads(raw); events=data.get('events',[data]);ids=[]
            for event in events:
                eid=str(uuid.uuid4());ids.append(eid);self.events.append({'id':eid,'request_id':event['request_id'],'project_id':PROJECT,'source':event['source'],'level':event['level'],'message':event['message']})
            response={'received':len(events),'replayed':False}
            if self.path.endswith('/async'):
                jid=str(len(self.jobs)+1);self.jobs[jid]={'status':'completed','result':{'event_ids':ids,'stored':len(ids)}};response['job_id']=jid;status=202
            else:response['event_ids']=ids;status=201
            self.receipts[key]=(raw,response);self.reply(status,response)
    def do_GET(self):
        with self.lock:
            if self.path.startswith('/api/v1/ingest/jobs/'):return self.reply(200,self.jobs[self.path.split('/')[-1]])
            q=urllib.parse.parse_qs(urllib.parse.urlsplit(self.path).query);items=sorted([x for x in self.events if x['request_id']==q['request_id'][0]],key=lambda x:x['id'],reverse=True)
            limit=int(q.get('limit',['100'])[0]); start=int(q.get('cursor',['0'])[0]); total=len(items)
            if self.corrupt: items=[]; total=0
            if self.corrupt_payload and items: items[0] = {**items[0], 'message':'corrupted after commit'}
            page=items[start:start+limit]; digest=hashlib.sha256(json.dumps(page,sort_keys=True).encode()).hexdigest()
            result={'items':page,'next_cursor':str(start+limit) if start+limit<total else None,'prev_cursor':str(max(0,start-limit)) if start else None,
                    'page_cursor':str(start),'page_digest':digest,'snapshot_at':'2026-01-01T00:00:00Z','consistency':q.get('consistency',['live'])[0]}
            if '/export?' in self.path:
                if self.headers.get('If-Match')!='"'+digest+'"':return self.reply(412,{})
                if self.corrupt_export:result['items']=[]
                return self.reply(200,result,{'X-Page-Digest':digest})
            self.reply(200,result)

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

    def test_v2_snapshot_reverse_and_export(self):
        server=ThreadingHTTPServer(('127.0.0.1',0),Fixture);thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
        try:
            url=f'http://127.0.0.1:{server.server_port}'
            result=bench.run(url,TOKEN,PROJECT,'app',requests=6,batch=12,concurrency=3,signing_key_id=KEY,signing_secret=SECRET,verify_explorer=True)
            self.assertEqual(result['events_verified'],50);self.assertTrue(result['explorer_verified']);self.assertEqual(result['signing_version'],2)
            Fixture.corrupt_export=True
            with self.assertRaisesRegex(RuntimeError,'Verified export differs'):bench.run(url,TOKEN,PROJECT,'app',requests=3,batch=2,verify_explorer=True)
        finally:Fixture.corrupt_export=False;server.shutdown();server.server_close();thread.join()
    def test_incomplete_signing_config(self):
        with self.assertRaises(ValueError):bench.run('http://localhost',TOKEN,PROJECT,'app',signing_key_id=KEY)

if __name__=='__main__':unittest.main()
