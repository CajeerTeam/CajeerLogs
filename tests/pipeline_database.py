#!/usr/bin/env python3
"""Run the release pipeline against an isolated real PHP HTTP / PostgreSQL schema."""
import json
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
from pipeline_scenario import exercise

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
        env=dict(os.environ,APP_ENV='test',APP_URL=url,APP_DEBUG='false',DB_SCHEMA=schema,SECURITY_2FA_REQUIRED_FOR_ADMINS='false',SECURITY_TOTP_KEY=secrets.token_hex(32),CAJEER_ADMIN_EMAIL='pipeline@example.test',CAJEER_ADMIN_PASSWORD=password,INGEST_REQUIRE_HMAC='false',INGEST_MAX_EVENT_AGE_SECONDS='0',INGEST_MAX_EVENT_FUTURE_SECONDS='0',API_RATE_LIMIT_PER_MINUTE='600')
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
                return json.loads(response.read()),response.headers
        sql(f'CREATE SCHEMA "{schema}"')
        try:
            cli('install','--non-interactive')
            with tempfile.TemporaryFile() as log:
                proc=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],cwd=tree,env=env,stdout=log,stderr=log)
                try:
                    for _ in range(100):
                        if proc.poll() is not None:raise RuntimeError('PHP runtime stopped')
                        try:
                            with socket.create_connection(('127.0.0.1',port),.1):break
                        except OSError:time.sleep(.05)
                    else:raise RuntimeError('PHP runtime not ready')
                    assert http('GET','/ready')[0]['status']=='ok'
                    data,h=http('POST','/api/v1/auth/login',{'email':'pipeline@example.test','password':password})
                    auth={'Cookie':h['Set-Cookie'].split(';',1)[0],'X-CSRF-Token':data['csrf_token'],'Origin':url}
                    result=exercise(http,url,auth,lambda:cli('queue:work','10'))
                    assert json.loads(cli('db:verify'))['status']=='ok'
                    logs=[json.loads(line) for line in cli('logs:export-jsonl',result['project_id']).splitlines()]
                    assert len(logs)==result['events'] and len({r['id'] for r in logs})==result['events'] and all(r['context']['customer_email']=='[redacted]' for r in logs)
                    print('PASS real pipeline database integrity and streaming export',flush=True)
                finally:proc.terminate();proc.wait(timeout=10)
        finally:sql(f'DROP SCHEMA IF EXISTS "{schema}" CASCADE')

if __name__=='__main__':main()
