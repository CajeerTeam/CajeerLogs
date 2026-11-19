"""End-to-end upgrade and rollback certification against real historical releases and PostgreSQL.

Usage: CAJEER_TEST_DATABASE=1 python3 tests/upgrade_release.py /path/to/previous
Requires separate disposable primary, upgrade restore and rollback databases.
"""
import contextlib
import datetime
import hashlib
import json
import os
import re
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
    rollback_target=os.environ.get('CAJEER_TEST_UPGRADE_ROLLBACK_DB')
    if (os.environ.get('APP_ENV')!='test' or not rollback_target or not target
        or len({target, rollback_target, os.environ.get('DB_DATABASE')})!=3
        or rollback_target in ('postgres','template0','template1')):
        raise SystemExit('Use three distinct explicitly provisioned disposable databases and APP_ENV=test.')
    old_version=json.loads((previous/'release.json').read_text())['version']
    if old_version not in ('0.1.0','0.2.0','0.2.9','0.3.0','0.3.1','0.3.2','0.3.3','0.3.4','0.3.5','0.3.6','0.3.7','0.3.8','0.3.9','0.4.0','0.4.1','0.4.2','0.4.3','0.4.4','0.4.5','0.4.6','0.4.7','0.4.8','0.4.9','0.4.10','0.5.0','0.5.1','0.5.2','0.5.3','0.5.4','0.5.5','0.5.6','0.5.7','0.5.8','0.5.9','0.5.10','0.6.0','0.6.1','0.6.2','0.6.3','0.6.4','0.6.5','0.6.6','0.6.7','0.6.8','0.6.9','0.7.0','0.7.1','0.7.2','0.7.3','0.7.4','0.7.5','0.7.6','0.7.7','0.7.8','0.7.9','0.8.0','0.8.1','0.8.2','0.8.3','0.8.4','0.8.5','0.8.6','0.8.7','0.8.8','0.8.9','0.9.0','0.9.1','0.9.2','0.9.3','0.9.4','0.9.5','0.9.6'): raise SystemExit('Expected a genuine supported previous source tree (through 0.9.6).')
    old_migrations={p.name:p.read_bytes() for p in (previous/'migrations/pgsql').glob('*.sql')}
    new_migrations={p.name:p.read_bytes() for p in (ROOT/'migrations/pgsql').glob('*.sql')}
    expected={'0009_project_management.sql','0010_source_management.sql','0011_token_management.sql','0012_database_integrity.sql','0013_ingest_signing.sql','0014_delivery_idempotency.sql'} if old_version=='0.1.0' else ({'0013_ingest_signing.sql','0014_delivery_idempotency.sql'} if old_version=='0.2.0' else set())
    if old_version not in ('0.3.2','0.3.3','0.3.4','0.3.5','0.3.6','0.3.7','0.3.8','0.3.9','0.4.0','0.4.1','0.4.2','0.4.3','0.4.4','0.4.5','0.4.6','0.4.7','0.4.8','0.4.9','0.4.10','0.5.0','0.5.1','0.5.2','0.5.3','0.5.4','0.5.5','0.5.6','0.5.7','0.5.8','0.5.9','0.5.10','0.6.0','0.6.1','0.6.2','0.6.3','0.6.4','0.6.5','0.6.6','0.6.7','0.6.8','0.6.9','0.7.0','0.7.1','0.7.2','0.7.3','0.7.4'): expected.add('0015_authentication_sessions.sql')
    if old_version not in ('0.3.5','0.3.6','0.3.7','0.3.8','0.3.9','0.4.0','0.4.1','0.4.2','0.4.3','0.4.4','0.4.5','0.4.6','0.4.7','0.4.8','0.4.9','0.4.10','0.5.0','0.5.1','0.5.2','0.5.3','0.5.4','0.5.5','0.5.6','0.5.7','0.5.8','0.5.9','0.5.10','0.6.0','0.6.1','0.6.2','0.6.3','0.6.4','0.6.5','0.6.6','0.6.7','0.6.8','0.6.9','0.7.0','0.7.1','0.7.2','0.7.3','0.7.4'): expected.add('0016_saved_log_views.sql')
    if old_version not in ('0.4.2','0.4.3','0.4.4','0.4.5','0.4.6','0.4.7','0.4.8','0.4.9','0.4.10','0.5.0','0.5.1','0.5.2','0.5.3','0.5.4','0.5.5','0.5.6','0.5.7','0.5.8','0.5.9','0.5.10','0.6.0','0.6.1','0.6.2','0.6.3','0.6.4','0.6.5','0.6.6','0.6.7','0.6.8','0.6.9','0.7.0','0.7.1','0.7.2','0.7.3','0.7.4'): expected.add('0017_rbac_roles_permissions.sql')
    if old_version not in ('0.4.3','0.4.4','0.4.5','0.4.6','0.4.7','0.4.8','0.4.9','0.4.10','0.5.0','0.5.1','0.5.2','0.5.3','0.5.4','0.5.5','0.5.6','0.5.7','0.5.8','0.5.9','0.5.10','0.6.0','0.6.1','0.6.2','0.6.3','0.6.4','0.6.5','0.6.6','0.6.7','0.6.8','0.6.9','0.7.0','0.7.1','0.7.2','0.7.3','0.7.4'): expected.add('0018_identity_security.sql')
    if old_version not in ('0.4.4','0.4.5','0.4.6','0.4.7','0.4.8','0.4.9','0.4.10','0.5.0','0.5.1','0.5.2','0.5.3','0.5.4','0.5.5','0.5.6','0.5.7','0.5.8','0.5.9','0.5.10','0.6.0','0.6.1','0.6.2','0.6.3','0.6.4','0.6.5','0.6.6','0.6.7','0.6.8','0.6.9','0.7.0','0.7.1','0.7.2','0.7.3','0.7.4'): expected.add('0019_audit_accountability.sql')
    if old_version not in ('0.4.5','0.4.6','0.4.7','0.4.8','0.4.9','0.4.10','0.5.0','0.5.1','0.5.2','0.5.3','0.5.4','0.5.5','0.5.6','0.5.7','0.5.8','0.5.9','0.5.10','0.6.0','0.6.1','0.6.2','0.6.3','0.6.4','0.6.5','0.6.6','0.6.7','0.6.8','0.6.9','0.7.0','0.7.1','0.7.2','0.7.3','0.7.4'): expected.add('0020_rate_limit_buckets.sql')
    if old_version not in ('0.4.9','0.4.10','0.5.0','0.5.1','0.5.2','0.5.3','0.5.4','0.5.5','0.5.6','0.5.7','0.5.8','0.5.9','0.5.10','0.6.0','0.6.1','0.6.2','0.6.3','0.6.4','0.6.5','0.6.6','0.6.7','0.6.8','0.6.9','0.7.0','0.7.1','0.7.2','0.7.3','0.7.4'): expected.add('0021_retention_execution_history.sql')
    if old_version not in ('0.5.1','0.5.2','0.5.3','0.5.4','0.5.5','0.5.6','0.5.7','0.5.8','0.5.9','0.5.10','0.6.0','0.6.1','0.6.2','0.6.3','0.6.4','0.6.5','0.6.6','0.6.7','0.6.8','0.6.9','0.7.0','0.7.1','0.7.2','0.7.3','0.7.4'): expected.add("0022_queue_accounting.sql")
    if old_version not in ('0.5.6','0.5.7','0.5.8','0.5.9','0.5.10','0.6.0','0.6.1','0.6.2','0.6.3','0.6.4','0.6.5','0.6.6','0.6.7','0.6.8','0.6.9','0.7.0','0.7.1','0.7.2','0.7.3','0.7.4'): expected.add("0023_queue_backpressure.sql")
    if old_version not in ('0.5.7','0.5.8','0.5.9','0.5.10','0.6.0','0.6.1','0.6.2','0.6.3','0.6.4','0.6.5','0.6.6','0.6.7','0.6.8','0.6.9','0.7.0','0.7.1','0.7.2','0.7.3','0.7.4'): expected.add("0024_project_queue_fairness.sql")
    if old_version not in ('0.5.9','0.5.10','0.6.0','0.6.1','0.6.2','0.6.3','0.6.4','0.6.5','0.6.6','0.6.7','0.6.8','0.6.9','0.7.0','0.7.1','0.7.2','0.7.3','0.7.4'): expected.add('0025_queue_operations_metrics.sql')
    if old_version not in ('0.6.7','0.6.8','0.6.9','0.7.0','0.7.1','0.7.2','0.7.3','0.7.4'): expected.add('0026_webhook_subscriptions.sql')
    if old_version not in ('0.6.8','0.6.9','0.7.0','0.7.1','0.7.2','0.7.3','0.7.4'): expected.add('0027_export_jobs.sql')
    if old_version not in ('0.7.2','0.7.3','0.7.4'): expected.add('0028_analytics_replication.sql')
    if old_version not in ('0.7.3','0.7.4'): expected.add('0029_analytics_rollups.sql')
    if old_version!='0.7.4': expected.add('0030_source_ingestion_monitoring.sql')
    expected.update({'0031_error_analytics.sql','0032_advanced_search.sql','0033_storage_tiers.sql'})
    if old_version in ('0.7.5','0.7.6','0.7.7','0.7.8','0.7.9','0.8.0','0.8.1','0.8.2','0.8.3','0.8.4','0.8.5','0.8.6','0.8.7','0.8.8','0.8.9','0.9.0','0.9.1','0.9.2'):
        expected={'0.7.5':{'0031_error_analytics.sql','0032_advanced_search.sql','0033_storage_tiers.sql'},
                  '0.7.6':{'0032_advanced_search.sql','0033_storage_tiers.sql'},
                  '0.7.7':{'0033_storage_tiers.sql'},
                  '0.7.8':set(),'0.7.9':set(),'0.8.0':set(),'0.8.1':set(),'0.8.2':set(),'0.8.3':set(),'0.8.4':set(),'0.8.5':set(),'0.8.6':set(),'0.8.7':set(),'0.8.8':set(),'0.8.9':set(),'0.9.0':set(),'0.9.1':set(),'0.9.2':set()}[old_version]
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
        def cli(*args, directory=None, environ=None):
            return subprocess.check_output(['php','bin/cajeer',*args],cwd=directory or tree,
                                           env=environ or env,text=True,stderr=subprocess.STDOUT,timeout=120)
        @contextlib.contextmanager
        def server(directory=None,environ=None):
            with tempfile.TemporaryFile() as log:
                proc=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],
                                      cwd=directory or tree,env=environ or env,stdout=log,stderr=log)
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
            assert hashlib.sha256((p/'backups'/backup['id']/'database.dump').read_bytes()).hexdigest()==backup['sha256']
            # Preserve the actual old runnable distribution, including its original .env
            # and signing keys. Later rollback must run this old code, not current code.
            rollback_tree=p/'rollback-app'
            shutil.copytree(tree,rollback_tree,ignore=shutil.ignore_patterns('node_modules','vendor','__pycache__'))
            assert (rollback_tree/'.env').read_bytes()==before
            # Overlay the verified public distribution, not a source fixture emulating 0.1.8.
            supplied=os.environ.get('CAJEER_TEST_RELEASE_ARCHIVE')
            if os.environ.get('CAJEER_TEST_REQUIRE_ARCHIVE')=='1' and not supplied:
                raise RuntimeError('Certification requires the same verified release ZIP')
            package=Path(supplied).resolve() if supplied else p/'release.zip'
            if not supplied: release.build(ROOT,package)
            extracted=p/'new'; result=release.verify(package,extracted)
            result_version=result['version']
            if result_version!=json.loads((ROOT/'release.json').read_text())['version']:
                raise RuntimeError('Upgrade tested a different release version')
            shutil.copytree(extracted,tree,dirs_exist_ok=True)
            assert (tree/'.env').read_bytes()==before
            assert cli('version').strip()=='0.9.7'
            if old_version in ('0.4.6','0.4.7','0.4.8','0.4.9','0.4.10','0.5.0','0.5.1','0.5.2','0.5.3','0.5.4','0.5.5','0.5.6','0.5.7','0.5.8','0.5.9','0.5.10','0.6.0','0.6.1','0.6.2','0.6.3','0.6.4','0.6.5','0.6.6','0.6.7','0.6.8','0.6.9','0.7.9','0.8.0','0.8.1','0.8.2','0.8.3','0.8.4','0.8.5','0.8.6','0.8.7','0.8.8','0.8.9','0.9.0','0.9.1','0.9.2','0.9.3'):
                proof=json.loads(cli('upgrade:check',backup['id'])); assert proof['status']=='ok' and proof['backup_sha256']==backup['sha256'] and proof['pending']==sorted(expected)
                assert (tree/'.env').read_bytes()==before
            if old_version=='0.5.2': env['QUEUE_MAX_ATTEMPTS']='3'
            # Exercise the actual production upgrade path, not only the bare migration runner.
            if old_version in ('0.9.0','0.9.1','0.9.2','0.9.3'):
                upgraded=json.loads(cli('upgrade',backup['id'],'--confirm-stopped'))
                assert upgraded['status']=='ready' and upgraded['schema_verified']
                assert upgraded['backup_id']==backup['id'] and upgraded['from']==old_version
                assert upgraded['recorded'] and upgraded['applied']==sorted(expected)
                again=json.loads(cli('upgrade',backup['id'],'--confirm-stopped'))
                assert again['status']=='ready' and again['applied']==[] and not again['recorded']
                assert sql(f"SELECT count(*) FROM \"{schema}\".settings WHERE key='installation.upgrade.0.9.7'")=='1'
            else:
                cli('migrate')
            cli('doctor')
            assert sql(f'SELECT max_attempts FROM "{schema}".jobs WHERE id={int(jid)}')==accepted_budget
            capacity=json.loads(cli('queue:capacity')); assert capacity['active_jobs']==1 and capacity['active_events']==1 and capacity['active_bytes']>0
            assert capacity['max_events']==100000 and capacity['max_bytes']==536870912 and capacity['admission_paused']==0
            assert json.loads(cli('queue:verify'))['valid']
            assert json.loads(cli('queue:project-verify'))['valid']
            project_state=json.loads(cli('queue:project',pid))[0]
            assert project_state['active_jobs']==1 and project_state['active_events']==1 and project_state['weight']==1
            assert json.loads(cli('audit:verify'))['valid']; cli('queue:work','10'); assert json.loads(cli('queue:verify'))['valid']; assert json.loads(cli('queue:capacity'))['active_jobs']==0; assert json.loads(cli('db:verify'))['status']=='ok'
            with server():
                assert call('GET','/ready')[0]['version']=='0.9.7'
                assert call('GET','/api/v1/auth/me',headers=auth)[0]['user']['email']=='upgrade@example.test'
                assert call('GET',f'/api/v1/logs/{eid}?project_id={pid}',headers=bearer)[0]['data']['message']=='previous release proof'
                assert call('GET',f'/api/v1/ingest/jobs/{jid}',headers=bearer)[0]['status']=='completed'
                assert call('POST','/api/v1/ingest',event,bearer|{'Idempotency-Key':'before-upgrade-sync'},200)[0]['event_ids']==[eid]
                assert call('POST','/api/v1/ingest/async',event|{'message':'queued before upgrade'},bearer|{'Idempotency-Key':'before-upgrade'},200)[0]['job_id']==jid
                assert len(call('GET',f'/api/v1/logs?project_id={pid}',headers=bearer)[0]['items'])==2
                filters=urllib.parse.urlencode({'project_id':pid,'sources':'["app"]','levels':'["info","error"]','q':'previous release','q_mode':'phrase'})
                assert [row['id'] for row in call('GET','/api/v1/logs?'+filters,headers=bearer)[0]['items']]==[eid]
            # Real encrypted export/import of the historic PostgreSQL dump before fenced rehearsal.
            if old_version in ('0.4.7','0.4.10','0.5.0','0.5.1','0.5.2','0.5.3','0.5.4','0.5.5','0.5.6','0.5.7','0.5.8','0.5.9','0.5.10','0.6.0','0.6.1','0.6.2','0.6.3','0.6.4','0.6.5','0.6.6'):
                offsite=p/'offsite'; offsite.mkdir(mode=0o700)
                env.update(BACKUP_OFFSITE_DIR=str(offsite),BACKUP_ENCRYPTION_KEY=secrets.token_hex(32),BACKUP_OFFSITE_REQUIRE_DISTINCT_DEVICE='false')
                assert json.loads(cli('backup:replicate',backup['id']))['offsite_verified']
                assert json.loads(cli('backup:offsite-verify',backup['id']))['sha256']==backup['sha256']
                shutil.rmtree(p/'backups'/backup['id'])
                assert json.loads(cli('backup:fetch',backup['id']))['sha256']==backup['sha256']
                assert json.loads(cli('backup:run'))['status']=='completed'
                assert json.loads(cli('backup:run'))['status']=='not_due'
            # Real rollback rehearsal: restore the *pre-upgrade* PostgreSQL
            # archive into a dedicated empty disposable database and boot the
            # unmodified previous release against it. Never downgrade or
            # overwrite the upgraded database in place.
            assert json.loads(cli('backup:verify',backup['id']))['sha256']==backup['sha256']
            assert sql("SELECT current_user=pg_get_userbyid(datdba) FROM pg_database WHERE datname=current_database()",rollback_target)=='t'
            assert sql("SELECT count(*) FROM pg_stat_activity WHERE datname=current_database() AND pid<>pg_backend_pid()",rollback_target)=='0'
            assert sql("SELECT count(*) FROM pg_class WHERE relnamespace='public'::regnamespace",rollback_target)=='0'
            assert sql("SELECT count(*) FROM pg_namespace WHERE nspname NOT LIKE 'pg\\_%' ESCAPE '\\' AND nspname NOT IN ('public','information_schema')",rollback_target)=='0'
            restore=subprocess.run([
                os.environ['PG_RESTORE_BIN'],'--no-password','--exit-on-error','--single-transaction',
                '--no-owner','--no-privileges','--dbname='+rollback_target,
                str(p/'backups'/backup['id']/'database.dump')],
                env=dict(pg,PGDATABASE=rollback_target),capture_output=True,timeout=180)
            if restore.returncode!=0: raise RuntimeError('Historic PostgreSQL rollback restore failed')
            assert sql(f'SELECT count(*) FROM "{schema}".log_events',rollback_target)=='1'
            assert sql(f'SELECT count(*) FROM "{schema}".jobs WHERE id={int(jid)}',rollback_target)=='1'
            assert sql(f'SELECT max_attempts FROM "{schema}".jobs WHERE id={int(jid)}',rollback_target)==accepted_budget
            rollback_env=dict(env,DB_DATABASE=rollback_target)
            assert cli('version',directory=rollback_tree,environ=rollback_env).strip()==old_version
            with server(rollback_tree,rollback_env):
                assert call('GET','/ready')[0]['version']==old_version
                assert call('GET','/api/v1/auth/me',headers=auth)[0]['user']['email']=='upgrade@example.test'
                assert call('GET',f'/api/v1/logs/{eid}?project_id={pid}',headers=bearer)[0]['data']['message']=='previous release proof'
                assert call('POST','/api/v1/ingest',event,bearer|{'Idempotency-Key':'before-upgrade-sync'},200)[0]['event_ids']==[eid]
                assert call('GET',f'/api/v1/ingest/jobs/{jid}',headers=bearer)[0]['status'] in ('pending','queued')
            cli('queue:work','10',directory=rollback_tree,environ=rollback_env)
            with server(rollback_tree,rollback_env):
                assert call('GET',f'/api/v1/ingest/jobs/{jid}',headers=bearer)[0]['status']=='completed'
                assert len(call('GET',f'/api/v1/logs?project_id={pid}',headers=bearer)[0]['items'])==2
            assert sql(f'SELECT count(*) FROM "{schema}".log_events')=='2', 'Rollback modified upgraded live database'
            print(f'PASS actual {old_version} rollback: old PHP runtime + pre-upgrade PostgreSQL dump + API + queued job',flush=True)
            result=json.loads(cli('backup:rehearse' if old_version in ('0.4.7','0.4.10','0.5.0','0.5.1','0.5.2','0.5.3','0.5.4','0.5.5') else 'backup:restore',backup['id'],target)); assert result['status']=='restored' and result['integrity_verified']
            assert sql(f'SELECT count(*) FROM "{schema}".log_events',target)=='1'
            assert sql(f'SELECT count(*) FROM "{schema}".api_tokens WHERE revoked_at IS NULL',target)=='0'
            assert sql(f'SELECT count(*) FROM "{schema}".log_events')=='2'
            if old_version in ('0.4.8','0.4.10','0.5.0','0.5.1','0.5.2','0.5.3','0.5.4','0.5.5','0.5.6','0.5.7','0.5.8','0.5.9','0.5.10','0.6.0','0.6.1','0.6.2','0.6.3','0.6.4','0.6.5','0.6.6'):
                preview=json.loads(cli('retention:preview','10',pid)); assert preview['candidates']==0
                sql(f"UPDATE \"{schema}\".projects SET retention_days=1 WHERE id='{pid}'")
                sql(f"UPDATE \"{schema}\".log_events SET received_at=now()-interval '2 days'")
                assert json.loads(cli('retention:preview','1',pid))['more']
                assert json.loads(cli('retention:run','1'))['removed']==1
                assert json.loads(cli('retention:history'))[0]['total_removed'] in (1,'1')
                assert sql(f'SELECT count(*) FROM "{schema}".log_events')=='1'
            print(f'PASS actual {old_version} install, backup, overlay upgrade, old session/token/log, pending job and restore',flush=True)
            # Durable artifact-bound proof. Write only after *both* directions and
            # the independent candidate restore have actually passed.
            evidence_dir=os.environ.get('CAJEER_TEST_UPGRADE_CERT_DIR')
            if evidence_dir:
                directory=Path(evidence_dir)
                if not directory.is_dir() or directory.is_symlink(): raise RuntimeError('Unsafe upgrade evidence directory')
                checks=['old_install','preupgrade_backup','candidate_integrity','candidate_api',
                        'candidate_queue','candidate_restore','previous_binary_restarted',
                        'original_database_restored','rollback_auth_and_ingest',
                        'rollback_queue_processed','upgraded_database_untouched']
                proof={'format':1,'scope':'upgrade-rollback','from_version':old_version,
                       'to_version':result_version,'archive_sha256':hashlib.sha256(package.read_bytes()).hexdigest(),
                       'backup_sha256':backup['sha256'],'pending_migrations':sorted(expected),
                       'checks':checks,'status':'passed'}
                destination=directory/(old_version+'.json')
                if destination.exists() or destination.is_symlink(): raise RuntimeError('Duplicate upgrade evidence')
                fd=os.open(destination,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
                with os.fdopen(fd,'w') as file:
                    json.dump(proof,file,sort_keys=True,indent=2)
                    file.write('\n'); file.flush(); os.fsync(file.fileno())
        finally:
            sql(f'DROP SCHEMA IF EXISTS "{schema}" CASCADE')
            sql(f'DROP SCHEMA IF EXISTS "{schema}" CASCADE',target)
            sql(f'DROP SCHEMA IF EXISTS "{schema}" CASCADE',rollback_target)


if __name__=='__main__':
    if len(sys.argv)!=2: raise SystemExit(__doc__)
    main(Path(sys.argv[1]).resolve())
