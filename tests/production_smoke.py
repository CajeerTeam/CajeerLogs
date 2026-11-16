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
from pipeline_scenario import exercise
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
        env={k:v for k,v in os.environ.items() if k not in ('APP_URL','DB_PASSWORD','POSTGRES_ADMIN_PASSWORD','PROMETHEUS_TOKEN','CAJEER_TLS_DIR','CAJEER_HTTPS_BIND','CAJEER_HTTPS_PORT','COMPOSE_PROJECT_NAME','COMPOSE_PROFILES','SECURITY_SESSION_IDLE_SECONDS','SECURITY_REAUTH_SECONDS','SECURITY_2FA_REQUIRED_ROLES','SECURITY_2FA_REQUIRED_PERMISSIONS','API_RATE_EXPORT_PER_MINUTE','API_RATE_BURST_PERCENT','QUEUE_WORKER_PROCESSES','QUEUE_WORKER_MAX_JOBS','QUEUE_WORKER_MAX_SECONDS','QUEUE_WORKER_MEMORY_MB','QUEUE_POLL_MIN_MS','QUEUE_POLL_MAX_MS','QUEUE_LEASE_SECONDS','QUEUE_CLAIM_TIMEOUT_MS','QUEUE_PROCESS_TIMEOUT_SECONDS','QUEUE_SHUTDOWN_GRACE_SECONDS','CAJEER_QUEUE_STOP_GRACE')}
        env.update(CAJEER_ADMIN_EMAIL='release@example.test',CAJEER_ADMIN_PASSWORD=admin)
        def compose(*args, capture=False, check=True, timeout=600):
            return subprocess.run(cmd+list(args),cwd=ROOT,env=env,check=check,timeout=timeout,stdout=subprocess.PIPE if capture else None,text=True).stdout
        def cli(*args): return compose('exec','-T','app','php','bin/cajeer',*args,capture=True)
        context=ssl.create_default_context(cafile=str(tls/'fullchain.pem'))
        def http(method,path,body=None,headers=None,expected=200):
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
            return (json.loads(data) if h.get('Content-Type','').startswith('application/json') and data else data),h
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
                    data,_=http('GET','/ready'); assert data['version']=='0.5.4' and data['status']=='ok'; break
                except (OSError, AssertionError, urllib.error.URLError): time.sleep(1)
            else: raise AssertionError('HTTPS readiness did not become healthy')
            panel,h=http('GET','/admin/'); assert b'0.5.4' in panel and 'frame-ancestors' in h['Content-Security-Policy']
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
            compose('exec','-T','db','psql','-U','postgres','-d','cajeer_logs','-v','ON_ERROR_STOP=1','-c',
                "UPDATE auth_sessions SET reauthenticated_at=now()-interval '10 minutes' WHERE user_id=(SELECT id FROM users WHERE email='release@example.test')")
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
            compose('exec','-T','db','psql','-U','postgres','-d','postgres','-v','ON_ERROR_STOP=1','-c','CREATE DATABASE cajeer_restore_smoke OWNER cajeer_logs')
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
            bench_v2=subprocess.check_output(['python3',str(ROOT/'scripts/benchmark.py'),'--url',url,'--project',integrated['project_id'],'--source','app','--requests','12','--batch','5','--concurrency','4','--ca-file',str(tls/'fullchain.pem'),'--verify-explorer','--confirm'],env=dict(env,CAJEER_TOKEN=integrated['token']['token'],CAJEER_SIGNING_KEY_ID=signing['current_key_id'],CAJEER_SIGNING_SECRET=signing['signing_secret']),text=True,timeout=150)
            measured_v2=json.loads(bench_v2); assert measured_v2['events_verified']==44 and measured_v2['errors']==0 and measured_v2['explorer_verified'] and measured_v2['signing_version']==2
            print(bench_v2,flush=True)
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
            compose('exec','-T','db','psql','-U','postgres','-d','cajeer_logs','-v','ON_ERROR_STOP=1','-c',
                f"UPDATE log_events SET received_at=now()-interval '2 days' WHERE id='{eventid}'")
            assert json.loads(cli('retention:preview','10',pid))['candidates']==1
            assert json.loads(cli('retention:run','10'))['removed']==1
            http('GET',f'/api/v1/logs/{eventid}?project_id={pid}',headers=bearer,expected=404)
            compose('up','-d','--wait','--wait-timeout','180','scheduler')
            proof=json.loads(cli('operations:check',m['id'])); assert proof['status']=='ok' and proof['checks']['audit_integrity'] and proof['checks']['queue_accounting'] and proof['checks']['backup_archive_verified']
            print('PASS real export rate limit, bounded retention and operator acceptance check',flush=True)
            # Actual Prometheus and Blackbox scrape this exact TLS deployment, then observe stopped processes.
            with monitoring_smoke(url,metrics,tls/'fullchain.pem') as monitor:
                compose('stop','queue')
                monitor.expect('cajeerlogs_worker_healthy{role="queue"}',0,timeout=180)
                monitor.expect('ALERTS{alertname="CajeerLogsWorkerStale",role="queue",alertstate="pending"}',1,timeout=30)
                monitor.expect('cajeerlogs_ready',1)
                monitor.expect('probe_success{job="cajeerlogs-health"}',1)
                print('PASS queue stopped; actual Prometheus detects stale heartbeat without API outage',flush=True)
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
                print('PASS PostgreSQL and queue recovery, alert resolution, real ingestion and audit after outage',flush=True)


        except BaseException:
            compose('ps',check=False); compose('logs','--tail','80',check=False); raise
        finally:
            compose('down','-v','--remove-orphans',check=False)


if __name__=='__main__': main()
