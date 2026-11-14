"""Real 0.1.0 -> 0.1.1 upgrade using the previous source tree and PostgreSQL.

Usage: CAJEER_TEST_DATABASE=1 python3 tests/upgrade_release.py /path/to/0.1.0
Requires a disposable DB_* database and a separate empty CAJEER_TEST_UPGRADE_RESTORE_DB.
"""
import contextlib
import datetime
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'scripts'))
import release


def main(previous):
    if os.environ.get('CAJEER_TEST_DATABASE')!='1': raise SystemExit('Use a disposable PostgreSQL database only.')
    target=os.environ.get('CAJEER_TEST_UPGRADE_RESTORE_DB')
    if not target or target==os.environ.get('DB_DATABASE'): raise SystemExit('Provision a separate empty restore database.')
    if json.loads((previous/'release.json').read_text())['version']!='0.1.0': raise SystemExit('Expected actual 0.1.0 source tree.')
    old_migrations={p.name:p.read_bytes() for p in (previous/'migrations/pgsql').glob('*.sql')}
    new_migrations={p.name:p.read_bytes() for p in (ROOT/'migrations/pgsql').glob('*.sql')}
    assert old_migrations==new_migrations,'0.1.0 migration history changed'
    schema='cl_upgrade_'+secrets.token_hex(8)
    with tempfile.TemporaryDirectory() as tmp:
        p=Path(tmp); tree=p/'app'; shutil.copytree(previous,tree,ignore=shutil.ignore_patterns('.git','.env','vendor','node_modules'))
        with socket.socket() as s: s.bind(('127.0.0.1',0)); port=s.getsockname()[1]
        url=f'http://127.0.0.1:{port}'; password=secrets.token_hex(24)
        env=dict(os.environ,APP_ENV='test',APP_URL=url,APP_DEBUG='false',DB_SCHEMA=schema,
                 SECURITY_2FA_REQUIRED_FOR_ADMINS='false',CAJEER_ADMIN_EMAIL='upgrade@example.test',CAJEER_ADMIN_PASSWORD=password,
                 BACKUP_DIR=str(p/'backups'),INGEST_REQUIRE_HMAC='false')
        env.pop('CAJEER_ENV_FILE',None)
        pg=dict(os.environ,PGHOST=env['DB_HOST'],PGPORT=env.get('DB_PORT','5432'),PGDATABASE=env['DB_DATABASE'],PGUSER=env['DB_USERNAME'],PGPASSWORD=env['DB_PASSWORD'],PGSSLMODE=env.get('DB_SSLMODE','prefer'))
        def sql(query, database=env['DB_DATABASE']):
            return subprocess.check_output(['psql','-X','-A','-t','-v','ON_ERROR_STOP=1','-d',database,'-c',query],env=pg,text=True).strip()
        def cli(*args): return subprocess.check_output(['php','bin/cajeer',*args],cwd=tree,env=env,text=True,stderr=subprocess.STDOUT)
        @contextlib.contextmanager
        def server():
            with tempfile.TemporaryFile() as log:
                proc=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],cwd=tree,env=env,stdout=log,stderr=log)
                try:
                    for _ in range(100):
                        if proc.poll() is not None: raise RuntimeError('PHP server stopped')
                        try:
                            with socket.create_connection(('127.0.0.1',port),.1): break
                        except OSError: time.sleep(.05)
                    else: raise RuntimeError('PHP server not available')
                    yield
                finally: proc.terminate(); proc.wait(timeout=10)
        def call(method,path,data=None,headers=None,status=200):
            req=urllib.request.Request(url+path,data=None if data is None else json.dumps(data).encode(),method=method,headers={'Content-Type':'application/json'}|(headers or {}))
            try: resp=urllib.request.urlopen(req,timeout=20)
            except urllib.error.HTTPError as e: resp=e
            with resp:
                assert resp.code==status,(method,path,resp.code,status)
                return json.loads(resp.read()),resp.headers
        sql(f'CREATE SCHEMA "{schema}"')
        try:
            assert cli('version').strip()=='0.1.0'
            cli('install','--non-interactive'); before=(tree/'.env').read_bytes()
            with server():
                assert call('GET','/ready')[0]['version']=='0.1.0'
                d,h=call('POST','/api/v1/auth/login',{'email':'upgrade@example.test','password':password}); auth={'Cookie':h['Set-Cookie'].split(';',1)[0],'X-CSRF-Token':d['csrf_token'],'Origin':url}
                pid=call('POST','/api/v1/projects',{'name':'Upgrade proof','slug':'upgrade-proof'},auth,201)[0]['id']
                sid=call('POST',f'/api/v1/projects/{pid}/sources',{'name':'App','slug':'app'},auth,201)[0]['id']
                token=call('POST',f'/api/v1/projects/{pid}/tokens',{'name':'Upgrade token','scopes':['logs:write','logs:read'],'source_id':sid},auth,201)[0]['token']; bearer={'Authorization':'Bearer '+token}
                event={'timestamp':datetime.datetime.now(datetime.timezone.utc).isoformat(),'source':'app','level':'info','message':'previous release proof'}
                eid=call('POST','/api/v1/ingest',event,bearer,201)[0]['event_ids'][0]
                jid=call('POST','/api/v1/ingest/async',event|{'message':'queued before upgrade'},bearer|{'Idempotency-Key':'before-upgrade'},202)[0]['job_id']
            backup=json.loads(cli('backup:create')); assert backup['version']=='0.1.0'
            # Overlay the verified public distribution, not a source fixture emulating 0.1.0.
            package=p/'release.zip'; release.build(ROOT,package); extracted=p/'new'; release.verify(package,extracted)
            shutil.copytree(extracted,tree,dirs_exist_ok=True)
            assert (tree/'.env').read_bytes()==before
            assert cli('version').strip()=='0.1.1'; cli('migrate'); cli('doctor')
            cli('queue:work','10')
            with server():
                assert call('GET','/ready')[0]['version']=='0.1.1'
                assert call('GET','/api/v1/auth/me',headers=auth)[0]['user']['email']=='upgrade@example.test'
                assert call('GET',f'/api/v1/logs/{eid}?project_id={pid}',headers=bearer)[0]['data']['message']=='previous release proof'
                assert call('GET',f'/api/v1/ingest/jobs/{jid}',headers=bearer)[0]['status']=='completed'
                assert len(call('GET',f'/api/v1/logs?project_id={pid}',headers=bearer)[0]['items'])==2
            result=json.loads(cli('backup:restore',backup['id'],target)); assert result['status']=='restored'
            assert sql(f'SELECT count(*) FROM "{schema}".log_events',target)=='1'
            assert sql(f'SELECT count(*) FROM "{schema}".api_tokens WHERE revoked_at IS NULL',target)=='0'
            assert sql(f'SELECT count(*) FROM "{schema}".log_events')=='2'
            print('PASS actual 0.1.0 install, backup, overlay upgrade, old session/token/log, pending job and restore',flush=True)
        finally:
            sql(f'DROP SCHEMA IF EXISTS "{schema}" CASCADE')
            sql(f'DROP SCHEMA IF EXISTS "{schema}" CASCADE',target)


if __name__=='__main__':
    if len(sys.argv)!=2: raise SystemExit(__doc__)
    main(Path(sys.argv[1]).resolve())
