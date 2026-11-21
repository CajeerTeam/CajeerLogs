"""Install and exercise the shipped production Docker stack over verified TLS.

Runs in disposable Compose volumes, uses no live database or mocked HTTP API.
Requires Docker Engine, Compose v2 and OpenSSL. Never point at production.
"""
import base64
import datetime
import hashlib
import hmac
import json
import os
from pathlib import Path
import secrets
import socket
import ssl
import struct
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from pipeline_scenario import exercise, CajeerLogsClient, encode
from monitoring_stack import monitoring_smoke

ROOT=Path(__file__).resolve().parents[1]


def main():
    with tempfile.TemporaryDirectory(prefix='cajeer-production-') as td:
        temp=Path(td); tls=temp/'tls'; tls.mkdir(mode=0o700)
        subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1',
                        '-keyout',str(tls/'privkey.pem'),'-out',str(tls/'fullchain.pem'),
                        '-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],
                        check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        with socket.socket() as s: s.bind(('127.0.0.1',0)); port=s.getsockname()[1]
        url=f'https://127.0.0.1:{port}'; metrics=secrets.token_hex(32); admin=secrets.token_hex(24)
        env_file=temp/'compose.env'
        env_file.write_text(f'APP_URL={url}\nDB_PASSWORD={secrets.token_hex(32)}\nPOSTGRES_ADMIN_PASSWORD={secrets.token_hex(32)}\nPROMETHEUS_TOKEN={metrics}\nCAJEER_TLS_DIR={tls}\nCAJEER_HTTPS_BIND=127.0.0.1\nCAJEER_HTTPS_PORT={port}\nSECURITY_SESSION_IDLE_SECONDS=1800\nSECURITY_REAUTH_SECONDS=300\nAPI_RATE_EXPORT_PER_MINUTE=10\n'); env_file.chmod(0o600)
        project='clsmoke'+secrets.token_hex(5)
        cmd=['docker','compose','--project-name',project,'--env-file',str(env_file),'-f',str(ROOT/'compose.production.yaml')]
        # Do not let developer environment override disposable test credentials.
        env={k:v for k,v in os.environ.items() if k not in ('APP_URL','DB_PASSWORD','POSTGRES_ADMIN_PASSWORD','PROMETHEUS_TOKEN','CAJEER_TLS_DIR','CAJEER_HTTPS_BIND','CAJEER_HTTPS_PORT','COMPOSE_PROJECT_NAME','COMPOSE_PROFILES','SECURITY_SESSION_IDLE_SECONDS','SECURITY_REAUTH_SECONDS','SECURITY_2FA_REQUIRED_ROLES','SECURITY_2FA_REQUIRED_PERMISSIONS','API_RATE_EXPORT_PER_MINUTE','API_RATE_BURST_PERCENT','QUEUE_WORKER_PROCESSES','QUEUE_WORKER_BATCH_JOBS','QUEUE_WORKER_MAX_JOBS','QUEUE_WORKER_MAX_SECONDS','QUEUE_WORKER_MEMORY_MB','QUEUE_POLL_MIN_MS','QUEUE_POLL_MAX_MS','QUEUE_LEASE_SECONDS','QUEUE_CLAIM_TIMEOUT_MS','QUEUE_PROCESS_TIMEOUT_SECONDS','QUEUE_SHUTDOWN_GRACE_SECONDS','CAJEER_QUEUE_STOP_GRACE')}
        env.update(CAJEER_ADMIN_EMAIL='release@example.test',CAJEER_ADMIN_PASSWORD=admin)
        def compose(*args, capture=False, check=True, timeout=600):
            return subprocess.run(cmd+list(args),cwd=ROOT,env=env,check=check,timeout=timeout,stdout=subprocess.PIPE if capture else None,text=True).stdout
        def cli(*args): return compose('exec','-T','app','php','bin/cajeer',*args,capture=True)
        def postgres_admin(sql, database='cajeer_logs'):
            # PostgreSQL uses mandatory SCRAM even on its private Unix socket.
            # Read the existing superuser secret inside the database container:
            # never copy it to CI logs, Python command arguments or the host.
            return compose('exec', '-T', 'db', 'sh', '-ec',
                           'PGPASSWORD="$POSTGRES_PASSWORD" exec psql -X -w -U postgres -d "$1" -v ON_ERROR_STOP=1 -c "$2"',
                           'cajeer-psql', database, sql)

        context=ssl.create_default_context(cafile=str(tls/'fullchain.pem'))
        def http(method,path,body=None,headers=None,expected=200,raw_response=False):
            raw=body if isinstance(body,bytes) else (None if body is None else json.dumps(body,separators=(',',':')).encode())
            hs={'Accept':'application/json'}; hs.update(headers or {})
            if raw is not None: hs['Content-Type']='application/json'
            req=urllib.request.Request(url+path,data=raw,headers=hs,method=method)
            try: resp=urllib.request.urlopen(req,context=context,timeout=20)
            except urllib.error.HTTPError as err: resp=err
            with resp:
                status=resp.code; data=resp.read(); h=resp.headers
            # Don't log response bodies that might include credentials.
            if status not in (expected if isinstance(expected,tuple) else (expected,)): raise AssertionError(f'{method} {path}: expected {expected}, got {status}')
            return (json.loads(data) if not raw_response and h.get('Content-Type','').startswith('application/json') and data else data),h
        def session(data,headers):
            cookie=headers['Set-Cookie']; assert 'Secure' in cookie and 'HttpOnly' in cookie and 'SameSite=Strict' in cookie
            return {'Cookie':cookie.split(';',1)[0],'X-CSRF-Token':data['csrf_token'],'Origin':url}
        try:
            compose('build',timeout=900); compose('up','-d','--wait','--wait-timeout','180','db')
            compose('run','--rm','--no-deps','-e','CAJEER_ADMIN_EMAIL','-e','CAJEER_ADMIN_PASSWORD','install')
            # Installer is idempotent; this must verify the existing password, not reset it.
            compose('run','--rm','--no-deps','-e','CAJEER_ADMIN_EMAIL','-e','CAJEER_ADMIN_PASSWORD','install')
            compose('up','-d','--wait','--wait-timeout','180')
            for _ in range(60):
                try:
                    data,_=http('GET','/ready'); assert data['version']=='1.0.0' and data['status']=='ok'; break
                except (OSError, AssertionError, urllib.error.URLError): time.sleep(1)
            else: raise AssertionError('HTTPS readiness did not become healthy')
            panel,h=http('GET','/admin/'); assert b'1.0.0' in panel and 'frame-ancestors' in h['Content-Security-Policy']
            assert b'app/router.js' in http('GET','/admin/assets/panel.js')[0]
            assert b'createApiClient' in http('GET','/admin/assets/api/client.js')[0]
            http('GET','/.env',expected=404)
            http('GET','/admin/',headers={'Host':'evil.example.test'},expected=400)
            http('GET','/admin/assets/panel.js',headers={'Host':'evil.example.test'},expected=400)
            _, headers=http('GET','/admin/assets/panel.js'); assert headers['X-Frame-Options']=='DENY' and headers['Referrer-Policy']=='no-referrer' and headers['Strict-Transport-Security']=='max-age=31536000'
            http('GET','/admin/',headers={'X-Forwarded-Proto':'http','X-Forwarded-For':'203.0.113.1','X-Forwarded-Host':'evil.example.test'})
            http('GET','/metrics',expected=401)
            assert b'cajeerlogs_operations_available 1' in http('GET','/metrics',headers={'Authorization':'Bearer '+metrics})[0]
            print('PASS clean install, reinstall, production HTTPS, assets, readiness and metrics',flush=True)
            password={'email':'release@example.test','password':admin}
            d,h=http('POST','/api/v1/auth/login',password); assert d['enrollment_required']; auth=session(d,h)
            http('GET','/api/v1/projects',headers=auth,expected=403)
            d,_=http('POST','/api/v1/auth/2fa/setup',{'password':admin},auth)
            secret=base64.b32decode(d['secret']); step=int(time.time())//30
            mac=hmac.new(secret,struct.pack('>Q',step),hashlib.sha1).digest(); offset=mac[-1]&15
            code=f'{(int.from_bytes(mac[offset:offset+4],"big")&0x7fffffff)%1000000:06d}'
            d,h=http('POST','/api/v1/auth/2fa/enable',{'otp':code},auth); assert len(d['recovery_codes'])==10; auth=session(d,h)
            recovery_codes=d['recovery_codes']; recovery=recovery_codes[0]
            http('POST','/api/v1/auth/login',password,expected=401)
            d,h=http('POST','/api/v1/auth/login',password|{'otp':recovery}); auth=session(d,h)
            http('POST','/api/v1/auth/login',password|{'otp':recovery},expected=401)
            p,_=http('POST','/api/v1/projects',{'name':'Release smoke','slug':'release-smoke'},auth,201); pid=p['id']
            source,_=http('POST',f'/api/v1/projects/{pid}/sources',{'name':'Application','slug':'app'},auth,201)
            t,_=http('POST',f'/api/v1/projects/{pid}/tokens',{'name':'Smoke client','scopes':['logs:write','logs:read'],'source_id':source['id']},auth,201)
            bearer={'Authorization':'Bearer '+t['token']}
            event={'timestamp':datetime.datetime.now(datetime.timezone.utc).isoformat(),'source':'app','level':'error','message':'release smoke error','context':{'password':'must-not-store','proof':'release'}}
            d,_=http('POST','/api/v1/ingest',event,bearer|{'Idempotency-Key':'release-sync'},201); eventid=d['event_ids'][0]
            d,_=http('POST','/api/v1/ingest',event,bearer|{'Idempotency-Key':'release-sync'}); assert d['replayed'] and d['event_ids']==[eventid]
            d,h=http('POST','/api/v1/ingest',event,bearer|{'Content-Encoding':'gzip'},415); assert d['error']=='unsupported_media_type' and d['request_id']==h['X-Request-ID']
            m,_=http('GET','/metrics',headers={'Authorization':'Bearer '+metrics}); assert b'cajeerlogs_ingest_requests_total{mode="sync",status="201"}' in m and b'cajeerlogs_ingest_duration_seconds_count{mode="sync"}' in m
            d,_=http('POST','/api/v1/ingest/async',event|{'message':'release async error'},bearer|{'Idempotency-Key':'release-async'},202); job=d['job_id']
            for _ in range(60):
                d,_=http('GET',f'/api/v1/ingest/jobs/{job}',headers=bearer)
                if d['status']=='completed': break
                assert d['status'] in ('pending','running'); time.sleep(1)
            else: raise AssertionError('Queue did not complete real job')
            assert json.loads(cli('queue:verify'))['valid']
            assert json.loads(cli('queue:project-verify'))['valid']
            assert json.loads(cli('queue:capacity'))['active_jobs']==0
            m,_=http('GET','/metrics',headers={'Authorization':'Bearer '+metrics}); assert b'cajeerlogs_queue_active_jobs 0' in m and b'cajeerlogs_queue_active_bytes 0' in m
            query=urllib.parse.urlencode({'project_id':pid,'q':'release','level':'error'})
            d,_=http('GET','/api/v1/logs?'+query,headers=bearer); assert len(d['items'])==2
            expanded=urllib.parse.urlencode({'project_id':pid,'q':'release error','q_mode':'plain','levels':'["error","warning"]','sources':'["app"]','context':'{"proof":"release"}'})
            d,_=http('GET','/api/v1/logs?'+expanded,headers=bearer); assert len(d['items'])==2
            m,_=http('GET','/metrics',headers={'Authorization':'Bearer '+metrics}); assert b'cajeerlogs_search_requests_total{mode="list",status="200"}' in m
            d,_=http('GET',f'/api/v1/logs/{eventid}?project_id={pid}',headers=bearer); assert d['data']['context']['password']=='[redacted]'
            csv,_=http('GET','/api/v1/logs/export?'+query+'&format=csv',headers=bearer); assert eventid.encode() in csv
            print('PASS real TOTP, recovery, token, synchronous and async ingest, worker, search and export',flush=True)
            # Prove policy composition over real TLS: an expired recent-auth window, a fresh
            # second factor, then a restricted operator with a live session and no export.
            postgres_admin("UPDATE auth_sessions SET reauthenticated_at=now()-interval '10 minutes' WHERE user_id=(SELECT id FROM users WHERE email='release@example.test')")
            role_body={'name':'Smoke read only','scope':'global','permissions':['projects:read','logs:read','tokens:read','tokens:create']}
            denied,_=http('POST','/api/v1/roles',role_body,auth,403); assert denied['error']=='reauthentication_required'
            fresh,_=http('POST','/api/v1/auth/reauthenticate',{'password':admin,'otp':recovery_codes[1]},auth); assert fresh['security']['reauth_seconds']==300
            role,_=http('POST','/api/v1/roles',role_body,auth,201)
            op_password=secrets.token_hex(24); op_email='operator@release.test'
            op,_=http('POST','/api/v1/users',{'email':op_email,'password':op_password,'role':'operator'},auth,201)
            http('PUT',f"/api/v1/projects/{pid}/members/{op['id']}",headers=auth,expected=204)
            d,h=http('POST','/api/v1/auth/login',{'email':op_email,'password':op_password}); operator=session(d,h)
            http('PUT',f"/api/v1/users/{op['id']}/role-assignment",{'role_id':role['id'],'expected_role_id':None},auth,204)
            d,_=http('GET','/api/v1/auth/me',headers=operator); assert 'logs:export' not in d['user']['permissions']
            http('GET','/api/v1/logs?'+query,headers=operator)
            http('GET','/api/v1/logs/export?'+query+'&format=csv',headers=operator,expected=403)
            http('POST',f'/api/v1/projects/{pid}/tokens',{'name':'Forbidden export delegation','scopes':['logs:read']},operator,403)
            audit=json.loads(cli('audit:verify')); assert audit['valid'] and audit['entries']>0
            print('PASS production step-up 2FA, live RBAC restriction, token delegation refusal and audit integrity',flush=True)

            scheduled=json.loads(cli('backup:run')); assert scheduled['status']=='completed' and scheduled['offsite'] is None
            m=scheduled['backup']; assert m['bytes']>0
            assert json.loads(cli('backup:run'))['status']=='not_due'
            assert json.loads(cli('backup:verify',m['id']))['sha256']==m['sha256']
            postgres_admin('CREATE DATABASE cajeer_restore_smoke OWNER cajeer_logs', database='postgres')
            restored=json.loads(cli('backup:rehearse',m['id'],'cajeer_restore_smoke')); assert restored['api_tokens_revoked'] and restored['integrity_verified']
            history=json.loads(cli('backup:history')); assert history['state']['last_restore_id']==m['id'] and history['state']['last_success_id']==m['id']
            # Independent target connection: check actual restored events, credentials and source isolation.
            proof=compose('exec','-T','-e','DB_DATABASE=cajeer_restore_smoke','app','php','-r',
                '$a=require "core/bootstrap.php"; $a->boot(); $p=$a->container()->get(PDO::class); echo json_encode([$p->query("SELECT count(*) FROM log_events")->fetchColumn(),$p->query("SELECT count(*) FROM api_tokens WHERE revoked_at IS NULL")->fetchColumn()]);',capture=True)
            assert list(map(int,json.loads(proof)))==[2,0]
            d,_=http('GET','/api/v1/logs?'+query,headers=bearer); assert len(d['items'])==2
            # Restart runtime with the persistent config and storage; data must survive.
            compose('restart','app'); time.sleep(3)
            for _ in range(30):
                try:
                    d,_=http('GET','/api/v1/logs?'+query,headers=bearer); assert len(d['items'])==2; break
                except (OSError,AssertionError,urllib.error.URLError): time.sleep(1)
            else: raise AssertionError('Data unavailable after restart')
            print('PASS pg_dump/pg_restore, restored data and credential revocation, persistent restart',flush=True)
            # Two containers, each with two supervised PHP processes, exercise real parallel claims.
            compose('up','-d','--wait','--wait-timeout','180','--scale','queue=2','queue')
            for _ in range(60):
                pool=json.loads(cli('queue:workers'))
                live=[w for w in pool['workers'] if w['status']=='ok' and w['pool']]
                if len(live)==4 and len({w['host'] for w in live})==2: break
                time.sleep(1)
            else: raise AssertionError('Expected four independently progressing queue workers')
            # Kill one child in the first pool, not the supervisor; replacement gets a new ID.
            local=json.loads(compose('exec','-T','--index','1','queue','php','bin/cajeer','queue:pool-health',capture=True))
            host=compose('exec','-T','--index','1','queue','php','-r','echo substr(hash("sha256",gethostname()),0,16);',capture=True).strip()
            victim=next(w for w in local['workers'] if w['host']==host and w['status']=='ok' and w['pool'])
            compose('exec','-T','--index','1','queue','php','-r',f'if(!posix_kill({victim["pid"]},SIGKILL)) exit(1);')
            for _ in range(60):
                local=json.loads(compose('exec','-T','--index','1','queue','php','bin/cajeer','queue:pool-health',capture=True,check=False))
                replacement=[w for w in local['workers'] if w['host']==host and w['status']=='ok' and w['pool']]
                if local['status']=='ok' and len(replacement)==2 and victim['id'] not in {w['id'] for w in replacement}: break
                time.sleep(1)
            else: raise AssertionError('Supervised queue child did not recover')
            print('PASS four real queue workers, per-container health and SIGKILL replacement',flush=True)
            # Use the real PHP-FPM gateway and live queue workers, not a mock benchmark.
            bench=subprocess.check_output(['python3',str(ROOT/'scripts/benchmark.py'),'--url',url,'--project',pid,'--source','app','--requests','12','--batch','5','--concurrency','4','--ca-file',str(tls/'fullchain.pem'),'--confirm'],env=dict(env,CAJEER_TOKEN=t['token']),text=True,timeout=150)
            measured=json.loads(bench); assert measured['events_verified']==44 and measured['errors']==0
            print(bench,flush=True)
            # Exercise the exact production HTTPS runtime with a separate v2 credential,
            # rotated bearer, inherited policies, worker, snapshot and verified export.
            http('POST','/api/v1/auth/reauthenticate',{'password':admin,'otp':recovery_codes[2]},auth)
            integrated=exercise(http,url,auth,lambda:None,str(tls/'fullchain.pem'))
            signing=integrated['token']['signing']
            # Deliberately stop every queue consumer before creating a real async job.
            # The durable PostgreSQL receipt must survive and complete after restart.
            compose('stop','queue')
            sdk=CajeerLogsClient(url,integrated['token']['token'],
                signing_key_id=signing['current_key_id'],hmac_secret=signing['signing_secret'],
                ca_file=str(tls/'fullchain.pem'))
            durable_event={'source':'app','level':'warning',
                'timestamp':datetime.datetime.now(datetime.timezone.utc).isoformat(),
                'message':'queue restart certification','request_id':'queue-restart-'+secrets.token_hex(8)}
            durable_body=encode(durable_event)
            durable_key='queue-restart-'+secrets.token_hex(12)
            queued=sdk.send_async_body(durable_body,durable_key)
            queued_id=queued['job_id']
            job_url='/api/v1/ingest/jobs/'+queued_id
            auth_token={'Authorization':'Bearer '+integrated['token']['token']}
            status,_=http('GET',job_url,headers=auth_token)
            assert status['status']=='pending', 'Stopped queue processed a job'
            replay=sdk.send_async_body(durable_body,durable_key)
            assert replay['replayed'] and replay['job_id']==queued_id
            compose('up','-d','--wait','--wait-timeout','180','--scale','queue=2','queue')
            for _ in range(100):
                status,_=http('GET',job_url,headers=auth_token)
                if status['status']=='completed':break
                assert status['status'] in ('pending','running')
                time.sleep(.5)
            else: raise AssertionError('Durable async job did not recover after queue outage')
            assert len(status['result']['event_ids'])==1
            assert json.loads(cli('queue:verify'))['valid']
            assert json.loads(cli('queue:project-verify'))['valid']
            print('PASS real queue shutdown, persisted async receipt, idempotent retry and worker recovery',flush=True)
            # Full JSON and CSV background exports from the authenticated Admin session.
            # This exercises PostgreSQL leasing, the file store, checksums and live download.
            exports='/api/v1/projects/'+integrated['project_id']+'/exports'
            now=datetime.datetime.now(datetime.timezone.utc)
            filters={'from':(now-datetime.timedelta(hours=2)).isoformat(),
                     'to':(now+datetime.timedelta(hours=1)).isoformat()}
            export_jobs=[]
            for format in ('json','csv'):
                result,_=http('POST',exports,{'format':format,'filters':filters},auth,202)
                export_jobs.append(result['job'])
                http('GET',exports+'/'+result['job']['id']+'/download',headers=auth,expected=409)
            completed_exports=json.loads(cli('exports:work','2'))
            assert completed_exports['ready']==2 and completed_exports['failed']==0
            for export_job in export_jobs:
                item,_=http('GET',exports+'/'+export_job['id'],headers=auth)
                item=item['job']; assert item['status']=='ready' and item['row_count']==6
                data,_=http('GET',exports+'/'+item['id']+'/download',headers=auth,raw_response=True)
                assert len(data)==item['byte_count'] and hashlib.sha256(data).hexdigest()==item['checksum']
                if item['format']=='json':
                    rows=json.loads(data)
                    assert len(rows)==6 and all(r['project_id']==integrated['project_id'] for r in rows)
                else:
                    assert len(data.decode('utf-8-sig').splitlines())==7
                http('GET',exports+'/'+item['id']+'/download',headers=auth_token,expected=403)
            # Corrupt a disposable on-disk artifact; it must never be streamed as valid data.
            damaged=export_jobs[0]['id']
            compose('exec','-T','app','php','-r',
                f'file_put_contents("storage/exports/private/{damaged}.json","CORRUPT",FILE_APPEND);')
            http('GET',exports+'/'+damaged+'/download',headers=auth,expected=410)
            print('PASS live JSON/CSV background export, role enforcement, SHA-256 and file corruption rejection',flush=True)
            bench_v2=subprocess.check_output(['python3',str(ROOT/'scripts/benchmark.py'),'--url',url,'--project',integrated['project_id'],'--source','app','--requests','12','--batch','5','--concurrency','4','--ca-file',str(tls/'fullchain.pem'),'--verify-explorer','--confirm'],env=dict(env,CAJEER_TOKEN=integrated['token']['token'],CAJEER_SIGNING_KEY_ID=signing['current_key_id'],CAJEER_SIGNING_SECRET=signing['signing_secret']),text=True,timeout=150)
            measured_v2=json.loads(bench_v2); assert measured_v2['events_verified']==44 and measured_v2['errors']==0 and measured_v2['explorer_verified'] and measured_v2['signing_version']==2
            print(bench_v2,flush=True)
            # Real four-scenario performance gate on this disposable Docker/PostgreSQL/TLS stack.
            load_cmd=['python3',str(ROOT/'scripts/load_test.py'),'--url',url,'--project',pid,
                      '--source','app','--scenario','all','--rate','4','--batch','20',
                      '--concurrency','4','--async-percent','100',
                      '--sustained-seconds','3','--stress-seconds','3',
                      '--soak-seconds','3','--fault-seconds','3',
                      '--drain-seconds','120','--p95-ms','5000','--fault-p95-ms','5000',
                      '--min-rate-ratio','0.8','--ca-file',str(tls/'fullchain.pem'),'--confirm-disposable']
            perf=subprocess.run(load_cmd,env=dict(env,CAJEER_TOKEN=t['token'],CAJEER_LOAD_TEST='1'),
                                text=True,capture_output=True,timeout=180)
            assert perf.returncode==0, 'Real load/fault tests did not pass on Docker PostgreSQL runtime'
            perf_report=json.loads(perf.stdout)
            assert perf_report['status']=='passed' and not perf_report['certified']
            assert [p['scenario'] for p in perf_report['phases']]==['sustained','stress','soak','fault']
            assert all(p['durable_events']==p['verified_events'] for p in perf_report['phases'])
            assert all(p['async_jobs']>0 for p in perf_report['phases'])
            assert json.loads(cli('queue:verify'))['valid'] and json.loads(cli('queue:project-verify'))['valid']
            assert json.loads(cli('queue:capacity'))['active_jobs']==0
            assert perf_report['phases'][-1]['fault_injection']['lost_ack']>0
            print('PASS live sustained/stress/soak/transport fault injection and exact durability verification',flush=True)
            # Full qualification is enabled by scripts/certify.py release only.
            # It uses the same real workers, PostgreSQL and trusted HTTPS origin
            # as the short integration smoke; no mock server or synthetic RPS.
            archive=os.environ.get('CAJEER_PERF_ARCHIVE_PATH')
            report_path=os.environ.get('CAJEER_PERF_REPORT_PATH')
            if bool(archive)!=bool(report_path):
                raise AssertionError('Incomplete high-throughput certification configuration')
            if archive:
                assert Path(archive).is_file() and Path(report_path).is_absolute()
                full_cmd=['python3',str(ROOT/'scripts/load_test.py'),'--url',url,
                    '--project',pid,'--source','app','--scenario','all','--rate','4',
                    '--read-rps','1','--batch','20','--concurrency','12',
                    '--async-percent','100',
                    '--sustained-seconds','60','--stress-seconds','120',
                    '--soak-seconds','900','--fault-seconds','60',
                    '--drain-seconds','300','--p95-ms','5000','--fault-p95-ms','8000',
                    '--min-rate-ratio','.85','--ca-file',str(tls/'fullchain.pem'),
                    '--archive',archive,'--certify','--output',report_path,
                    '--confirm-disposable']
                full_run=subprocess.run(full_cmd,env=dict(env,CAJEER_TOKEN=t['token'],CAJEER_LOAD_TEST='1'),
                                        text=True,capture_output=True,timeout=3000)
                if full_run.returncode!=0:
                    # No credentials, response body or private payload in the error.
                    raise AssertionError('Live long-duration high-throughput certification failed')
                full_report=json.loads(Path(report_path).read_text())
                assert full_report['status']=='passed' and full_report['certified']
                assert all(p['durable_events']==p['verified_events'] and p['async_jobs']>0
                           for p in full_report['phases'])
                assert json.loads(cli('queue:verify'))['valid']
                assert json.loads(cli('queue:project-verify'))['valid']
                assert json.loads(cli('queue:capacity'))['active_jobs']==0
                print('PASS sustained/stress/soak/fault live async throughput certification',flush=True)
            assert json.loads(cli('db:verify'))['status']=='ok'
            assert json.loads(cli('db:performance'))['tables']
            assert json.loads(cli('db:explain',pid,'--analyze'))['plans']['latest']
            # Export limit is enforced by the real shared PostgreSQL bucket.
            for _ in range(40):
                result,headers=http('GET','/api/v1/logs/export?'+query+'&format=csv',headers=bearer,expected=(200,429))
                if isinstance(result,dict) and result.get('error')=='rate_limited':
                    assert 1<=int(headers['Retry-After'])<=60; break
            else: raise AssertionError('Real export rate budget did not reject requests')
            # Retention is deterministic with the scheduler temporarily stopped. Keep it running
            # again for the monitor checks. Only the intentionally aged event may be removed.
            compose('stop','scheduler')
            cli('retention:set',pid,'1')
            postgres_admin(f"UPDATE log_events SET received_at=now()-interval '2 days' WHERE id='{eventid}'")
            assert json.loads(cli('retention:preview','10',pid))['candidates']==1
            assert json.loads(cli('retention:run','10'))['removed']==1
            http('GET',f'/api/v1/logs/{eventid}?project_id={pid}',headers=bearer,expected=404)
            compose('up','-d','--wait','--wait-timeout','180','scheduler')
            proof=json.loads(cli('operations:check',m['id'])); assert proof['status']=='ok' and proof['checks']['audit_integrity'] and proof['checks']['queue_accounting'] and proof['checks']['backup_archive_verified']
            # These are the shipped operator commands on the installed production
            # stack. Failed checks must not be relabeled as an accepted release.
            healthy_triage=json.loads(cli('operations:triage',m['id']))
            assert healthy_triage['status']=='ok' and healthy_triage['version']=='1.0.0' and not healthy_triage['critical']
            posture=json.loads(cli('security:audit'))
            assert posture['status']=='ok', 'Production security posture is not ready'
            incident=json.loads(cli('operations:incident',m['id']))
            assert incident['status']=='ok' and incident['version']=='1.0.0'
            # Verify the *real on-disk* private evidence, not merely the CLI's path.
            checked=compose('exec','-T','app','php','-r',
                '$f=$argv[1]; $d=dirname($f); if(!is_file($f) || is_link($f) || (fileperms($f)&0777)!==0600 || (fileperms($d)&0777)!==0700) exit(1); $e=json_decode(file_get_contents($f),true); if(($e["status"]??null)!=="ok") exit(2); echo "verified";',
                incident['report'],capture=True)
            assert checked.strip()=='verified'
            print('PASS live security audit, triage and private incident evidence',flush=True)
            # Actual Prometheus and Blackbox scrape this exact TLS deployment, then observe stopped processes.
            with monitoring_smoke(url,metrics,tls/'fullchain.pem') as monitor:
                compose('stop','queue')
                monitor.expect('cajeerlogs_worker_healthy{role="queue"}',0,timeout=180)
                monitor.expect('ALERTS{alertname="CajeerLogsWorkerStale",role="queue",alertstate="pending"}',1,timeout=30)
                monitor.expect('cajeerlogs_ready',1)
                monitor.expect('probe_success{job="cajeerlogs-health"}',1)
                stopped=compose('exec','-T','app','php','bin/cajeer','operations:triage',capture=True,check=False)
                triage=json.loads(stopped)
                assert triage['status'] in ('degraded','critical')
                assert any(issue['runbook']=='docs/runbooks/queue-backlog.md'
                           for issue in triage['warnings'] + triage['critical']), 'No queue-outage runbook'
                print('PASS live triage routes stopped worker to queue incident runbook',flush=True)
                compose('stop','db')
                monitor.expect('cajeerlogs_ready',0,timeout=90)
                monitor.expect('probe_success{job="cajeerlogs-ready"}',0,timeout=90)
                monitor.expect('probe_success{job="cajeerlogs-health"}',1)
                http('GET','/health'); http('GET','/ready',expected=503)
                monitor.expect('ALERTS{alertname="CajeerLogsDatabaseNotReady",job="cajeerlogs-ready",alertstate="firing"}',1,timeout=170)
                print('PASS PostgreSQL stopped; real HTTPS liveness survives and readiness/metrics report outage',flush=True)
                compose('start','db')
                compose('up','-d','--wait','--wait-timeout','180','db','app','queue','scheduler','webhook')
                monitor.expect('cajeerlogs_ready',1,timeout=90)
                monitor.expect('probe_success{job="cajeerlogs-ready"}',1,timeout=90)
                monitor.expect('cajeerlogs_worker_healthy{role="queue"}',1,timeout=90)
                monitor.expect('count(ALERTS{alertname="CajeerLogsDatabaseNotReady"}) or vector(0)',0,timeout=90)
                post,_=http('POST','/api/v1/ingest',event|{'message':'recovery after database restart'},bearer|{'Idempotency-Key':'after-outage'},201)
                restored_event,_=http('GET',f"/api/v1/logs/{post['event_ids'][0]}?project_id={pid}",headers=bearer)
                assert restored_event['data']['message']=='recovery after database restart'
                assert json.loads(cli('audit:verify'))['valid']
                recovered=json.loads(cli('operations:triage',m['id']))
                assert recovered['status']=='ok' and not recovered['critical']
                assert json.loads(cli('security:audit'))['status']=='ok'
                # Complete operational evidence only after both real failures,
                # alert-resolution and post-recovery data integrity succeed.
                if archive:
                    completed=json.loads(Path(report_path).read_text())
                    completed['operations_acceptance']={
                        'version':'1.0.0',
                        'archive_sha256':hashlib.sha256(Path(archive).read_bytes()).hexdigest(),
                        'checks':{'production_security_audit':True,'healthy_triage':True,
                                  'private_incident_evidence':True,'worker_outage_triaged':True,
                                  'prometheus_worker_alert':True,'postgres_outage_alert':True,
                                  'post_recovery_triage':True,'post_recovery_ingest':True}}
                    # Atomic evidence update; a failed smoke never emits ops success.
                    fd,temporary=tempfile.mkstemp(prefix='.ops-cert-', dir=Path(report_path).parent)
                    try:
                        with os.fdopen(fd,'w') as target:
                            json.dump(completed,target,indent=2,sort_keys=True)
                            target.write('\n');target.flush();os.fsync(target.fileno())
                        os.replace(temporary,report_path)
                    finally:
                        Path(temporary).unlink(missing_ok=True)
                print('PASS PostgreSQL/queue recovery, alerts, operations readiness and actual ingestion',flush=True)


        except BaseException:
            # Read-only readiness diagnostics expose the failing check without
            # relaxing the production health gate or logging credentials.
            if compose('ps','-q','app',capture=True,check=False).strip():
                compose('exec','-T','app','php','bin/cajeer','health:ready',check=False)
                diagnostics = (r'$app=require "/app/core/bootstrap.php"; $app->boot(); '
                               r'echo json_encode(["diagnostics"=>'
                               r'$app->container()->get(\Cajeer\Logs\Observability\Diagnostics::class)->checks(),'
                               r'"security"=>\Cajeer\Logs\Security\SecurityPosture::configuration($app->config())],'
                               r'JSON_THROW_ON_ERROR),PHP_EOL;')
                compose('exec','-T','app','php','-r',diagnostics,check=False)
            compose('ps',check=False); compose('logs','--tail','80',check=False); raise
        finally:
            compose('down','-v','--remove-orphans',check=False)


if __name__=='__main__': main()
