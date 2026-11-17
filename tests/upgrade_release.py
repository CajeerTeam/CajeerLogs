"""Real 0.1.0, 0.2.0, 0.2.9, 0.3.0 or 0.3.1 or 0.3.2 or 0.3.3 or 0.3.4 or 0.3.5 or 0.3.6 or 0.3.7 or 0.3.8 or 0.3.9 or 0.4.0 or 0.4.1 or 0.4.2 or 0.4.3 or 0.4.4 or 0.4.5 or 0.4.6 or 0.4.7 or 0.4.8 or 0.4.9 or 0.4.10 or 0.5.0 or 0.5.1 or 0.5.2 or 0.5.3 or 0.5.4 -> 0.5.5 upgrade using the previous source tree and PostgreSQL.

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
    if old_version not in ('0.1.0','0.2.0','0.2.9','0.3.0','0.3.1','0.3.2','0.3.3','0.3.4','0.3.5','0.3.6','0.3.7','0.3.8','0.3.9','0.4.0','0.4.1','0.4.2','0.4.3','0.4.4','0.4.5','0.4.6','0.4.7','0.4.8','0.4.9','0.4.10','0.5.0','0.5.1','0.5.2','0.5.3','0.5.4'): raise SystemExit('Expected an actual supported previous source tree (through 0.5.4).')
    old_migrations={p.name:p.read_bytes() for p in (previous/'migrations/pgsql').glob('*.sql')}
    new_migrations={p.name:p.read_bytes() for p in (ROOT/'migrations/pgsql').glob('*.sql')}
    expected={'0009_project_management.sql','0010_source_management.sql','0011_token_management.sql','0012_database_integrity.sql','0013_ingest_signing.sql','0014_delivery_idempotency.sql'} if old_version=='0.1.0' else ({'0013_ingest_signing.sql','0014_delivery_idempotency.sql'} if old_version=='0.2.0' else set())
    if old_version not in ('0.3.2','0.3.3','0.3.4','0.3.5','0.3.6','0.3.7','0.3.8','0.3.9','0.4.0','0.4.1','0.4.2','0.4.3','0.4.4','0.4.5','0.4.6','0.4.7','0.4.8','0.4.9','0.4.10','0.5.0','0.5.1','0.5.2','0.5.3','0.5.4'): expected.add('0015_authentication_sessions.sql')
    if old_version not in ('0.3.5','0.3.6','0.3.7','0.3.8','0.3.9','0.4.0','0.4.1','0.4.2','0.4.3','0.4.4','0.4.5','0.4.6','0.4.7','0.4.8','0.4.9','0.4.10','0.5.0','0.5.1','0.5.2','0.5.3','0.5.4'): expected.add('0016_saved_log_views.sql')
    if old_version not in ('0.4.2','0.4.3','0.4.4','0.4.5','0.4.6','0.4.7','0.4.8','0.4.9','0.4.10','0.5.0','0.5.1','0.5.2','0.5.3','0.5.4'): expected.add('0017_rbac_roles_permissions.sql')
    if old_version not in ('0.4.3','0.4.4','0.4.5','0.4.6','0.4.7','0.4.8','0.4.9','0.4.10','0.5.0','0.5.1','0.5.2','0.5.3','0.5.4'): expected.add('0018_identity_security.sql')
    if old_version not in ('0.4.4','0.4.5','0.4.6','0.4.7','0.4.8','0.4.9','0.4.10','0.5.0','0.5.1','0.5.2','0.5.3','0.5.4'): expected.add('0019_audit_accountability.sql')
    if old_version not in ('0.4.5','0.4.6','0.4.7','0.4.8','0.4.9','0.4.10','0.5.0','0.5.1','0.5.2','0.5.3','0.5.4'): expected.add('0020_rate_limit_buckets.sql')
    if old_version not in ('0.4.9','0.4.10','0.5.0','0.5.1','0.5.2','0.5.3','0.5.4'): expected.add('0021_retention_execution_history.sql')
    if old_version not in ('0.5.1','0.5.2','0.5.3','0.5.4'): expected.add("0022_queue_accounting.sql")
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
            accepted_budget=sql(f'SELECT max_attempts FROM "{schema}".jobs WHERE id={int(jid)}')
            backup=json.loads(cli('backup:create')); assert backup['version']==old_version
            # Overlay the verified public distribution, not a source fixture emulating 0.1.8.
            package=p/'release.zip'; release.build(ROOT,package); extracted=p/'new'; release.verify(package,extracted)
            shutil.copytree(extracted,tree,dirs_exist_ok=True)
            assert (tree/'.env').read_bytes()==before
            assert cli('version').strip()=='0.5.5'
            if old_version in ('0.4.6','0.4.7','0.4.8','0.4.9','0.4.10','0.5.0','0.5.1','0.5.2','0.5.3','0.5.4'):
                proof=json.loads(cli('upgrade:check',backup['id'])); assert proof['status']=='ok' and proof['backup_sha256']==backup['sha256'] and proof['pending']==sorted(expected)
                assert (tree/'.env').read_bytes()==before
            if old_version=='0.5.2': env['QUEUE_MAX_ATTEMPTS']='3'
            cli('migrate'); cli('doctor')
            assert sql(f'SELECT max_attempts FROM "{schema}".jobs WHERE id={int(jid)}')==accepted_budget
            capacity=json.loads(cli('queue:capacity')); assert capacity['active_jobs']==1 and capacity['active_events']==1 and capacity['active_bytes']>0
            assert json.loads(cli('queue:verify'))['valid']
            assert json.loads(cli('audit:verify'))['valid']; cli('queue:work','10'); assert json.loads(cli('queue:verify'))['valid']; assert json.loads(cli('queue:capacity'))['active_jobs']==0; assert json.loads(cli('db:verify'))['status']=='ok'
            with server():
                assert call('GET','/ready')[0]['version']=='0.5.5'
                assert call('GET','/api/v1/auth/me',headers=auth)[0]['user']['email']=='upgrade@example.test'
                assert call('GET',f'/api/v1/logs/{eid}?project_id={pid}',headers=bearer)[0]['data']['message']=='previous release proof'
                assert call('GET',f'/api/v1/ingest/jobs/{jid}',headers=bearer)[0]['status']=='completed'
                assert call('POST','/api/v1/ingest',event,bearer|{'Idempotency-Key':'before-upgrade-sync'},200)[0]['event_ids']==[eid]
                assert call('POST','/api/v1/ingest/async',event|{'message':'queued before upgrade'},bearer|{'Idempotency-Key':'before-upgrade'},200)[0]['job_id']==jid
                assert len(call('GET',f'/api/v1/logs?project_id={pid}',headers=bearer)[0]['items'])==2
                filters=urllib.parse.urlencode({'project_id':pid,'sources':'["app"]','levels':'["info","error"]','q':'previous release','q_mode':'phrase'})
                assert [row['id'] for row in call('GET','/api/v1/logs?'+filters,headers=bearer)[0]['items']]==[eid]
            # Real encrypted export/import of the historic PostgreSQL dump before fenced rehearsal.
            if old_version in ('0.4.7','0.4.10','0.5.0','0.5.1','0.5.2','0.5.3','0.5.4'):
                offsite=p/'offsite'; offsite.mkdir(mode=0o700)
                env.update(BACKUP_OFFSITE_DIR=str(offsite),BACKUP_ENCRYPTION_KEY=secrets.token_hex(32),BACKUP_OFFSITE_REQUIRE_DISTINCT_DEVICE='false')
                assert json.loads(cli('backup:replicate',backup['id']))['offsite_verified']
                assert json.loads(cli('backup:offsite-verify',backup['id']))['sha256']==backup['sha256']
                shutil.rmtree(p/'backups'/backup['id'])
                assert json.loads(cli('backup:fetch',backup['id']))['sha256']==backup['sha256']
                assert json.loads(cli('backup:run'))['status']=='completed'
                assert json.loads(cli('backup:run'))['status']=='not_due'
            result=json.loads(cli('backup:rehearse' if old_version in ('0.4.7','0.4.10','0.5.0','0.5.1','0.5.2','0.5.3','0.5.4') else 'backup:restore',backup['id'],target)); assert result['status']=='restored' and result['integrity_verified']
            assert sql(f'SELECT count(*) FROM "{schema}".log_events',target)=='1'
            assert sql(f'SELECT count(*) FROM "{schema}".api_tokens WHERE revoked_at IS NULL',target)=='0'
            assert sql(f'SELECT count(*) FROM "{schema}".log_events')=='2'
            if old_version in ('0.4.8','0.4.10','0.5.0','0.5.1','0.5.2','0.5.3','0.5.4'):
                preview=json.loads(cli('retention:preview','10',pid)); assert preview['candidates']==0
                sql(f"UPDATE \"{schema}\".projects SET retention_days=1 WHERE id='{pid}'")
                sql(f"UPDATE \"{schema}\".log_events SET received_at=now()-interval '2 days'")
                assert json.loads(cli('retention:preview','1',pid))['more']
                assert json.loads(cli('retention:run','1'))['removed']==1
                assert json.loads(cli('retention:history'))[0]['total_removed'] in (1,'1')
                assert sql(f'SELECT count(*) FROM "{schema}".log_events')=='1'
            print(f'PASS actual {old_version} install, backup, overlay upgrade, old session/token/log, pending job and restore',flush=True)
        finally:
            sql(f'DROP SCHEMA IF EXISTS "{schema}" CASCADE')
            sql(f'DROP SCHEMA IF EXISTS "{schema}" CASCADE',target)


if __name__=='__main__':
    if len(sys.argv)!=2: raise SystemExit(__doc__)
    main(Path(sys.argv[1]).resolve())
