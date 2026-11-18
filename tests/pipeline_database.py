#!/usr/bin/env python3
"""Run the release pipeline against an isolated real PHP HTTP / PostgreSQL schema."""
import json
import datetime
import csv
import hashlib
import io
import os
from pathlib import Path
import secrets
import sys
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
from pipeline_scenario import exercise, CajeerLogsClient

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/"scripts"))
from release import build, verify


def main():
    if os.environ.get('CAJEER_TEST_DATABASE')!='1' or os.environ.get('APP_ENV')!='test':
        raise SystemExit('Set APP_ENV=test and CAJEER_TEST_DATABASE=1 for a disposable database.')
    if not all(os.environ.get(k) for k in ('DB_HOST','DB_DATABASE','DB_USERNAME','DB_PASSWORD')):
        raise SystemExit('Explicit disposable DB credentials are required.')
    schema='cl_pipeline_'+secrets.token_hex(8)
    pg=dict(os.environ,PGHOST=os.environ['DB_HOST'],PGPORT=os.environ.get('DB_PORT','5432'),PGDATABASE=os.environ['DB_DATABASE'],PGUSER=os.environ['DB_USERNAME'],PGPASSWORD=os.environ['DB_PASSWORD'],PGSSLMODE=os.environ.get('DB_SSLMODE','prefer'))
    def sql(statement):
        subprocess.run(['psql','-X','-v','ON_ERROR_STOP=1','-c',statement],env=pg,check=True,timeout=30,stdout=subprocess.DEVNULL)
    with tempfile.TemporaryDirectory(prefix='cl-pipeline-') as tmp:
        tree=Path(tmp)/'app'; archive=Path(tmp)/'release.zip'
        build(ROOT,archive); verify(archive,tree)
        with socket.socket() as sock: sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
        url=f'http://127.0.0.1:{port}'; password=secrets.token_hex(24)
        env=dict(os.environ,APP_ENV='test',APP_URL=url,APP_DEBUG='false',DB_SCHEMA=schema,SECURITY_2FA_REQUIRED_FOR_ADMINS='false',SECURITY_TOTP_KEY=secrets.token_hex(32),CAJEER_ADMIN_EMAIL='pipeline@example.test',CAJEER_ADMIN_PASSWORD=password,INGEST_REQUIRE_HMAC='false',INGEST_MAX_EVENT_AGE_SECONDS='0',INGEST_MAX_EVENT_FUTURE_SECONDS='0',API_RATE_LIMIT_PER_MINUTE='600',CAJEER_WEBHOOK_PIPELINE=secrets.token_hex(32))
        for name in ('CAJEER_ENV_FILE','DB_MIGRATION_USERNAME','DB_MIGRATION_PASSWORD'):env.pop(name,None)
        def cli(*args):return subprocess.check_output(['php','bin/cajeer',*args],cwd=tree,env=env,text=True,timeout=120)
        def http(method,path,body=None,headers=None,expected=200):
            raw=body if isinstance(body,bytes) else (None if body is None else json.dumps(body,separators=(',',':')).encode())
            req=urllib.request.Request(url+path,data=raw,method=method,headers={'Content-Type':'application/json'}|(headers or {}))
            opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
            try:response=opener.open(req,timeout=20)
            except urllib.error.HTTPError as e:response=e
            with response:
                if response.code!=expected:raise AssertionError(f'{method} {path.split("?")[0]} expected {expected}, got {response.code}')
                payload=response.read()
                return (json.loads(payload) if response.headers.get('Content-Type','').startswith('application/json') else payload),response.headers
        def raw_download(path, headers):
            request=urllib.request.Request(url+path,method='GET',headers=headers)
            with urllib.request.build_opener(urllib.request.ProxyHandler({})).open(request,timeout=30) as response:
                return response.read(),response.headers
        def ready_server(process):
            for _ in range(100):
                if process.poll() is not None: raise RuntimeError('PHP runtime stopped')
                try:
                    with socket.create_connection(('127.0.0.1',port),.1): break
                except OSError: time.sleep(.05)
            else: raise RuntimeError('PHP runtime not ready')
            assert http('GET','/ready')[0]['status']=='ok'
        sql(f'CREATE SCHEMA "{schema}"')
        try:
            cli('install','--non-interactive')
            with tempfile.TemporaryFile() as log:
                proc=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],cwd=tree,env=env,stdout=log,stderr=log)
                try:
                    ready_server(proc)
                    data,h=http('POST','/api/v1/auth/login',{'email':'pipeline@example.test','password':password})
                    auth={'Cookie':h['Set-Cookie'].split(';',1)[0],'X-CSRF-Token':data['csrf_token'],'Origin':url}
                    result=exercise(http,url,auth,lambda:cli('queue:work','10'))
                    assert json.loads(cli('db:verify'))['status']=='ok'
                    logs=[json.loads(line) for line in cli('logs:export-jsonl',result['project_id']).splitlines()]
                    assert len(logs)==result['events'] and len({r['id'] for r in logs})==result['events'] and all(r['context']['customer_email']=='[redacted]' for r in logs)
                    # Queue and export jobs must survive an actual PHP HTTP process restart.
                    # A real project subscription observes the next queued ingest;
                    # the outbox should be created exactly once on commit.
                    hook,_=http('POST',f"/api/v1/projects/{result['project_id']}/webhooks",
                        {'url':'https://hooks.example.com/ingest',
                         'secret_env':'CAJEER_WEBHOOK_PIPELINE','events':['logs.ingested']},auth,201)
                    assert hook['id']
                    t=result['token'];sign=t['signing']
                    sdk=CajeerLogsClient(url,t['token'],signing_key_id=sign['current_key_id'],hmac_secret=sign['signing_secret'])
                    recovered=sdk.ingest_async({'source':'app','level':'warning',
                        'timestamp':datetime.datetime.now(datetime.timezone.utc).isoformat(),
                        'message':'restart-recovery','request_id':'recovery-'+secrets.token_hex(8)},
                        idempotency_key='pipeline-restart-'+secrets.token_hex(8))
                    queued_job=recovered['job_id']
                    jobpath='/api/v1/ingest/jobs/'+queued_job
                    bearer={'Authorization':'Bearer '+t['token']}
                    assert http('GET',jobpath,headers=bearer)[0]['status']=='pending'
                    path=f"/api/v1/projects/{result['project_id']}/exports"
                    now=datetime.datetime.now(datetime.timezone.utc)
                    filters={'from':(now-datetime.timedelta(hours=2)).isoformat(),
                             'to':(now+datetime.timedelta(hours=1)).isoformat()}
                    exported=[]
                    for format in ('json','csv'):
                        response,_=http('POST',path,{'format':format,'filters':filters},auth,202)
                        item=response['job']
                        assert item['status']=='queued'
                        exported.append(item)
                        http('GET',path+'/'+item['id']+'/download',headers=auth,expected=409)
                    # Stop the gateway after writes have been committed. Restart with no loss of
                    # the PostgreSQL queued job, export jobs, tokens or authenticated session.
                    proc.terminate();proc.wait(timeout=10)
                    proc=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],cwd=tree,env=env,stdout=log,stderr=log)
                    ready_server(proc)
                    assert http('GET',jobpath,headers=bearer)[0]['status']=='pending'
                    worked=json.loads(cli('queue:work','10'))
                    complete,_=http('GET',jobpath,headers=bearer)
                    assert complete['status']=='completed' and len(complete['result']['event_ids'])==1
                    delivery_count=subprocess.check_output(['psql','-X','-t','-A','-v','ON_ERROR_STOP=1',
                        '-c',f"SELECT count(*) FROM \"{schema}\".webhook_deliveries WHERE webhook_id='{hook['id']}'"],
                        env=pg,text=True,timeout=30).strip()
                    assert delivery_count=='1', 'Committed async ingest must create exactly one webhook delivery'
                    assert json.loads(cli('exports:work','2'))['ready']==2
                    for item in exported:
                        info,_=http('GET',path+'/'+item['id'],headers=auth)
                        job=info['job'];assert job['status']=='ready' and job['row_count']==6
                        binary,headers=raw_download(path+'/'+item['id']+'/download',auth)
                        assert len(binary)==job['byte_count'] and hashlib.sha256(binary).hexdigest()==job['checksum']
                        if item['format']=='json':
                            rows=json.loads(binary)
                            assert len(rows)==6 and all(row['project_id']==result['project_id'] for row in rows)
                        else:
                            rows=list(csv.reader(io.StringIO(binary.decode('utf-8-sig'))))
                            assert len(rows)==7
                        http('GET',path+'/'+item['id']+'/download',headers=bearer,expected=403)
                    corrupt=exported[0]
                    location=tree/'storage/exports/private'/(corrupt['id']+'.json')
                    with location.open('ab') as stream:stream.write(b'\nCORRUPT')
                    http('GET',path+'/'+corrupt['id']+'/download',headers=auth,expected=410)
                    assert json.loads(cli('db:verify'))['status']=='ok'
                    print('PASS integrated SDK / durable queue / webhook outbox / gateway restart / JSON+CSV / checksum and corruption rejection',flush=True)

                finally:proc.terminate();proc.wait(timeout=10)
        finally:sql(f'DROP SCHEMA IF EXISTS "{schema}" CASCADE')

if __name__=='__main__':main()
