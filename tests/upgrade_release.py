"""Real 0.1.0, 0.2.0, 0.2.9, 0.3.0 or 0.3.1 or 0.3.2 or 0.3.3 or 0.3.4 or 0.3.5 or 0.3.6 or 0.3.7 or 0.3.8 or 0.3.9 or 0.4.0 -> 0.4.1 upgrade using the previous source tree and PostgreSQL.

Usage: CAJEER_TEST_DATABASE=1 python3 tests/upgrade_release.py /path/to/previous
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
import urllib.parse
import urllib.request

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'scripts'))
import release


def main(previous):
    if os.environ.get('CAJEER_TEST_DATABASE')!='1': raise SystemExit('Use a disposable PostgreSQL database only.')
    target=os.environ.get('CAJEER_TEST_UPGRADE_RESTORE_DB')
    if not target or target==os.environ.get('DB_DATABASE'): raise SystemExit('Provision a separate empty restore database.')
    old_version=json.loads((previous/'release.json').read_text())['version']
    if old_version not in ('0.1.0','0.2.0','0.2.9','0.3.0','0.3.1','0.3.2','0.3.3','0.3.4','0.3.5','0.3.6','0.3.7','0.3.8','0.3.9','0.4.0'): raise SystemExit('Expected actual 0.1.0, 0.2.0, 0.2.9, 0.3.0, 0.3.1, 0.3.2, 0.3.3, 0.3.4 0.3.5 0.3.6 or 0.3.7 or 0.3.8 or 0.3.9 or 0.4.0 source tree.')
    old_migrations={p.name:p.read_bytes() for p in (previous/'migrations/pgsql').glob('*.sql')}
    new_migrations={p.name:p.read_bytes() for p in (ROOT/'migrations/pgsql').glob('*.sql')}
    expected={'0009_project_management.sql','0010_source_management.sql','0011_token_management.sql','0012_database_integrity.sql','0013_ingest_signing.sql','0014_delivery_idempotency.sql'} if old_version=='0.1.0' else ({'0013_ingest_signing.sql','0014_delivery_idempotency.sql'} if old_version=='0.2.0' else set())
    if old_version not in ('0.3.2','0.3.3','0.3.4','0.3.5','0.3.6','0.3.7','0.3.8','0.3.9','0.4.0'): expected.add('0015_authentication_sessions.sql')
    if old_version not in ('0.3.5','0.3.6','0.3.7','0.3.8','0.3.9','0.4.0'): expected.add('0016_saved_log_views.sql')
    assert all(new_migrations.get(k)==v for k,v in old_migrations.items()) and set(new_migrations)-set(old_migrations)==expected,'previous migration history changed'
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
            assert cli('version').strip()==old_version
            cli('install','--non-interactive'); before=(tree/'.env').read_bytes()
            with server():
                assert call('GET','/ready')[0]['version']==old_version
                d,h=call('POST','/api/v1/auth/login',{'email':'upgrade@example.test','password':password}); auth={'Cookie':h['Set-Cookie'].split(';',1)[0],'X-CSRF-Token':d['csrf_token'],'Origin':url}
                pid=call('POST','/api/v1/projects',{'name':'Upgrade proof','slug':'upgrade-proof'},auth,201)[0]['id']
                sid=call('POST',f'/api/v1/projects/{pid}/sources',{'name':'App','slug':'app'},auth,201)[0]['id']
                token=call('POST',f'/api/v1/projects/{pid}/tokens',{'name':'Upgrade token','scopes':['logs:write','logs:read'],'source_id':sid},auth,201)[0]['token']; bearer={'Authorization':'Bearer '+token}
                event={'timestamp':datetime.datetime.now(datetime.timezone.utc).isoformat(),'source':'app','level':'info','message':'previous release proof'}
                eid=call('POST','/api/v1/ingest',event,bearer|{'Idempotency-Key':'before-upgrade-sync'},201)[0]['event_ids'][0]
                jid=call('POST','/api/v1/ingest/async',event|{'message':'queued before upgrade'},bearer|{'Idempotency-Key':'before-upgrade'},202)[0]['job_id']
            backup=json.loads(cli('backup:create')); assert backup['version']==old_version
            # Overlay the verified public distribution, not a source fixture emulating 0.1.8.
            package=p/'release.zip'; release.build(ROOT,package); extracted=p/'new'; release.verify(package,extracted)
            shutil.copytree(extracted,tree,dirs_exist_ok=True)
            assert (tree/'.env').read_bytes()==before
            assert cli('version').strip()=='0.4.1'; cli('migrate'); cli('doctor')
            cli('queue:work','10'); assert json.loads(cli('db:verify'))['status']=='ok'
            with server():
                assert call('GET','/ready')[0]['version']=='0.4.1'
                assert call('GET','/api/v1/auth/me',headers=auth)[0]['user']['email']=='upgrade@example.test'
                assert call('GET',f'/api/v1/logs/{eid}?project_id={pid}',headers=bearer)[0]['data']['message']=='previous release proof'
                assert call('GET',f'/api/v1/ingest/jobs/{jid}',headers=bearer)[0]['status']=='completed'
                assert call('POST','/api/v1/ingest',event,bearer|{'Idempotency-Key':'before-upgrade-sync'},200)[0]['event_ids']==[eid]
                assert call('POST','/api/v1/ingest/async',event|{'message':'queued before upgrade'},bearer|{'Idempotency-Key':'before-upgrade'},200)[0]['job_id']==jid
                assert len(call('GET',f'/api/v1/logs?project_id={pid}',headers=bearer)[0]['items'])==2
                filters=urllib.parse.urlencode({'project_id':pid,'sources':'["app"]','levels':'["info","error"]','q':'previous release','q_mode':'phrase'})
                assert [row['id'] for row in call('GET','/api/v1/logs?'+filters,headers=bearer)[0]['items']]==[eid]
            result=json.loads(cli('backup:restore',backup['id'],target)); assert result['status']=='restored' and result['integrity_verified']
            assert sql(f'SELECT count(*) FROM "{schema}".log_events',target)=='1'
            assert sql(f'SELECT count(*) FROM "{schema}".api_tokens WHERE revoked_at IS NULL',target)=='0'
            assert sql(f'SELECT count(*) FROM "{schema}".log_events')=='2'
            print(f'PASS actual {old_version} install, backup, overlay upgrade, old session/token/log, pending job and restore',flush=True)
        finally:
            sql(f'DROP SCHEMA IF EXISTS "{schema}" CASCADE')
            sql(f'DROP SCHEMA IF EXISTS "{schema}" CASCADE',target)


if __name__=='__main__':
    if len(sys.argv)!=2: raise SystemExit(__doc__)
    main(Path(sys.argv[1]).resolve())
