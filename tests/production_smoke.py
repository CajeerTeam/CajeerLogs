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
        env_file.write_text(f'APP_URL={url}\nDB_PASSWORD={secrets.token_hex(32)}\nPOSTGRES_ADMIN_PASSWORD={secrets.token_hex(32)}\nPROMETHEUS_TOKEN={metrics}\nCAJEER_TLS_DIR={tls}\nCAJEER_HTTPS_BIND=127.0.0.1\nCAJEER_HTTPS_PORT={port}\n'); env_file.chmod(0o600)
        project='clsmoke'+secrets.token_hex(5)
        cmd=['docker','compose','--project-name',project,'--env-file',str(env_file),'-f',str(ROOT/'compose.production.yaml')]
        # Do not let developer environment override disposable test credentials.
        env={k:v for k,v in os.environ.items() if k not in ('APP_URL','DB_PASSWORD','POSTGRES_ADMIN_PASSWORD','PROMETHEUS_TOKEN','CAJEER_TLS_DIR','CAJEER_HTTPS_BIND','CAJEER_HTTPS_PORT','COMPOSE_PROJECT_NAME','COMPOSE_PROFILES')}
        env.update(CAJEER_ADMIN_EMAIL='release@example.test',CAJEER_ADMIN_PASSWORD=admin)
        def compose(*args, capture=False, check=True, timeout=600):
            return subprocess.run(cmd+list(args),cwd=ROOT,env=env,check=check,timeout=timeout,stdout=subprocess.PIPE if capture else None,text=True).stdout
        def cli(*args): return compose('exec','-T','app','php','bin/cajeer',*args,capture=True)
        context=ssl.create_default_context(cafile=str(tls/'fullchain.pem'))
        def http(method,path,body=None,headers=None,expected=200):
            raw=None if body is None else json.dumps(body,separators=(',',':')).encode()
            hs={'Accept':'application/json'}; hs.update(headers or {})
            if raw is not None: hs['Content-Type']='application/json'
            req=urllib.request.Request(url+path,data=raw,headers=hs,method=method)
            try: resp=urllib.request.urlopen(req,context=context,timeout=20)
            except urllib.error.HTTPError as err: resp=err
            with resp:
                status=resp.code; data=resp.read(); h=resp.headers
            # Don't log response bodies that might include credentials.
            if status!=expected: raise AssertionError(f'{method} {path}: expected {expected}, got {status}')
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
                    data,_=http('GET','/ready'); assert data['version']=='0.1.6' and data['status']=='ok'; break
                except (OSError, AssertionError, urllib.error.URLError): time.sleep(1)
            else: raise AssertionError('HTTPS readiness did not become healthy')
            panel,h=http('GET','/admin/'); assert b'0.1.6' in panel and 'frame-ancestors' in h['Content-Security-Policy']
            assert len(http('GET','/admin/assets/panel.js')[0])>1000
            http('GET','/.env',expected=404)
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
            recovery=d['recovery_codes'][0]
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
            d,_=http('POST','/api/v1/ingest/async',event|{'message':'release async error'},bearer|{'Idempotency-Key':'release-async'},202); job=d['job_id']
            for _ in range(60):
                d,_=http('GET',f'/api/v1/ingest/jobs/{job}',headers=bearer)
                if d['status']=='completed': break
                assert d['status'] in ('pending','running'); time.sleep(1)
            else: raise AssertionError('Queue did not complete real job')
            query=urllib.parse.urlencode({'project_id':pid,'q':'release','level':'error'})
            d,_=http('GET','/api/v1/logs?'+query,headers=bearer); assert len(d['items'])==2
            d,_=http('GET',f'/api/v1/logs/{eventid}?project_id={pid}',headers=bearer); assert d['data']['context']['password']=='[redacted]'
            csv,_=http('GET','/api/v1/logs/export?'+query+'&format=csv',headers=bearer); assert eventid.encode() in csv
            print('PASS real TOTP, recovery, token, synchronous and async ingest, worker, search and export',flush=True)
            m=json.loads(cli('backup:create')); assert m['bytes']>0
            assert json.loads(cli('backup:verify',m['id']))['sha256']==m['sha256']
            compose('exec','-T','db','psql','-U','postgres','-d','postgres','-v','ON_ERROR_STOP=1','-c','CREATE DATABASE cajeer_restore_smoke OWNER cajeer_logs')
            restored=json.loads(cli('backup:restore',m['id'],'cajeer_restore_smoke')); assert restored['api_tokens_revoked']
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
        except BaseException:
            compose('ps',check=False); compose('logs','--tail','80',check=False); raise
        finally:
            compose('down','-v','--remove-orphans',check=False)


if __name__=='__main__': main()
