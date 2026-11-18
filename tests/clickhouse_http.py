#!/usr/bin/env python3
"""Real HTTP transport contract tests against a local restricted ClickHouse-like receiver."""
import http.server
import json
import os
from pathlib import Path
import socketserver
import subprocess
import tempfile
import threading
import urllib.parse

ROOT=Path(__file__).resolve().parents[1]
state={'queries': [],'records': [], 'versions': {}}
class Server(http.server.BaseHTTPRequestHandler):
    def log_message(self,*args): pass
    def do_POST(self):
        p=urllib.parse.urlsplit(self.path)
        params=urllib.parse.parse_qs(p.query)
        body=self.rfile.read(int(self.headers.get('content-length','0'))).decode()
        sql=params.get('query',[body])[0]
        state['queries'].append((sql,params,self.headers.get('X-ClickHouse-User'),self.headers.get('X-ClickHouse-Key')))
        if self.headers.get('X-ClickHouse-Key')!='secret':
            self.send_response(401);self.end_headers();self.wfile.write(b'authentication failed');return
        if 'SELECT version()' in sql: data=[{'version':'25.8.1'}]
        elif 'SELECT version, checksum' in sql: data=[{'version':a,'checksum':b} for a,b in state['versions'].items()]
        elif 'INSERT INTO' in sql:
            records=[json.loads(line) for line in body.strip().splitlines()]
            if 'analytics_schema_migrations' in sql:
                for record in records: state['versions'][record['version']]=record['checksum']
            else: state['records']+=records
            data=None
        elif sql.startswith('SELECT'):
            # The wire test verifies parameterized, project-scoped queries, not aggregation semantics.
            if 'count() AS n' in sql: data=[{'n':0}]
            else: data=[]
        else: data=None
        raw=json.dumps({'data':data}).encode() if data is not None else b''
        self.send_response(200);self.send_header('Content-Length',str(len(raw)));self.end_headers();self.wfile.write(raw)

with socketserver.TCPServer(('127.0.0.1',0),Server) as server:
    t=threading.Thread(target=server.serve_forever,daemon=True);t.start()
    url=f'http://127.0.0.1:{server.server_address[1]}'
    test_php=r'''<?php
    $app=require getenv('TEST_ROOT').'/core/bootstrap.php';
    $c=new \Cajeer\Logs\Analytics\ClickHouseHttpClient(['dsn'=>getenv('CLICKHOUSE_DSN'),'database'=>'cajeer_logs','username'=>'test','password'=>'secret','allow_insecure'=>true,'timeout_seconds'=>3]);
    $m=new \Cajeer\Logs\Analytics\ClickHouseMigrator($c,getenv('TEST_ROOT').'/migrations/clickhouse');
    $one=$m->migrate();$two=$m->migrate();
    if (count($one['applied'])!==3 || count($two['skipped'])!==3) throw new Exception('migration replay');
    $s=new \Cajeer\Logs\Analytics\ClickHouseAnalyticsStore($c);
    $a='11111111-1111-4111-8111-111111111111';$b='22222222-2222-4222-8222-222222222222';
    $s->record(['id'=>$a,'project_id'=>$b,'source_id'=>$a,'occurred_at'=>'2026-11-18T09:00:00Z','received_at'=>'2026-11-18T09:00:00Z','source'=>'unit','level'=>'info','message'=>'hello','context'=>['safe'=>1]]);
    $replica=new \Cajeer\Logs\Analytics\ClickHouseAnalyticsStore($c,true);
    $replicated=['id'=>$a,'project_id'=>$b,'source_id'=>$a,'occurred_at'=>'2026-11-18T09:00:00Z','received_at'=>'2026-11-18T09:00:00Z','source'=>'unit','level'=>'info','message'=>'new version','context'=>['safe'=>1],'revision'=>4,'deleted'=>0];
    $replica->record($replicated);
    $replica->record(array_replace($replicated,['revision'=>5,'deleted'=>1,'message'=>'']));
    $replica->aggregate(['project_id'=>$b,'from'=>'2026-11-18T00:00:00Z','to'=>'2026-11-19T00:00:00Z']);
    $result=$s->aggregate(['project_id'=>$b,'service'=>"x' OR 1=1 --",'from'=>'2026-11-18T00:00:00Z','to'=>'2026-11-19T00:00:00Z']);
    if ($result['total']!==0) throw new Exception('unexpected result');
    try{$s->aggregate([]);throw new Exception('missing project permitted');}catch(InvalidArgumentException){}
    try{new \Cajeer\Logs\Analytics\ClickHouseHttpClient(['dsn'=>getenv('CLICKHOUSE_DSN'),'database'=>'cajeer_logs','username'=>'test']);throw new Exception('plaintext permitted');}catch(InvalidArgumentException){}
    try{new \Cajeer\Logs\Analytics\ClickHouseHttpClient(['dsn'=>'http://user:pass@127.0.0.1','database'=>'cajeer_logs','username'=>'test','allow_insecure'=>true]);throw new Exception('credentials embedded');}catch(InvalidArgumentException){}
    echo 'transport passed';'''
    with tempfile.TemporaryDirectory() as temp:
        script=Path(temp)/'wire.php';script.write_text(test_php)
        env=dict(os.environ,TEST_ROOT=str(ROOT),CLICKHOUSE_DSN=url)
        r=subprocess.run(['php',str(script)],env=env,capture_output=True,text=True,timeout=30)
        if r.returncode: raise RuntimeError(r.stdout+'\n'+r.stderr)
        print(r.stdout.strip())
    assert len(state['versions'])==3
    assert len(state['records'])==3 and state['records'][0]['project_id']=='22222222-2222-4222-8222-222222222222'
    assert state['records'][0]['context']=='{"safe":1}'
    scope=[(sql,p) for sql,p,*_ in state['queries'] if 'FROM `cajeer_logs`.`log_events`' in sql]
    assert scope and all('{project_id:UUID}' in sql and p['param_project_id']==['22222222-2222-4222-8222-222222222222'] and p['param_service']==["x' OR 1=1 --"] for sql,p in scope)
    assert all(user=='test' and key=='secret' for _,_,user,key in state['queries'])
    assert all(p['readonly']==['1'] for sql,p,*_ in state['queries'] if sql.startswith('SELECT'))
    print('ClickHouse HTTP integration assertions passed')

    replica_rows=[r for r in state['records'] if 'revision' in r]
    assert [r['revision'] for r in replica_rows]==[4,5] and [r['deleted'] for r in replica_rows]==[0,1]
    replica_queries=[(sql,p) for sql,p,*_ in state['queries'] if 'replicated_log_events' in sql and sql.startswith('SELECT')]
    assert replica_queries and all('FINAL' in sql and 'deleted=0' in sql and p['param_project_id']==['22222222-2222-4222-8222-222222222222'] for sql,p in replica_queries)
    print('Versioned replica HTTP/FINAL/tombstone assertions passed')
