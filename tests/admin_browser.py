"""Chromium UI tests: real PHP delivery, plus a deterministic API fixture for UI interactions.

Run: python -m pip install playwright; python -m playwright install chromium
     python tests/admin_browser.py
Set CHROMIUM_PATH to use an installed system browser. Fixture responses are NOT DB tests.
"""
import contextlib
import datetime
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import time
from urllib.parse import parse_qs, urlsplit
from urllib.request import urlopen, Request
from urllib.error import HTTPError
from types import SimpleNamespace
import re
import hashlib
import hmac
import uuid
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
P1 = '11111111-1111-4111-8111-111111111111'
P2 = '22222222-2222-4222-8222-222222222222'
S1 = '33333333-3333-4333-8333-333333333333'
U1 = '44444444-4444-4444-8444-444444444444'
U2 = '55555555-5555-4555-8555-555555555555'
E1 = '66666666-6666-4666-8666-666666666666'
E2 = '77777777-7777-4777-8777-777777777777'
T1 = '88888888-8888-4888-8888-888888888888'
TOKEN = 'cl_' + 'a'*64
CSRF = 'c'*64

@contextlib.contextmanager
def server(**overrides):
    with socket.socket() as s:
        s.bind(('127.0.0.1', 0)); port = s.getsockname()[1]
    url = f'http://127.0.0.1:{port}'
    env = dict(os.environ, APP_ENV='test', APP_DEBUG='false', APP_URL=url, API_PREFIX='/api/v1'); env.update(overrides)
    with open(os.devnull, 'w') as log:
        p = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', 'public', 'public/router.php'], cwd=ROOT, env=env, stdout=log, stderr=log)
        try:
            for _ in range(100):
                if p.poll() is not None: raise RuntimeError('PHP server stopped')
                try:
                    with socket.create_connection(('127.0.0.1', port), .2): break
                except OSError: time.sleep(.05)
            else: raise RuntimeError('PHP server did not start')
            yield url
        finally:
            p.terminate(); p.wait(timeout=5)


def browser_for(p):
    engine=os.environ.get('CAJEER_BROWSER_ENGINE','chromium')
    if engine not in ('chromium','firefox','webkit'): raise ValueError('Unknown browser engine')
    if engine!='chromium': return getattr(p,engine).launch(headless=True)
    path=os.environ.get('CHROMIUM_PATH') or (shutil.which('chromium') if 'CAJEER_BROWSER_ENGINE' not in os.environ else None)
    return p.chromium.launch(executable_path=path, headless=True, args=['--no-sandbox'])


def nav(page, route):
    page.locator('#nav a[href="#'+route+'"]').click()
    expect(page.locator('#nav a[href="#'+route+'"]')).to_have_attribute('aria-current', 'page')


def login(page, email='admin@example.test', password='admin-password-12345'):
    page.get_by_label('Email', exact=True).fill(email)
    page.get_by_label('Пароль', exact=True).fill(password)
    page.get_by_role('button', name='Войти', exact=True).click()


class Fixture:
    def __init__(self):
        self.reauth=False; self.fresh_until=None; self.rbac_roles=[]; self.custom_permissions=None; self.system_report_fails=False; self.user = None; self.project_roles={}; self.denied_projects=set(); self.totp_enabled=False; self.require_totp=False; self.role = 'administrator'; self.projects=[{'id':P1,'name':'Production','slug':'production','revision':'1','status':'active','description':'','metadata':{},'max_body_bytes':1048576,'max_batch_events':100,'created_at':'2026-01-01T00:00:00Z'}]
        self.sources=[{'id':S1,'project_id':P1,'name':'Application','slug':'app','created_at':'2026-01-01T00:00:00Z','status':'active','revision':'1','source_type':'application','metadata':{}}]
        self.users=[{'id':U1,'email':'admin@example.test','role':'administrator','blocked_at':None}]
        self.sessions=[{'id':U1,'current':True,'user_agent':'Current browser','remote_address':'127.0.0.1','created_at':'2026-01-01T00:00:00Z','last_seen_at':'2026-01-01T00:00:00Z','expires_at':'2026-01-02T00:00:00Z'},{'id':U2,'current':False,'user_agent':'Other device','remote_address':'192.0.2.3','created_at':'2026-01-01T00:00:00Z','last_seen_at':'2026-01-01T00:00:00Z','expires_at':'2026-01-02T00:00:00Z'}]; self.members=[]; self.tokens=[]; self.accounts=[]; self.calls=[]; self.fail_next=False; self.change_export=False; self.head_changed=False; self.multi_logs=False; self.redaction={}; self.views=[]; self.connection_credentials={}; self.connection_event=None; self.connection_attempts=[]
        self.dead_jobs=[{'id':j,'project_id':P1,'status':'dead','attempts':2,'max_attempts':8,'last_error':reason,'payload_events':1,'payload_bytes':123,'created_at':'2026-01-01T00:00:00Z','available_at':'2026-01-01T00:00:00Z','completed_at':'2026-01-01T00:00:01Z'} for j,reason in [('9223372036854775806','database_error'),('42','lease_expired')]]
        self.event={'id':E1,'project_id':P1,'source_id':S1,'source':'app','service':'api','host':'host-1','level':'error','message':'<img src=x onerror="window.pwned=1"> database error','context':{'secret':'[redacted]','html':'<script>window.pwned=1</script>','nested':{'items':[{'code':'needle','big':12345678901234567890}]}},'trace_id':'trace-1','request_id':'req-1','tags':['prod'],'timestamp':'2026-11-14T00:00:00Z','received_at':'2026-11-14T00:00:01Z'}
    def route(self,r):
        req=r.request; path=urlsplit(req.url).path.removeprefix('/api/v1'); method=req.method; q={k:v[0] for k,v in parse_qs(urlsplit(req.url).query).items()}; body=req.post_data_json if req.post_data else None
        self.calls.append((method,path,q,body))
        def send(data=None,status=200,headers=None):
            if status==204: r.fulfill(status=status,headers=headers or {});return
            r.fulfill(status=status,content_type='application/json',body=json.dumps(data),headers=headers or {})
        def err(status,code): send({'error':code,'request_id':'test'},status)
        def listing(items,next_cursor=None): send({'items':items,'next_cursor':next_cursor})
        if path=='/auth/login':
            if body['password']=='bad': return err(401,'unauthorized')
            if self.totp_enabled and not body.get('otp'): return err(401,'second_factor_required')
            if self.totp_enabled and body.get('otp')!='123456': return err(401,'unauthorized')
            self.user={'id':U1 if self.role=='administrator' else U2,'email':body['email'],'role':self.role,'two_factor_enabled':self.totp_enabled}
            if self.custom_permissions is not None:self.user['permissions']=self.custom_permissions
            return send({'user':self.user,'csrf_token':CSRF,'expires_in':28800,'enrollment_required':self.require_totp and not self.totp_enabled},headers={'Set-Cookie':'cajeerlogs_session='+'b'*64+'; Path=/; HttpOnly; SameSite=Strict'})
        if path=='/roles':
            if self.role!='administrator':return err(403,'forbidden')
            permissions=['projects:read','logs:read','logs:export','logs:write','sources:create','tokens:read','tokens:create','audit:read']
            if method=='GET':return send({'items':self.rbac_roles,'permissions':permissions,'builtins':{'administrator':permissions+['roles:manage'],'operator':permissions,'viewer':permissions[:3]}})
            row=dict(body,id=str(uuid.uuid4()),revision=1);self.rbac_roles.append(row);return send(row,201)
        if path.startswith('/roles/'):
            row=next(x for x in self.rbac_roles if x['id']==path.split('/')[-1])
            if body['revision']!=row['revision']:return err(409,'role_changed')
            if method=='DELETE':self.rbac_roles.remove(row);return send(status=204)
            row.update(name=body['name'],permissions=body['permissions'],revision=row['revision']+1);return send(row)
        if path=='/health': return send({'status':'ok','version':'0.9.2'})
        if path=='/auth/token':
            credential=self.connection_credentials.get(req.headers.get('authorization','').removeprefix('Bearer '))
            return send({'token':credential['row']}) if credential else err(401,'unauthorized')
        if path=='/ingest':
            assert 'x-csrf-token' not in req.headers and 'cookie' not in req.headers
            token=req.headers.get('authorization','').removeprefix('Bearer '); credential=self.connection_credentials[token]; row=credential['row']
            source=next(x for x in self.sources if x['id']==row['source_id']); assert source['slug']==body['source']
            key=req.headers['idempotency-key']; nonce=req.headers['x-log-nonce']; timestamp=req.headers['x-log-timestamp']; kid=req.headers.get('x-log-key-id'); assert kid==credential.get('kid')
            parts=['cajeerlogs-ingest-v2',kid] if kid else ['cajeerlogs-ingest-v1']; parts+=['POST','/api/v1/ingest',timestamp,nonce,key,hashlib.sha256(req.post_data.encode()).hexdigest()]
            assert hmac.compare_digest(req.headers['x-log-signature'],hmac.new(credential.get('secret',token).encode(),'\n'.join(parts).encode(),hashlib.sha256).hexdigest())
            self.connection_attempts.append((key,nonce,req.post_data))
            if self.connection_event is None:
                self.connection_event=dict(body,id=str(uuid.uuid4()),project_id=row['project_id'],source_id=row['source_id'],received_at=body['timestamp'],context={},tags=[])
                # Simulate a response lost after commit; retry must reuse the key and body.
                return err(503,'service_unavailable')
            assert self.connection_attempts[-2][0]==key and self.connection_attempts[-2][1]!=nonce and self.connection_attempts[-2][2]==req.post_data
            return send({'accepted':True,'received':1,'stored':0,'rejected':0,'event_ids':[self.connection_event['id']],'replayed':True},200)
        if self.user is None: return err(401,'unauthorized')
        if method!='GET': assert req.headers.get('x-csrf-token')==CSRF, (method,path,req.headers)
        if path=='/auth/me': return send({'user':self.user,'csrf_token':CSRF,'enrollment_required':self.require_totp and not self.totp_enabled,**({'security':{'totp_required':self.require_totp,'idle_seconds':120,'reauth_seconds':300,'reauthenticated_until':self.fresh_until}} if self.reauth else {})})
        if path=='/auth/reauthenticate':
            if body.get('password')!='admin-password-12345' or (self.totp_enabled and body.get('otp')!='123456'): return err(403,'invalid_otp')
            self.fresh_until='2026-11-16T00:00:00Z';return send({'security':{'totp_required':self.require_totp,'idle_seconds':120,'reauth_seconds':300,'reauthenticated_until':self.fresh_until}})
        if path=='/auth/profile' and method=='PATCH':
            self.user['display_name']=body['display_name']; return send({'user':self.user})
        if path=='/auth/sessions': return listing(self.sessions)
        if path.startswith('/auth/sessions/') and method=='DELETE':
            sid=path.split('/')[-1]; self.sessions=[x for x in self.sessions if x['id']!=sid]; return send(status=204)
        if path=='/auth/logout-others':
            self.sessions=[x for x in self.sessions if x['current']]; return send(status=204)
        if path=='/auth/2fa/recovery-codes': return send({'recovery_codes':['d'*32,'e'*32]})
        if path=='/auth/2fa/setup':
            assert body['password']=='admin-password-12345'; return send({'secret':'JBSWY3DPEHPK3PXP','otpauth_uri':'otpauth://totp/CajeerLogs%3Aadmin%40example.test?secret=JBSWY3DPEHPK3PXP&issuer=CajeerLogs&algorithm=SHA1&digits=6&period=30','expires_in':600})
        if path=='/auth/2fa/enable':
            assert body['otp']=='123456'; self.totp_enabled=True; self.user['two_factor_enabled']=True
            return send({'user':self.user,'csrf_token':CSRF,'expires_in':28800,'enrollment_required':False,'recovery_codes':['a'*32,'b'*32]})
        if path=='/auth/2fa/disable':
            assert body['otp']=='123456' and body['password']=='admin-password-12345'; self.totp_enabled=False; self.user=None; return send(status=204)
        if path in ['/auth/logout','/auth/logout-all','/auth/password']:
            self.user=None;return send(status=204,headers={'Set-Cookie':'cajeerlogs_session=; Path=/; Max-Age=0'})
        if '/redaction' in path:
            if self.role!='administrator': return err(403,'forbidden')
            base=path.removesuffix('/preview'); policy=self.redaction.get(base,{'revision':0,'rules':[]})
            if path.endswith('/preview'):
                sample=body['event'].copy(); sample['context']=sample.get('context',{}).copy()
                assert body['rules']==[{'type':'path','value':'/context/customer_id'}]
                sample['context']['customer_id']='[redacted]'; return send({'event':sample})
            if method=='PUT':
                assert body['revision']==policy['revision']; policy={'revision':policy['revision']+1,'rules':body['rules']}; self.redaction[base]=policy
            return send(policy)
        if '/queue/' in path:
            pid=path.split('/')[2]
            if not self.user or self.role=='viewer' or self.project_roles.get(pid)=='viewer':return err(403,'forbidden')
            jobs=[j for j in self.dead_jobs if j['project_id']==pid]
            if '/queue/jobs/' in path:
                row=next((j for j in jobs if j['id']==path.split('/')[-1]),None)
                if row is None:return err(404,'not_found')
                return send({'job':row,'attempts':[{'attempt':2,'started_at':row['created_at'],'finished_at':row['completed_at'],'outcome':'dead','error':row['last_error']}]})
            if method=='POST':
                assert req.headers.get('x-csrf-token')==CSRF and body['confirm'] is True
                chosen=[j for j in jobs if any(x['id']==j['id'] and x['attempts']==j['attempts'] for x in body['items']) and j['status']=='dead']
                if len(chosen)!=len(body['items']):return err(409,'conflict')
                status='pending' if path.endswith('/retry') else 'cancelled'
                for j in chosen:j['status']=status
                return send({'changed':len(chosen),'items':[{'id':j['id'],'status':status} for j in chosen]})
            rows=[j for j in jobs if j['status']=='dead' and (not q.get('reason') or j['last_error']==q['reason']) and (not q.get('cursor') or int(j['id'])<int(q['cursor']))]
            limit=int(q.get('limit',50));return listing(rows[:limit],rows[limit-1]['id'] if len(rows)>limit else None)
        if path=='/projects':
            if method=='POST': self.projects.append({'id':P2,'revision':'1','status':'active','description':'','metadata':{},'max_body_bytes':1048576,'max_batch_events':100,**body});return send({'id':P2},201)
            return listing([dict(p,effective_role=self.project_roles.get(p['id'],self.role)) for p in self.projects if p['id'] not in self.denied_projects and (q.get('status','all')=='all' or p['status']==q['status']) and q.get('q','').lower() in (p['name']+' '+p['slug']+' '+p['id']).lower()])
        if path in ['/projects/'+P1,'/projects/'+P2]:
            project=next(p for p in self.projects if p['id']==path.split('/')[-1])
            if project['id'] in self.denied_projects:return err(403,'forbidden')
            if method=='PATCH':
                assert body['revision']==project['revision']; project.update({k:v for k,v in body.items() if k!='revision'}); project['revision']=str(int(project['revision'])+1)
            return send(dict(project,effective_role=self.project_roles.get(project['id'],self.role)))
        if '/saved-views' in path:
            parts=path.split('/'); pid=parts[2]; vid=parts[4] if len(parts)>4 else None
            if method=='POST':
                row={'id':'88888888-8888-4888-8888-888888888888','revision':1,'project_id':pid,**body};self.views.append(row);return send(row,201)
            if vid is None: return listing([v for v in self.views if v['project_id']==pid])
            row=next((v for v in self.views if v['id']==vid and v['project_id']==pid),None)
            if row is None:return err(404,'not_found')
            if method=='PATCH':
                if body['revision']!=row['revision']:return err(409,'conflict')
                row.update(body);row['revision']+=1;return send(row)
            if method=='DELETE':self.views.remove(row);return send(status=204)
            return send(row)
        if '/sources/' in path:
            parts=path.split('/'); source=next(x for x in self.sources if x['id']==parts[4] and x['project_id']==parts[2]); action=parts[5] if len(parts)>5 else None
            if action=='stats': return send({'events':2,'events_24h':2,'events_60s':2,'active_tokens':1,'accepted_events_total':'2','rejected_events_total':'0','last_seen_at':None,'last_received_at':None})
            if method=='PATCH':
                assert body['revision']==source['revision'];source.update({k:v for k,v in body.items() if k!='revision'});source['revision']=str(int(source['revision'])+1)
            if action in ['disable','enable','archive','restore']:
                assert body['revision']==source['revision'];source['status']={'disable':'disabled','enable':'active','archive':'archived','restore':'disabled'}[action];source['revision']=str(int(source['revision'])+1)
            return send(source)
        if path.startswith('/projects/') and path.endswith(('/archive','/restore')):
            project=next(p for p in self.projects if p['id']==path.split('/')[2]); assert body['revision']==project['revision']; project['status']='archived' if path.endswith('/archive') else 'active'; project['revision']=str(int(project['revision'])+1); return send(project)
        if path.startswith('/projects/') and path.endswith('/stats'):
            return send({'sources':len(self.sources),'members':len(self.members),'active_tokens':len(self.tokens),'events':2,'events_24h':2,'queued_jobs':0,'dead_jobs':0,'last_received_at':None})
        if path.endswith('/sources'):
            if method=='POST': self.sources.append({'id':str(uuid.uuid4()),'project_id':path.split('/')[2],'status':'active','revision':'1','metadata':{},**body});return send({'id':self.sources[-1]['id']},201)
            return listing([s for s in self.sources if s['project_id']==path.split('/')[2] and (q.get('status','all')=='all' or s['status']==q['status']) and q.get('q','').lower() in (s['name']+' '+s['slug']+' '+s['id']).lower() and (q.get('source_type','all')=='all' or s['source_type']==q['source_type'])])
        if path.endswith('/members'):
            return listing(self.members)
        if '/members/' in path:
            if method=='PUT': self.members=[dict(self.users[-1],project_role=(body or {}).get('role'))]
            else: self.members=[]
            return send(status=204)
        if path=='/users':
            if method=='POST': self.users.append({'id':U2,'email':body['email'],'role':body['role'],'blocked_at':None});return send({'id':U2},201)
            return listing([u for u in self.users if q.get('q','').lower() in (u['email']+' '+u['id']).lower() and (q.get('role','all')=='all' or u['role']==q['role'])])
        if path=='/users/'+U2+'/projects':
            return listing([dict(self.projects[0],member=True,project_role=self.members[0].get('project_role'),effective_role='viewer') for _ in self.members])
        if path=='/users/'+U2+'/audit':
            return listing([{'id':U2,'action':'user.created','actor_id':U1,'target':U2,'created_at':'2026-01-01T00:00:00Z','request_id':'test-request'}])
        if path=='/users/'+U2+'/2fa/reset':
            self.users[-1]['two_factor_enabled']=False;return send(status=204)
        if path=='/users/'+U2:
            if method=='GET': return send({'user':self.users[-1]})
            self.users[-1].update(email=body['email'],role=body['role'],blocked_at='2026-01-01' if body['blocked'] else None);return send({'id':U2})
        if path.endswith('/security/audit'):
            rows=[{'id':E1,'action':'token.created','actor_id':U1,'target':T1,'created_at':'2026-11-15T00:00:00Z','request_id':'safe-audit-id','project_id':P1,'result':'success','actor_type':'user','chain_position':1,'event_hash':'a'*64}]
            rows=[row for row in rows if all(not q.get(k) or row[k]==q[k] for k in ['action','result','actor_type'])]
            if q.get('format')=='ndjson': return r.fulfill(status=200,content_type='application/x-ndjson',body=''.join(json.dumps(row)+'\n' for row in rows))
            return listing(rows)
        if '/service-accounts/' in path and method=='GET':
            a=next(x for x in self.accounts if x['id']==path.split('/')[-1]);ts=[x for x in self.tokens if x.get('service_account_id')==a['id']];return send(dict(a,total_tokens=len(ts),active_tokens=len([x for x in ts if not x.get('revoked_at')]),last_used_at=None))
        if path.endswith('/service-accounts'):
            if method=='POST':
                row={'id':'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','name':body['name'],'project_id':P1,'disabled_at':None}; self.accounts.append(row); return send(row,201)
            return listing([a for a in self.accounts if not q.get('q') or q['q'].lower() in a['name'].lower()])
        if '/service-accounts/' in path and path.endswith('/disable'):
            self.accounts[0]['disabled_at']='2026-01-01'; selected=[row for row in self.tokens if row.get('service_account_id')==self.accounts[0]['id'] and not row.get('revoked_at')];
            for row in selected: row['revoked_at']='2026-01-01'
            return send(dict(self.accounts[0],revoked=len(selected)))
        if path.endswith('/tokens'):
            if method=='POST' and body.get('signing_mode'):
                assert body['scopes']==['logs:write'] and body['source_id']
                pid=path.split('/')[2]; row=dict(body,id=str(uuid.uuid4()),project_id=pid,revoked_at=None,expires_at='2099-01-01T00:00:00Z',revision='1'); token='cl_'+'d'*64
                credential={'row':row}; result={'id':row['id'],'token':token}
                if body['signing_mode']=='separate':
                    kid=str(uuid.uuid4()); secret='sk_'+'e'*64;credential.update(kid=kid,secret=secret);result['signing']={'current_key_id':kid,'signing_secret':secret}
                self.connection_credentials[token]=credential;self.tokens.append(row);return send(result,201)
            if method=='POST': self.tokens.append({'id':T1,'name':body['name'],'scopes':body['scopes'],'source_id':body.get('source_id'),'expires_at':'2099-01-01T00:00:00Z','revoked_at':None,'revision':'1','description':body.get('description',''),'requests_per_minute':body.get('requests_per_minute'),'service_account_id':body.get('service_account_id')});return send({'id':T1,'token':TOKEN},201)
            return listing([t for t in self.tokens if (not q.get('q') or q['q'].lower() in t['name'].lower()) and (not q.get('scope') or q['scope'] in t['scopes']) and (not q.get('service_account_id') or t.get('service_account_id')==q['service_account_id'])] if path.startswith('/projects/') else [])
        signing_match=re.search(r'/tokens/([^/]+)/signing(/previous)?$',path)
        if signing_match:
            token_id=signing_match.group(1); credential=next(c for c in self.connection_credentials.values() if c['row']['id']==token_id); meta=credential.setdefault('meta',{'current_key_id':credential['kid'],'previous_key_id':None,'previous_until':None,'required':True})
            if method=='GET': return send(meta)
            if method=='POST':
                assert body['expected_key_id']==meta['current_key_id'];old=meta['current_key_id'];meta.update(current_key_id=str(uuid.uuid4()),previous_key_id=old,previous_until='2099-01-01T00:00:00Z');return send(dict(meta,signing_secret='sk_'+'f'*64))
            if method=='DELETE' and signing_match.group(2):
                assert body=={'expected_key_id':meta['current_key_id'],'expected_previous_key_id':meta['previous_key_id']};meta.update(previous_key_id=None,previous_until=None);return send(meta)
        if path.endswith('/tokens/'+T1+'/rotate'):
            old=self.tokens[0]; old['rotated_to_id']='99999999-9999-4999-8999-999999999999'; old['revision']=str(int(old['revision'])+1); old['expires_at']='2099-01-01T00:00:00Z'; new=dict(old,id=old['rotated_to_id'],scopes=body['scopes'],revision='1',rotated_to_id=None); self.tokens.append(new); return send(dict(new,token='cl_'+'b'*64,previous=old),201)
        if path.endswith('/tokens/'+T1):
            if method=='GET': return send(dict(self.tokens[0],predecessor_id=None,signing=None))
            if method=='PATCH':
                row=self.tokens[0]; row.update({k:v for k,v in body.items() if k!='revision'}); row['revision']=str(int(row['revision'])+1); return send(row)
            self.tokens[0]['revoked_at']='2026-01-01';return send(status=204)
        if path.endswith('/tokens/revoke-all'):
            for row in self.tokens: row['revoked_at']='2026-01-01'
            return send({'revoked':len(self.tokens)})
        if path=='/logs/stats':
            interval=q.get('interval','hour'); width={'minute':60,'5min':300,'hour':3600,'day':86400}[interval]
            start=datetime.datetime.fromisoformat(q['from'].replace('Z','+00:00')).timestamp()
            bucket=datetime.datetime.fromtimestamp(int(start//width)*width,datetime.timezone.utc).isoformat().replace('+00:00','Z')
            return send({'total':2,'by_level':{'error':2,'warning':0},'by_source':[{'source':'app','source_id':S1,'count':2}],'source_count':1,'by_service':[{'service':'api','count':2}],'by_host':[{'host':'host-1','count':2}],'buckets':[{'timestamp':bucket,'count':2,'errors':2}],'level_buckets':[{'timestamp':bucket,'level':'error','count':2}],'interval':interval,'sources_truncated':False,'from':q.get('from'),'to':q.get('to')})
        if path=='/logs':
            if self.fail_next: self.fail_next=False;return err(503,'service_unavailable')
            assert q['project_id'] in [P1,P2]
            if self.connection_event and q.get('request_id')==self.connection_event['request_id']:
                return send({'items':[self.connection_event],'next_cursor':None,'prev_cursor':None,'page_cursor':'page-one','page_digest':'a'*64,'consistency':'live'})
            second=q.get('cursor') in ('opaque-cursor','page-two'); cursor='page-two' if second else 'page-one';
            return send({'items':[{**self.event,'id':E2,'message':'second page event'}] if second else ([self.event,{**self.event,'id':E2,'message':'other event'}] if self.multi_logs else [self.event]), 'next_cursor':None if second else 'opaque-cursor','prev_cursor':'page-one' if second else None,'page_cursor':cursor,'page_digest':'b'*64 if second else ('c'*64 if self.head_changed else 'a'*64),'consistency':q.get('consistency','live'),'snapshot_at':'2026-11-14T00:00:01Z' if q.get('consistency')=='snapshot' else None,'cursor_expires_at':'2026-11-14T01:00:01Z'})
        if path=='/logs/export':
            assert q.get('project_id') in [P1,P2]
            if q['format']=='csv':
                if self.change_export: return err(412,'page_changed')
                assert q.get('cursor')=='page-one'; assert req.headers.get('if-match')=='"'+'a'*64+'"'
                return r.fulfill(status=200,content_type='text/csv',headers={'X-Page-Digest':'a'*64},body='id,message\n'+E1+',database error\n')
            return send({'items':[self.event],'next_cursor':None})
        if self.connection_event and path=='/logs/'+self.connection_event['id']:
            assert q.get('project_id')==self.connection_event['project_id'] and q.get('source_id')==self.connection_event['source_id'];return send({'data':self.connection_event})
        if path.startswith('/logs/'): return send({'data':{**self.event,'id':E2,'message':'other event'} if path.endswith(E2) else self.event})
        if path=='/system/report' and self.system_report_fails: return err(500,'internal_error')
        if path=='/system/report': return send({'status':'ok','environment':'test','php':'8.4','database':{'status':'ok'},'operations':{'database':{'queue_active_jobs':2,'queue_max_jobs':1000,'queue_max_events':100000,'queue_max_bytes':536870912,'queue_admission_paused':1,'queue_high_watermark_percent':85,'queue_low_watermark_percent':60,'queue_rejected_jobs':1,'queue_rejected_events':2,'queue_rejected_bytes':3,'queue_rejected_paused':4,'queue_active_events':3,'queue_active_bytes':456}},'queue_retry_policy':{'max_attempts':4,'base_seconds':5,'max_seconds':60,'jitter_percent':25,'outage_max_failures':10},'queue_workers':{'available':True,'active':1,'truncated':False,'workers':[{'id':'worker-proof','pid':123,'processed':7,'last_success':1767243600,'status':'ok'}]},'workers':{'queue':{'last_success_timestamp':1767243600,'status':'ok'},'scheduler':{'last_success_timestamp':0,'status':'never'},'webhook':{'last_success_timestamp':1767243600,'status':'stale'},'backup':{'last_success_timestamp':0,'status':'never'}},'retention':{'batch_size':1000,'timeout_ms':5000,'history':[{'finished_at':'2026-01-01T05:00:00Z','status':'success','removed':42,'backlog_lower_bound':3,'total_removed':42,'duration_ms':12}]},'backup':{'last_success_at':'2026-01-01T03:00:00+00:00','last_offsite_verified':True,'replication_pending':False,'last_restore_at':'2026-01-01T04:00:00+00:00','last_restore_seconds':12.5},'deployment_security':{'https_configured':True,'trusted_proxy_count':1,'production_host_validation':True,'database_tls_required':False,'database_sslmode':'disable'},'rate_limits':{'global':{'per_minute':600,'burst':150},'search':{'per_minute':120,'burst':30}}})
        raise AssertionError('Unexpected fixture request: '+path)


def http(url, method='GET', body=None, headers=None):
    try:
        with urlopen(Request(url, data=body, headers=headers or {}, method=method), timeout=5) as r:
            return r.status, dict(r.headers), r.read()
    except HTTPError as e:
        return e.code, dict(e.headers), e.read()


def offline_adapter(fixture, url):
    # Browser still executes the shipped JS and DOM APIs, but no navigation or network
    # is attempted. Only the fetch transport and document origin are injected for tests.
    def fetch(target, options):
        if urlsplit(target).path=='/ready': return {'status':503,'body':'{"status":"down"}','headers':{'Content-Type':'application/json'}}
        path=urlsplit(target).path
        if not path.startswith('/api/v1/'): raise AssertionError('Unexpected URL '+target)
        data=options.get('body'); req=SimpleNamespace(url=target,method=options.get('method','GET'),headers={k.lower():v for k,v in options.get('headers',{}).items()},post_data=data,post_data_json=json.loads(data) if data else None)
        response={}
        def fulfill(status=200,body=None,content_type=None,headers=None):
            response.update(status=status,body=body,headers={**(headers or {}),**({'Content-Type':content_type} if content_type else {})})
        fixture.route(SimpleNamespace(request=req,fulfill=fulfill)); return response
    return fetch


def offline_load(page, url, fixture, route=''):
    # Chromium can run the actual module graph without network access. Only import
    # specifiers and browser transport are adapted; production module bodies are unchanged.
    page.goto('about:blank')
    html=http(url+'/admin/')[2].decode()
    html=re.sub(r'<script[^>]*src="[^"]*"[^>]*></script>', '', html)
    html=re.sub(r'<link[^>]*rel="stylesheet"[^>]*>', '', html)
    page.set_content(html)
    root=ROOT/'public/admin/assets'
    css='\n'.join(http(url+'/admin/assets/styles/'+name+'.css')[2].decode() for name in ['tokens','base','layout','components'])
    page.add_style_tag(content=css)
    modules={}
    for file in root.rglob('*.js'):
        name=file.relative_to(root).as_posix()
        source=http(url+'/admin/assets/'+name)[2].decode()
        def rewrite(m):
            target=(file.parent/m.group(2).split('?')[0]).resolve().relative_to(root).as_posix()
            return m.group(1)+'cl/'+target+m.group(3)
        source=re.sub(r"(from\s+['\"]|^import\s+['\"])(\.{1,2}/[^'\"]+)(['\"])",rewrite,source,flags=re.M)
        modules['cl/'+name]='const location=window.testLocation;\n'+source
    page.evaluate("""([origin,hash,modules])=>{
        if(hash) window.location.hash=hash;
        const memory=()=>{const data={};Object.defineProperties(data,{getItem:{value:k=>data[k]??null},setItem:{value:(k,v)=>{data[k]=String(v);}}});return data;};
        Object.defineProperty(window,'localStorage',{value:memory(),configurable:true});
        Object.defineProperty(window,'sessionStorage',{value:memory(),configurable:true});
        // about:blank is not a secure context in the restricted runner. Production uses native WebCrypto.
        if(!crypto.subtle) Object.defineProperty(crypto,'subtle',{value:{
            digest:async (_,data)=>Uint8Array.from(await window.fixtureCrypto('digest',[],Array.from(new Uint8Array(data)))).buffer,
            importKey:async (_,data)=>Array.from(new Uint8Array(data)),
            sign:async (_,key,data)=>Uint8Array.from(await window.fixtureCrypto('sign',key,Array.from(new Uint8Array(data)))).buffer
        }});
        window.testDownload=null;
        const urls=new Map();const nativeCreate=URL.createObjectURL.bind(URL);const nativeRevoke=URL.revokeObjectURL.bind(URL);
        URL.createObjectURL=blob=>{const u=nativeCreate(blob);urls.set(u,blob);return u;};
        URL.revokeObjectURL=u=>{urls.delete(u);nativeRevoke(u);};
        HTMLAnchorElement.prototype.click=function(){const blob=urls.get(this.getAttribute('href'));if(blob)blob.text().then(body=>window.testDownload={name:this.download,body});};
        window.testLocation={origin,get hash(){return window.location.hash;},set hash(v){window.location.hash=v;}};
        window.fetch=async (target,options={})=>{const result=await window.fixtureFetch(String(target),options);if(options.signal?.aborted)throw new DOMException('Aborted','AbortError');return new Response(result.body??null,{status:result.status,headers:result.headers});};
        const imports=Object.fromEntries(Object.entries(modules).map(([key,source])=>[key,URL.createObjectURL(new Blob([source],{type:'text/javascript'}))]));
        const map=document.createElement('script');map.type='importmap';map.textContent=JSON.stringify({imports});document.head.append(map);
        return import('cl/panel.js');
    }""",[url,route,modules])


def run():
    offline=os.environ.get('CAJEER_BROWSER_OFFLINE')=='1'
    with server(DB_CONNECTION='unsupported') as url, sync_playwright() as p:
        browser=browser_for(p); ctx=browser.new_context(accept_downloads=True); page=ctx.new_page(); page.set_default_timeout(8000)
        errors=[]; page.on('pageerror',lambda e: errors.append(str(e)))
        # Actual PHP router, not intercepted, even in offline browser mode.
        status,headers,body=http(url+'/admin');assert status==200 and b'0.9.2' in body
        assert "script-src 'self'" in {k.lower():v for k,v in headers.items()}['content-security-policy']
        assert http(url+'/api/v1/auth/me')[0]==401
        assert http(url+'/admin/assets/panel.js')[0]==200
        assert http(url+'/admin/',method='POST')[0]==405
        assert http(url+'/admin/index.html')[0]==404
        assert http(url+'/admin/unknown')[0]==404
        assert http(url+'/api/v1/auth/login',method='POST',body=b'{"email":"admin@example.test","password":"secret"}',headers={'Content-Type':'application/json'})[0]==503
        if not offline:
            response=page.goto(url+'/admin'); assert response.status==200 and page.url.endswith('/admin/')
            expect(page.get_by_role('heading',name='Вход в CajeerLogs')).to_be_visible();login(page);expect(page.get_by_role('alert')).to_contain_text('временно недоступны')
        print('PASS real PHP delivery, CSP header, unauthenticated API and unavailable database')
        f=Fixture()
        if offline:
            page.expose_function('fixtureCrypto',lambda op,key,data:list(hashlib.sha256(bytes(data)).digest() if op=='digest' else hmac.new(bytes(key),bytes(data),hashlib.sha256).digest()))
            page.expose_function('fixtureFetch',offline_adapter(f,url));offline_load(page,url,f)
        else:
            page.route('**/api/v1/**',f.route);page.reload()
        expect(page.get_by_role('heading',name='Вход в CajeerLogs')).to_be_visible()
        login(page,password='bad');expect(page.get_by_role('alert')).to_contain_text('Неверные');login(page)
        expect(page.get_by_role('heading',name='Обзор',exact=True)).to_be_visible();expect(page.get_by_text('Всего событий')).to_be_visible()
        nav(page,'rbac');expect(page.get_by_role('heading',name='Роли и разрешения',exact=True)).to_be_visible()
        page.get_by_role('button',name='Новая роль',exact=True).click();dlg=page.get_by_role('dialog');dlg.get_by_label('Название роли').fill('RBAC Support');dlg.get_by_role('button',name='Создать роль',exact=True).click();expect(page.get_by_role('row').filter(has_text='RBAC Support')).to_be_visible()
        page.get_by_role('button',name='Изменить роль',exact=True).click();dlg.get_by_label('Название роли').fill('RBAC Support v2');dlg.get_by_label('logs:export',exact=False).check();dlg.get_by_role('button',name='Сохранить роль',exact=True).click();expect(page.get_by_role('row').filter(has_text='RBAC Support v2')).to_be_visible()
        assert f.rbac_roles[0]['revision']==2 and 'logs:export' in f.rbac_roles[0]['permissions']
        page.get_by_role('button',name='Удалить роль',exact=True).click();dlg.get_by_role('button',name='Подтвердить',exact=True).click();expect(page.get_by_role('row').filter(has_text='RBAC Support v2')).to_have_count(0)
        nav(page,'overview');print('PASS role editor create, update and delete')
        nav(page,'queue');expect(page.get_by_role('heading',name='Проблемные задания',exact=True)).to_be_visible()
        expect(page.get_by_role('row').filter(has_text='9223372036854775806')).to_be_visible()
        page.get_by_role('row').filter(has_text='9223372036854775806').get_by_role('button',name='История').click();expect(page.get_by_role('dialog').get_by_text('database_error',exact=True)).to_be_visible();page.locator('#close-modal').click()
        page.get_by_label('Причина',exact=True).select_option('database_error');page.get_by_role('button',name='Найти',exact=True).click();expect(page.get_by_role('row').filter(has_text='lease_expired')).to_have_count(0)
        page.get_by_label('Выбрать 9223372036854775806').check();page.get_by_role('button',name='Повторить выбранные').click();dlg=page.get_by_role('dialog');expect(dlg.get_by_role('button',name='Повторить',exact=True)).to_be_disabled();dlg.get_by_label('Подтверждаю операцию').check();dlg.get_by_role('button',name='Повторить',exact=True).click();expect(page.get_by_text('Нет записей.',exact=True)).to_be_visible()
        assert f.dead_jobs[0]['status']=='pending' and any(m=='POST' and path.endswith('/queue/dead/retry') and b['items']==[{'id':'9223372036854775806','attempts':2}] for m,path,q,b in f.calls)
        page.get_by_label('Причина',exact=True).select_option('');page.get_by_role('button',name='Найти',exact=True).click();page.get_by_label('Выбрать 42').check();page.get_by_role('button',name='Отказаться от выбранных').click();dlg=page.get_by_role('dialog');dlg.get_by_label('Подтверждаю операцию').check();dlg.get_by_role('button',name='Отказаться',exact=True).click();expect(page.get_by_text('Нет записей.',exact=True)).to_be_visible();assert f.dead_jobs[1]['status']=='cancelled'
        nav(page,'overview');print('PASS dead-letter metadata history, filter, exact bigint ID, explicit retry and discard')
        # Search projects via the API, revalidate the selected project, and jump to a section.
        page.get_by_role('button',name='Быстрый переход').click();dlg=page.get_by_role('dialog')
        expect(dlg.get_by_role('button',name='Production',exact=False)).to_be_visible()
        dlg.get_by_label('Поиск проекта для перехода').fill('missing');dlg.get_by_role('button',name='Найти проекты').click();expect(dlg.get_by_text('Проекты не найдены.')).to_be_visible()
        dlg.get_by_label('Поиск проекта для перехода').fill('production');dlg.get_by_role('button',name='Найти проекты').click();expect(dlg.get_by_role('button',name='Production',exact=False)).to_be_visible()
        dlg.get_by_label('Открыть раздел').select_option('logs');dlg.get_by_role('button',name='Production',exact=False).click();expect(page.get_by_role('heading',name='Логи',exact=True)).to_be_visible()
        assert any(m=='GET' and path=='/projects' and q.get('q')=='production' for m,path,q,b in f.calls)
        page.keyboard.press('Control+k');expect(dlg.get_by_role('heading',name='Быстрый переход')).to_be_visible();dlg.get_by_role('button',name='Обзор',exact=True).click();expect(page.get_by_role('heading',name='Обзор',exact=True)).to_be_visible()
        # Access can be revoked between listing and opening a project.
        f.projects.append(dict(f.projects[0],id=P2,name='Switch Target',slug='switch-target'))
        page.get_by_role('button',name='Быстрый переход').click();dlg.get_by_label('Поиск проекта для перехода').fill('switch-target');dlg.get_by_role('button',name='Найти проекты').click();expect(dlg.get_by_role('button',name='Switch Target',exact=False)).to_be_visible()
        f.denied_projects.add(P2);dlg.get_by_role('button',name='Switch Target',exact=False).click();expect(dlg.get_by_role('alert')).to_contain_text('Недостаточно прав');page.locator('#close-modal').click();expect(page.get_by_label('Активный проект')).to_have_value(P1)
        f.denied_projects.remove(P2);f.projects.pop()
        print('PASS global workspace search, keyboard navigation, project access recheck and revoked selection')
        # Dashboard controls use the actual API client.
        page.get_by_label('Период',exact=True).select_option('15m')
        page.get_by_label('Сравнить с предыдущим периодом').check()
        page.get_by_role('button',name='Применить',exact=True).click()
        expect(page.get_by_text('К предыдущему:',exact=False).first).to_be_visible()
        assert any(c[1]=='/logs/stats' and c[2].get('interval')=='minute' for c in f.calls)
        expect(page.get_by_role('heading',name='Состояние источников')).to_be_visible()
        page.get_by_role('button',name='Выбрать',exact=True).first.click()
        expect(page.get_by_label('Источник dashboard')).to_have_value(S1)
        assert any(c[1]=='/logs/stats' and c[2].get('source_id')==S1 for c in f.calls)
        expect(page.get_by_role('heading',name='Доля ошибок по времени (UTC)')).to_be_visible()
        expect(page.get_by_role('heading',name='Сравнение периодов')).to_be_visible()
        page.get_by_label('Уровень для графика').select_option('warning')
        expect(page.get_by_role('heading',name='Уровень warning по времени (UTC)')).to_be_visible()
        page.get_by_label('Уровень для графика').select_option('error')
        expect(page.get_by_role('heading',name='Уровень error по времени (UTC)')).to_be_visible()
        level_card=page.locator('section.card').filter(has=page.get_by_role('heading',name='Уровень error по времени (UTC)'))
        level_card.locator('.analytics-bucket').first.click()
        expect(page.get_by_role('heading',name='Логи',exact=True)).to_be_visible()
        assert any(c[1]=='/logs' and c[2].get('level')=='error' and c[2].get('source_id')==S1 for c in f.calls)
        nav(page,'overview')

        page.locator('.analytics-bucket').first.focus()
        expect(page.locator('.analytics-chart-detail').first).to_contain_text('событий')
        page.keyboard.press('Enter')
        expect(page.get_by_role('heading',name='Логи',exact=True)).to_be_visible()
        expect(page.get_by_label('Источник UUID',exact=True)).to_have_value(S1)
        expect(page.get_by_text('database error',exact=False)).to_be_visible()
        assert any(c[1]=='/logs' and c[2].get('source_id')==S1 and c[2].get('from') and c[2].get('to') for c in f.calls)
        page.get_by_role('button',name='Сбросить',exact=True).click()
        nav(page,'overview')
        page.get_by_label('Период',exact=True).select_option('24h')
        page.get_by_label('Источник dashboard').select_option('')
        page.get_by_label('Сравнить с предыдущим периодом').uncheck()
        page.get_by_role('button',name='Применить',exact=True).click()
        expect(page.get_by_text('Всего событий')).to_be_visible()
        print('PASS dashboard UTC period, previous comparison, source selection, keyboard chart drill-down')
        if not offline:
            assert ctx.cookies()[0]['httpOnly'];assert page.evaluate('document.cookie')==''
        expect(page.locator('#breadcrumbs')).to_contain_text('Обзор')
        page.locator('.skip').focus();page.keyboard.press('Enter');assert page.evaluate('document.activeElement.id')=='main'
        print('PASS login, dashboard, breadcrumbs and skip-link fixture')
        nav(page,'logs');expect(page.get_by_role('heading',name='Логи',exact=True)).to_be_visible();expect(page.get_by_text('database error',exact=False)).to_be_visible()
        page.get_by_text('Настроить таблицу',exact=True).click()
        page.get_by_label('Показать Host',exact=True).check()
        expect(page.get_by_role('columnheader',name='Host',exact=True)).to_be_visible()
        page.get_by_label('Показать Сервис',exact=True).uncheck()
        expect(page.get_by_role('columnheader',name='Сервис',exact=True)).to_have_count(0)
        page.get_by_label('Поднять Уровень',exact=True).click()
        expect(page.locator('.explorer-table th').first).to_have_text('Уровень')
        page.get_by_label('Ширина Сообщение',exact=True).fill('500');page.get_by_label('Ширина Сообщение',exact=True).press('Tab')
        assert page.locator('.explorer-table col').evaluate_all('(cs)=>cs.some(c=>c.style.width==="500px")')
        page.get_by_label('Плотность',exact=True).select_option('compact');expect(page.locator('.explorer-table')).to_have_class(re.compile('compact'))
        page.get_by_label('Часовой пояс',exact=True).select_option('local');expect(page.get_by_role('columnheader',name='Время события (лок.)')).to_be_visible()
        page.get_by_label('Перенос текста',exact=True).uncheck();expect(page.locator('.explorer-table')).to_have_class(re.compile('nowrap'))
        page.get_by_role('button',name='Сбросить колонки').click();page.get_by_text('Настроить таблицу',exact=True).click()
        page.get_by_role('button',name='Проверить новые события',exact=True).click();expect(page.get_by_text('Первая страница не изменилась.')).to_be_visible()
        page.get_by_role('button',name='Далее',exact=True).click();expect(page.get_by_text('second page event')).to_be_visible()
        f.head_changed=True;page.get_by_role('button',name='Проверить новые события',exact=True).click();expect(page.get_by_text('Первая страница изменилась:',exact=False)).to_be_visible();expect(page.get_by_text('second page event')).to_be_visible()
        f.head_changed=False;page.get_by_role('button',name='Показать последние',exact=True).click();expect(page.get_by_text('database error',exact=False)).to_be_visible()
        page.get_by_role('button',name='Обновить страницу',exact=True).click();expect(page.get_by_role('button',name='Обновить страницу',exact=True)).to_be_enabled();assert any(c[1]=='/logs' and c[2].get('cursor')=='page-one' for c in f.calls)
        f.multi_logs=True;page.get_by_role('button',name='Обновить страницу',exact=True).click();expect(page.locator('.explorer-open')).to_have_count(2)
        page.locator('.explorer-open').first.focus();page.keyboard.press('ArrowDown');expect(page.locator('.explorer-open').nth(1)).to_be_focused();page.keyboard.press('Enter');expect(page.get_by_role('dialog')).to_contain_text(E2)
        page.get_by_role('button',name='Предыдущее событие').click();expect(page.get_by_role('dialog')).to_contain_text(E1);page.get_by_role('button',name='Следующее событие').click();expect(page.get_by_role('dialog')).to_contain_text(E2);page.locator('#close-modal').click();expect(page.locator('.explorer-open').nth(1)).to_be_focused()
        nav(page,'overview');nav(page,'logs');expect(page.locator('.explorer-open').nth(1)).to_be_focused()
        f.multi_logs=False;page.get_by_role('button',name='Обновить страницу',exact=True).click();expect(page.locator('.explorer-open')).to_have_count(1)
        f.fail_next=True;page.get_by_role('button',name='Обновить страницу',exact=True).click();expect(page.get_by_role('button',name='Повторить запрос')).to_be_visible();expect(page.get_by_text('database error',exact=False)).to_be_visible();page.get_by_role('button',name='Повторить запрос').click();expect(page.get_by_role('button',name='Повторить запрос')).to_have_count(0)
        print('PASS Explorer column settings, layout, live notification without moving page, cursor refresh, keyboard detail navigation, focus restoration and recovery')
        page.get_by_label('Поиск',exact=True).fill('database');page.get_by_label('Уровень',exact=True).select_option('error');page.get_by_label('Режим страницы',exact=True).select_option('snapshot');page.get_by_role('button',name='Найти').click()
        expect(page.get_by_text('database error',exact=False)).to_be_visible();assert any(c[1]=='/logs' and c[2].get('q')=='database' and c[2].get('level')=='error' for c in f.calls)
        page.get_by_text('Дополнительные фильтры',exact=True).click();page.get_by_label('Режим поиска',exact=True).select_option('phrase');page.get_by_label('Добавить Уровни',exact=True).select_option('error');page.get_by_role('button',name='Добавить значение: Уровни',exact=True).click();page.get_by_label('Добавить Уровни',exact=True).select_option('warning');page.get_by_role('button',name='Добавить значение: Уровни',exact=True).click();page.get_by_label('Добавить Любой тег (ANY)',exact=True).fill('db');page.get_by_label('Добавить Любой тег (ANY)',exact=True).press('Enter');page.get_by_label('Добавить Любой тег (ANY)',exact=True).fill('queue');page.get_by_label('Добавить Любой тег (ANY)',exact=True).press('Enter');page.get_by_role('button',name='Найти').click()
        expect(page.get_by_text('database error',exact=False)).to_be_visible();assert any(c[1]=='/logs' and c[2].get('q_mode')=='phrase' and c[2].get('levels')=='["error","warning"]' and c[2].get('tags_any')=='["db","queue"]' for c in f.calls)
        page.get_by_text('Сохранённые представления',exact=True).click();page.get_by_role('button',name='Сохранить текущее').click();page.get_by_label('Название',exact=True).fill('Error investigation');page.get_by_role('dialog').get_by_role('button',name='Сохранить',exact=True).click();expect(page.get_by_label('Сохранённое представление')).to_have_value('88888888-8888-4888-8888-888888888888')
        assert f.views[0]['filters']['levels']=='["error","warning"]' and f.views[0]['layout']['visible'] and 'cursor' not in f.views[0]['filters']
        page.get_by_role('button',name='Переименовать',exact=True).click();page.get_by_label('Название',exact=True).fill('Renamed search');page.get_by_role('dialog').get_by_role('button',name='Сохранить',exact=True).click();expect(page.get_by_label('Сохранённое представление').locator('option').last).to_have_text('Renamed search')
        page.get_by_role('button',name='Сбросить',exact=True).click();expect(page.get_by_label('Поиск',exact=True)).to_have_value('');page.get_by_text('Сохранённые представления',exact=True).click();page.get_by_label('Сохранённое представление').select_option('88888888-8888-4888-8888-888888888888');page.get_by_role('button',name='Применить',exact=True).click();expect(page.get_by_label('Поиск',exact=True)).to_have_value('database')
        page.get_by_text('Сохранённые представления',exact=True).click();page.get_by_label('Сохранённое представление').select_option('88888888-8888-4888-8888-888888888888');page.get_by_role('button',name='Удалить',exact=True).click();page.get_by_role('dialog').get_by_role('button',name='Удалить',exact=True).click();expect(page.get_by_label('Сохранённое представление').locator('option')).to_have_count(1);assert not f.views
        # Build a path using ordered segments and a registry-backed source, then remove one active filter.
        page.get_by_label('С (UTC)',exact=True).fill('2026-11-13T00:00');page.get_by_label('До (UTC)',exact=True).fill('2026-11-15T00:00');page.get_by_label('Добавить JSON-путь context',exact=True).fill('nested');page.get_by_label('Добавить JSON-путь context',exact=True).press('Enter');page.get_by_label('Добавить JSON-путь context',exact=True).fill('0');page.get_by_label('Добавить JSON-путь context',exact=True).press('Enter');page.get_by_label('Значение JSON-пути (scalar)',exact=True).fill('12345678901234567890');page.get_by_role('button',name='Найти').click();expect(page.get_by_text('database error',exact=False)).to_be_visible();assert any(c[1]=='/logs' and c[2].get('context_path')=='["nested","0"]' and c[2].get('context_equals')=='12345678901234567890' for c in f.calls)
        page.get_by_role('button',name='Снять фильтр JSON-путь',exact=True).click();expect(page.get_by_label('Значение JSON-пути (scalar)',exact=True)).to_have_value('');assert any(c[1]=='/logs' and 'context_path' not in c[2] for c in f.calls)
        print('PASS saved view create, rename, restore and delete through UI')
        print('PASS expanded search filters fixture')
        page.get_by_role('button',name='Подробнее').click();expect(page.get_by_role('dialog')).to_be_visible();expect(page.get_by_role('heading',name='JSON context',exact=True)).to_be_visible();assert page.evaluate('window.pwned') is None;assert page.locator('#modal img, #modal script').count()==0
        dlg=page.get_by_role('dialog');dlg.get_by_role('button',name='JSON context',exact=True).click();dlg.locator('summary').filter(has_text='nested:').click();dlg.locator('summary').filter(has_text='items:').click();dlg.locator('summary').filter(has_text='0:').click();expect(dlg.locator('.json-node').filter(has_text='needle')).to_be_visible();dlg.get_by_label('Поиск по JSON').fill('needle');dlg.get_by_role('button',name='Найти в JSON').click();expect(dlg.locator('.json-result')).to_contain_text('/nested/items/0/code')
        dlg.locator('.json-result').get_by_role('button',name='Фильтр',exact=True).click();expect(page.get_by_role('dialog')).not_to_be_visible();expect(page.get_by_label('Значение JSON-пути (scalar)',exact=True)).to_have_value('\"needle\"');assert any(c[1]=='/logs' and c[2].get('context_path')=='["nested","items","0","code"]' for c in f.calls)
        page.get_by_role('button',name='Снять фильтр JSON-путь',exact=True).click();page.get_by_role('button',name='Подробнее').click();expect(dlg.get_by_role('button',name='Raw JSON',exact=True)).to_be_visible()
        dlg.get_by_role('button',name='Raw JSON',exact=True).click();expect(dlg.get_by_label('JSON события')).to_have_value(re.compile('12345678901234567890'))
        if offline:
            page.evaluate('window.testDownload=null');dlg.get_by_role('button',name='Скачать JSON').click();page.wait_for_function('window.testDownload !== null');assert '12345678901234567890' in page.evaluate('window.testDownload.body')
        else:
            with page.expect_download() as dl: dlg.get_by_role('button',name='Скачать JSON').click()
            assert '12345678901234567890' in Path(dl.value.path()).read_text()
        dlg.get_by_role('button',name='Связанные события').click();expect(dlg.get_by_text('Текущее',exact=True)).to_be_visible();assert any(c[1]=='/logs' and c[2].get('trace_id')=='trace-1' and c[2].get('project_id')==P1 for c in f.calls)
        dlg.get_by_role('button',name='Request ID',exact=True).click();expect(dlg.get_by_text('Текущее',exact=True)).to_be_visible();assert any(c[1]=='/logs' and c[2].get('request_id')=='req-1' for c in f.calls)
        dlg.get_by_role('button',name='Далее',exact=True).click();expect(dlg.get_by_text('second page event')).to_be_visible();dlg.get_by_role('button',name='Открыть',exact=True).click();expect(dlg).to_contain_text(E2)
        print('PASS event inspector JSON search, lossless raw download, related trace/request and cursor navigation')
        page.locator('#close-modal').click();page.get_by_role('button',name='Далее',exact=True).click();expect(page.get_by_text('second page event')).to_be_visible();nav(page,'overview');nav(page,'logs');expect(page.get_by_text('second page event')).to_be_visible();page.get_by_role('button',name='Назад',exact=True).click();expect(page.get_by_text('database error',exact=False)).to_be_visible();expect(page.get_by_text('Снимок по received_at:',exact=False)).to_be_visible();assert any(c[1]=='/logs' and c[2].get('cursor')=='page-two' for c in f.calls)
        if offline:
            page.evaluate('window.testDownload=null');page.get_by_role('button',name='CSV',exact=True).click();page.wait_for_function('window.testDownload !== null');dl=page.evaluate('window.testDownload');assert dl['name']=='cajeerlogs.csv' and 'database error' in dl['body']
        else:
            with page.expect_download() as dl: page.get_by_role('button',name='CSV',exact=True).click()
            assert dl.value.suggested_filename=='cajeerlogs.csv' and 'database error' in Path(dl.value.path()).read_text()
        f.change_export=True;page.get_by_role('button',name='CSV',exact=True).click();expect(page.locator('#notice')).to_contain_text('Страница изменилась');f.change_export=False
        print('PASS filters, safe detail rendering, pagination and CSV download')
        nav(page,'projects');expect(page.get_by_role('heading',name='Создать проект')).to_be_visible();form=page.locator('form').filter(has=page.get_by_role('button',name='Создать проект',exact=True));form.get_by_label('Название').fill('New project');form.get_by_label('Slug').fill('new-project');form.get_by_role('button',name='Создать проект').click();expect(page.get_by_role('heading',name='New project')).to_be_visible()
        page.get_by_label('Название проекта',exact=True).fill('Renamed project');page.get_by_label('Описание проекта').fill('Project details');page.get_by_label('Метаданные JSON').fill('{"environment":"test"}');page.get_by_label('Событий в минуту (пусто = без квоты)').fill('50');page.get_by_role('button',name='Сохранить проект').click();expect(page.get_by_role('heading',name='Renamed project')).to_be_visible()
        page.get_by_role('button',name='Архивировать проект').click();page.get_by_role('dialog').get_by_role('button',name='Подтвердить').click();expect(page.get_by_role('button',name='Восстановить проект')).to_be_visible()
        page.get_by_role('button',name='Восстановить проект').click();page.get_by_role('dialog').get_by_role('button',name='Подтвердить').click();expect(page.get_by_role('button',name='Архивировать проект')).to_be_visible()
        print('PASS project edit, quotas, archive and restore fixture')
        form=page.locator('form').filter(has=page.get_by_role('button',name='Добавить источник'));form.get_by_label('Название').fill('New source');form.get_by_label('Slug').fill('new-source');form.get_by_role('button',name='Добавить источник').click();expect(page.get_by_text('New source',exact=True)).to_be_visible()
        page.get_by_role('button',name='Открыть источник').click();expect(page.get_by_role('dialog').get_by_role('heading',name='New source')).to_be_visible()
        page.get_by_label('Название источника').fill('Renamed source');page.get_by_role('dialog').get_by_label('Тип источника').select_option('nginx');page.get_by_label('Событий в минуту (пусто = без отдельной квоты)').fill('10');page.get_by_role('button',name='Сохранить источник').click();expect(page.get_by_text('Renamed source',exact=True)).to_be_visible()
        for action,status in [('Отключить источник','Отключён'),('Архивировать источник','Архивный'),('Восстановить источник','Отключён'),('Включить источник','Активный')]:
            page.get_by_role('button',name='Открыть источник').click();page.get_by_role('dialog').get_by_role('button',name=action).click();page.get_by_role('dialog').get_by_role('button',name='Подтвердить').click();expect(page.get_by_role('row').filter(has_text='Renamed source')).to_contain_text(status)
        page.get_by_label('Поиск источников').fill('missing');page.get_by_role('button',name='Найти источники').click();expect(page.get_by_text('Renamed source',exact=True)).to_have_count(0);page.get_by_label('Поиск источников').fill('Renamed');page.get_by_role('button',name='Найти источники').click();expect(page.get_by_text('Renamed source',exact=True)).to_be_visible()
        print('PASS source edit, type, policy, disable/archive/restore/enable and search fixture')
        # Exercise actual modal JS, draft preview, revision update and reopening against controlled API.
        page.get_by_role('button',name='Настроить маскирование',exact=True).click()
        dlg=page.get_by_role('dialog'); expect(dlg.get_by_label('Правила JSON')).to_be_visible()
        rules=[{'type':'path','value':'/context/customer_id'}]; dlg.get_by_label('Правила JSON').fill(json.dumps(rules))
        dlg.get_by_label('Синтетическое событие JSON').fill(json.dumps({'source':'new-source','timestamp':'2026-01-01T00:00:00Z','level':'info','message':'sample','context':{'customer_id':'preview-private'}}))
        dlg.get_by_role('button',name='Предпросмотр без записи').click(); expect(dlg.locator('pre')).to_contain_text('[redacted]'); assert 'preview-private' not in dlg.locator('pre').inner_text()
        dlg.get_by_role('button',name='Сохранить политику').click(); expect(dlg).not_to_be_visible()
        assert any(v['revision']==1 and v['rules']==rules for v in f.redaction.values())
        page.get_by_role('button',name='Настроить маскирование',exact=True).click(); expect(page.get_by_role('dialog').get_by_label('Правила JSON')).to_be_visible(); assert 'customer_id' in page.get_by_role('dialog').get_by_label('Правила JSON').input_value()
        page.locator('#close-modal').click(); print('PASS redaction policy editor, preview and revision save fixture')

        nav(page,'users');form=page.locator('form').filter(has=page.get_by_role('button',name='Создать пользователя'));form.get_by_label('Email').fill('viewer@example.test');form.get_by_label('Пароль').fill('viewer-password-12345');form.get_by_role('button',name='Создать пользователя').click();expect(page.get_by_text('viewer@example.test',exact=True)).to_be_visible()
        page.get_by_role('row').filter(has_text='viewer@example.test').get_by_role('button',name='Изменить').click();page.get_by_role('dialog').get_by_label('Роль',exact=True).select_option('operator');page.get_by_role('dialog').get_by_role('button',name='Сохранить').click();expect(page.get_by_role('row').filter(has_text='viewer@example.test')).to_contain_text('Оператор')
        nav(page,'projects');page.get_by_label('UUID пользователя').fill(U2);page.get_by_role('button',name='Добавить участника').click();expect(page.get_by_text('viewer@example.test',exact=True)).to_be_visible();page.get_by_role('button',name='Изменить роль').click();page.get_by_role('dialog').get_by_label('Роль в проекте').select_option('viewer');page.get_by_role('dialog').get_by_role('button',name='Сохранить роль').click();expect(page.get_by_role('row').filter(has_text='viewer@example.test')).to_contain_text('Наблюдатель');page.get_by_role('button',name='Убрать доступ').click();page.get_by_role('dialog').get_by_role('button',name='Подтвердить').click();expect(page.get_by_text('viewer@example.test',exact=True)).to_have_count(0)
        nav(page,'users');page.get_by_label('Поиск пользователей').fill('viewer@');page.get_by_role('button',name='Найти пользователей').click();expect(page.get_by_role('row').filter(has_text='viewer@example.test')).to_be_visible();expect(page.get_by_role('row').filter(has_text='admin@example.test')).to_have_count(0)
        page.get_by_role('button',name='Карточка',exact=True).click();expect(page.get_by_role('heading',name='Карточка пользователя')).to_be_visible();expect(page.get_by_text('user.created',exact=True)).to_be_visible()
        page.get_by_label('Поиск проектов для доступа').fill('');page.get_by_role('button',name='Найти',exact=True).click();page.get_by_role('button',name='Выбрать',exact=True).first.click();page.get_by_role('dialog').get_by_label('Роль в проекте').select_option('viewer');page.get_by_role('button',name='Сохранить доступ').click();expect(page.get_by_role('button',name='Убрать членство')).to_be_visible()
        page.get_by_role('button',name='Убрать членство').click();page.get_by_role('button',name='Подтвердить').click();expect(page.get_by_role('button',name='Убрать членство')).to_have_count(0)
        page.get_by_role('button',name='Сбросить 2FA',exact=True).click();dlg=page.get_by_role('dialog');dlg.get_by_label('Ваш текущий пароль').fill('admin-password-12345');dlg.get_by_text('Подтверждаю сброс 2FA другого пользователя').click();dlg.get_by_role('button',name='Сбросить 2FA').click();expect(dlg).not_to_be_visible();expect(page.get_by_role('heading',name='Карточка пользователя')).to_be_visible()
        print('PASS user directory, account detail, audit, membership picker, 2FA reset fixture')
        print('PASS create project/source/user, role update, project membership')
        nav(page,'tokens');page.get_by_label('Название сервиса').fill('Backend service');page.get_by_role('button',name='Создать сервисную учётную запись').click();expect(page.get_by_role('row').filter(has_text='Backend service')).to_be_visible();form=page.locator('form').filter(has=page.get_by_label('logs:read'));form.get_by_label('Название').fill('Reader');form.get_by_label('Сервисная учётная запись (UUID, необязательно)').fill('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');form.get_by_role('button',name='Выпустить токен').click();expect(page.get_by_role('dialog')).to_contain_text(TOKEN);assert TOKEN not in page.evaluate('JSON.stringify(localStorage)+JSON.stringify(sessionStorage)');page.locator('#close-modal').click();expect(page.get_by_text(TOKEN,exact=True)).to_have_count(0);page.get_by_role('button',name='Изменить токен').click();dlg=page.get_by_role('dialog');dlg.get_by_label('Название',exact=True).fill('Renamed Reader');dlg.get_by_label('Запросов в минуту (необязательно)').fill('60');dlg.get_by_role('button',name='Сохранить токен').click();expect(page.get_by_role('row').filter(has_text='Renamed Reader')).to_be_visible();page.get_by_role('button',name='Ротация',exact=True).click();dlg=page.get_by_role('dialog');dlg.get_by_role('button',name='Выпустить замену').click();expect(dlg).to_contain_text('cl_'+'b'*64);page.locator('#close-modal').click();expect(page.get_by_text('Перекрытие',exact=True)).to_be_visible();page.get_by_role('button',name='Отозвать',exact=True).first.click();page.get_by_role('dialog').get_by_role('button',name='Подтвердить').click();expect(page.get_by_text('Отозван',exact=True)).to_be_visible();page.get_by_role('button',name='Отключить сервис').click();page.get_by_role('dialog').get_by_role('button',name='Подтвердить').click();expect(page.get_by_text('Отключена',exact=True)).to_be_visible()
        # Security management: real modules with fixture API responses.
        page.get_by_label('Поиск сервиса').fill('Backend');page.get_by_role('button',name='Найти сервис').click();page.get_by_role('button',name='Карточка сервиса').click();dlg=page.get_by_role('dialog');expect(dlg).to_contain_text('Всего токенов');dlg.get_by_role('button',name='Показать токены сервиса').click();expect(page.get_by_label('Фильтр UUID сервиса')).to_have_value('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
        page.get_by_role('button',name='Карточка токена').first.click();expect(page.get_by_role('dialog')).to_contain_text('Предыдущий токен');assert TOKEN not in page.get_by_role('dialog').inner_text();page.locator('#close-modal').click()
        page.get_by_text('Журнал безопасности проекта',exact=True).click();expect(page.get_by_text('token.created',exact=True)).to_be_visible();page.get_by_role('button',name='Запись аудита').click();expect(page.get_by_role('dialog')).to_contain_text('safe-audit-id');page.locator('#close-modal').click()
        page.get_by_label('Действие аудита').first.select_option('token.revoked');page.get_by_role('button',name='Фильтровать аудит').first.click();expect(page.get_by_text('token.created',exact=True)).to_have_count(0)
        print('PASS token/account detail, account filter, scoped audit and credential secrecy')
        nav(page,'security');expect(page.get_by_role('heading',name='Безопасность и аудит',exact=True)).to_be_visible();expect(page.get_by_text('token.created',exact=True)).to_be_visible()
        page.get_by_text('Глобальный журнал безопасности',exact=True).click();expect(page.get_by_text('token.created',exact=True)).to_have_count(2)
        expect(page.get_by_role('link',name='Мой профиль, 2FA и сессии')).to_be_visible()
        project_audit=page.locator('details').filter(has=page.get_by_text('Журнал безопасности проекта',exact=True))
        project_audit.get_by_label('Результат',exact=True).select_option('failure');project_audit.get_by_role('button',name='Фильтровать аудит').click();expect(project_audit.get_by_text('token.created',exact=True)).to_have_count(0)
        project_audit.get_by_label('Результат',exact=True).select_option('success');project_audit.get_by_label('Тип инициатора').select_option('user');project_audit.get_by_label('Начало UTC').fill('2026-11-01T00:00');project_audit.get_by_role('button',name='Фильтровать аудит').click();expect(project_audit.get_by_text('token.created',exact=True)).to_be_visible()
        assert any(c[2].get('from')=='2026-11-01T00:00:00Z' and c[2].get('result')=='success' for c in f.calls)
        if offline:
            project_audit.get_by_role('button',name='Экспорт NDJSON (до 100 записей)').click();page.wait_for_function("window.testDownload?.name==='cajeerlogs-audit.ndjson'");assert json.loads(page.evaluate('window.testDownload.body'))['result']=='success'
        else:
            with page.expect_download() as download:
                project_audit.get_by_role('button',name='Экспорт NDJSON (до 100 записей)').click()
            assert json.loads(Path(download.value.path()).read_text())['result']=='success'
        print('PASS audit result/actor/time filters and NDJSON download')
        print('PASS integrated security workspace and global/project audit')
        # Full wizard: new source, source-scoped write-only token, v2 signature, lost-response replay, persisted detail and drill-down.
        nav(page,'projects');page.get_by_label('Поиск проектов').fill(P2);page.get_by_role('button',name='Найти проекты').click();expect(page.get_by_role('row').filter(has_text=P2).first).to_be_visible()
        page.get_by_label('Фильтр типа источника').select_option('server');page.get_by_role('button',name='Найти источники').click();expect(page.get_by_role('button',name='Открыть источник')).to_have_count(0)
        page.get_by_label('Поиск источников').fill('');page.get_by_label('Фильтр типа источника').select_option('all');page.get_by_role('button',name='Найти источники').click()
        page.get_by_role('button',name='Мастер подключения').click();dlg=page.get_by_role('dialog');dlg.get_by_label('Название',exact=True).fill('Onboarding app');dlg.get_by_label('Slug').fill('onboarding');dlg.get_by_label('Тип подключения').select_option('server');dlg.get_by_role('button',name='Создать источник подключения').click()
        dlg.get_by_label('Режим подписи подключения').select_option('separate');dlg.get_by_role('button',name='Выпустить токен подключения').click();expect(dlg).to_contain_text('cl_'+'d'*64);expect(dlg).to_contain_text('sk_'+'e'*64);assert 'cl_'+'d'*64 not in page.evaluate('JSON.stringify(localStorage)+JSON.stringify(sessionStorage)')
        dlg.get_by_role('button',name='Проверить подключение').click();expect(dlg.get_by_role('alert')).to_be_visible();dlg.get_by_role('button',name='Проверить подключение').click();expect(dlg).to_contain_text('Тестовое событие сохранено и найдено:');assert len(f.connection_attempts)==2
        dlg.get_by_role('button',name='Открыть тестовое событие в логах').click();expect(page.get_by_role('heading',name='Логи',exact=True)).to_be_visible();expect(page.get_by_text('CajeerLogs source connection test',exact=True)).to_be_visible();expect(page.get_by_text('cl_'+'d'*64,exact=True)).to_have_count(0)
        page.get_by_role('button',name='Сбросить',exact=True).click();print('PASS project/source directory, HMAC v2 onboarding, receipt replay and Explorer drill-down fixture')
        nav(page,'tokens');page.get_by_role('button',name='HMAC',exact=True).click();dlg=page.get_by_role('dialog');dlg.get_by_label('Перекрытие, секунд (только при ротации v2)').fill('3600');dlg.get_by_role('button',name='Сохранить / ротировать ключ').click();expect(dlg).to_contain_text('sk_'+'f'*64);page.locator('#close-modal').click()
        page.get_by_role('button',name='HMAC',exact=True).click();dlg.get_by_role('button',name='Завершить перекрытие HMAC').click();dlg.get_by_role('button',name='Подтвердить').click();expect(dlg).not_to_be_visible();page.get_by_role('button',name='HMAC',exact=True).click();expect(dlg.get_by_role('button',name='Завершить перекрытие HMAC')).to_have_count(0);page.locator('#close-modal').click();assert any(m=='DELETE' and path.endswith('/signing/previous') for m,path,q,b in f.calls)
        print('PASS HMAC key rotation, one-time secret, early overlap retirement fixture')
        f.system_report_fails=True;nav(page,'system');expect(page.get_by_text('Подробный отчёт недоступен.',exact=False)).to_be_visible();expect(page.get_by_role('row').filter(has_text='Runtime')).to_contain_text('HTTP 200');f.system_report_fails=False;page.get_by_role('button',name='Обновить',exact=True).click();expect(page.get_by_role('button',name='Скопировать отчёт')).to_be_visible();expect(page.get_by_role('heading',name='Ограничения запросов')).to_be_visible();expect(page.get_by_role('row').filter(has_text='global')).to_contain_text('150');expect(page.get_by_role('heading',name='Безопасность развёртывания')).to_be_visible();expect(page.get_by_role('row').filter(has_text='Доверенные proxy')).to_contain_text('1');expect(page.get_by_role('heading',name='Резервное копирование')).to_be_visible();expect(page.get_by_role('row').filter(has_text='Внешняя копия')).to_contain_text('Проверена');expect(page.get_by_role('row').filter(has_text='Длительность восстановления')).to_contain_text('12.5');expect(page.get_by_role('heading',name='Хранение и retention')).to_be_visible();expect(page.get_by_role('row').filter(has_text='Всего удалено')).to_contain_text('42');expect(page.get_by_role('row').filter(has_text='Осталось не менее')).to_contain_text('3');expect(page.get_by_role('heading',name='Повторные попытки очереди')).to_be_visible();expect(page.get_by_role('row').filter(has_text='Попытки новых jobs')).to_contain_text('4');expect(page.get_by_role('heading',name='Queue workers')).to_be_visible();expect(page.get_by_role('row').filter(has_text='worker-proof')).to_contain_text('123');expect(page.get_by_role('heading',name='Ёмкость очереди')).to_be_visible();expect(page.get_by_role('row').filter(has_text='Активные задания')).to_contain_text('2 / 1000');expect(page.get_by_role('row').filter(has_text='Приём новых jobs')).to_contain_text('Приостановлен');expect(page.get_by_role('row').filter(has_text='Порог остановки')).to_contain_text('85%');expect(page.get_by_role('row').filter(has_text='Отказы: jobs / events')).to_contain_text('1 / 2 / 3 / 4');expect(page.get_by_role('heading',name='Фоновые процессы')).to_be_visible();expect(page.get_by_role('row').filter(has_text='scheduler')).to_contain_text('never');expect(page.get_by_role('row').filter(has_text='webhook')).to_contain_text('stale');print('PASS partial system diagnostics, rate policies, deployment security, backup, retention and worker heartbeat')
        nav(page,'settings');page.get_by_role('button',name='Тёмная тема').first.click();assert page.locator('html').get_attribute('data-theme')=='dark';page.get_by_label('Цветовая тема',exact=True).select_option('system');page.emulate_media(color_scheme='dark');expect(page.locator('html')).to_have_attribute('data-theme','dark');page.emulate_media(color_scheme='light');expect(page.locator('html')).to_have_attribute('data-theme','light');page.get_by_label('Цветовая тема',exact=True).select_option('dark');expect(page.locator('html')).to_have_attribute('data-theme','dark');page.set_viewport_size({'width':390,'height':844});assert page.evaluate('document.documentElement.scrollWidth <= innerWidth');expect(page.locator('#sidebar')).not_to_be_visible();page.get_by_role('button',name='Меню').click();expect(page.locator('#sidebar')).to_be_visible();page.keyboard.press('Escape');expect(page.locator('#sidebar')).not_to_be_visible();page.get_by_role('button',name='Меню').click();nav(page,'logs');expect(page.locator('#sidebar')).not_to_be_visible();expect(page.get_by_role('heading',name='Логи',exact=True)).to_be_visible();page.set_viewport_size({'width':1280,'height':720});nav(page,'settings');
        page.get_by_label('Отображаемое имя').fill('Admin <test>');page.get_by_role('button',name='Сохранить профиль').click();expect(page.locator('#account')).to_contain_text('Admin <test>')
        expect(page.get_by_text('Текущая сессия',exact=True)).to_be_visible();page.get_by_role('row').filter(has_text='Other device').get_by_role('button',name='Завершить',exact=True).click();page.get_by_role('dialog').get_by_role('button',name='Подтвердить').click();expect(page.get_by_text('Other device',exact=True)).to_have_count(0)
        print('PASS profile, current session and individual revoke')
        print('PASS service account, credential issue, metadata, rotation, revoke, system report, theme and mobile layout')
        if offline: offline_load(page,url,f,'settings')
        else: page.reload()
        expect(page.get_by_role('heading',name='Настройки',exact=True)).to_be_visible();f.user=None;page.locator('#nav a[href="#logs"]').click();expect(page.get_by_role('heading',name='Вход в CajeerLogs')).to_be_visible();expect(page.get_by_text('Сессия истекла.',exact=False)).to_be_visible()
        f.role='viewer';login(page,email='viewer@example.test');expect(page.get_by_role('heading',name='Логи',exact=True)).to_be_visible();expect(page.locator('#nav a[href="#tokens"]')).to_have_count(0);expect(page.locator('#nav a[href="#queue"]')).to_have_count(0);expect(page.locator('#nav a[href="#users"]')).to_have_count(0);page.evaluate("location.hash='users'");expect(page.get_by_role('heading',name='Обзор',exact=True)).to_be_visible();nav(page,'projects');expect(page.get_by_role('button',name='Создать проект')).to_have_count(0);expect(page.get_by_role('button',name='Добавить источник')).to_have_count(0)
        page.get_by_role('button',name='Выйти',exact=True).click();expect(page.get_by_role('heading',name='Вход в CajeerLogs')).to_be_visible();assert not errors, errors
        print('PASS session restore, expiry, viewer restrictions, logout; no uncaught browser errors')
        f.role='operator';login(page,email='operator@example.test');nav(page,'tokens');expect(page.get_by_role('heading',name='Токены проекта')).to_be_visible()
        selected=page.get_by_label('Активный проект').input_value();f.project_roles[selected]='viewer';page.evaluate("window.dispatchEvent(new Event('focus'))")
        expect(page.get_by_text('Нет разрешений на токены или сервисные аккаунты в этом проекте.')).to_be_visible();expect(page.get_by_role('heading',name='Токены проекта')).to_have_count(0)
        nav(page,'logs');expect(page.get_by_role('heading',name='Логи',exact=True)).to_be_visible();f.denied_projects.add(selected);page.evaluate("window.dispatchEvent(new Event('focus'))")
        expect(page.get_by_label('Активный проект')).not_to_have_value(selected);assert not errors,errors
        page.get_by_role('button',name='Выйти',exact=True).click();expect(page.get_by_role('heading',name='Вход в CajeerLogs')).to_be_visible();f.denied_projects.clear();f.project_roles.clear()
        print('PASS effective-role downgrade, revoked project eviction and fresh workspace')
        f.role='operator';f.custom_permissions=['projects:read','logs:read'];login(page,email='support@example.test');nav(page,'logs');expect(page.get_by_role('button',name='CSV',exact=True)).to_have_count(0);expect(page.locator('#nav a[href="#tokens"]')).to_have_count(0);expect(page.locator('#nav a[href="#rbac"]')).to_have_count(0);nav(page,'projects');expect(page.get_by_role('button',name='Добавить источник')).to_have_count(0)
        page.get_by_role('button',name='Выйти',exact=True).click();expect(page.get_by_role('heading',name='Вход в CajeerLogs')).to_be_visible();f.custom_permissions=None
        print('PASS restricted operator sees logs but not export or management controls')
        f.role='administrator'; f.require_totp=True; login(page)
        expect(page.get_by_role('heading',name='Настройка 2FA',exact=True)).to_be_visible();expect(page.locator('#nav a[href="#logs"]')).to_have_count(0)
        setup=page.locator('form').filter(has=page.get_by_role('button',name='Настроить 2FA',exact=True));setup.get_by_label('Текущий пароль').fill('admin-password-12345');setup.get_by_role('button',name='Настроить 2FA').click()
        expect(page.get_by_role('dialog')).to_contain_text('JBSWY3DPEHPK3PXP');expect(page.get_by_role('img',name='QR-код настройки TOTP')).to_be_visible();page.get_by_role('dialog').get_by_label('6-значный код').fill('123456');page.get_by_role('dialog').get_by_role('button',name='Подтвердить').click();expect(page.get_by_role('dialog')).to_contain_text('a'*32)
        assert 'a'*32 not in page.evaluate('JSON.stringify(localStorage)+JSON.stringify(sessionStorage)');page.get_by_role('button',name='Сохранено').click();expect(page.get_by_text('a'*32,exact=True)).to_have_count(0)
        page.get_by_role('button',name='Выйти',exact=True).click();expect(page.get_by_role('heading',name='Вход в CajeerLogs')).to_be_visible();login(page);expect(page.get_by_role('heading',name='Подтверждение 2FA')).to_be_visible()
        page.get_by_label('Код 2FA / recovery (если включено)').fill('000000');page.get_by_role('button',name='Подтвердить вход').click();expect(page.get_by_role('alert')).to_contain_text('Неверные')
        page.get_by_label('Код 2FA / recovery (если включено)').fill('123456');page.get_by_role('button',name='Подтвердить вход').click();expect(page.get_by_role('heading',name='Настройки',exact=True)).to_be_visible();f.require_totp=False
        f.reauth=True;page.evaluate("window.dispatchEvent(new Event('focus'))");expect(page.get_by_role('heading',name='Подтверждение личности')).to_be_visible()
        confirm=page.locator('form').filter(has=page.get_by_role('button',name='Подтвердить личность',exact=True));confirm.get_by_label('Текущий пароль').fill('admin-password-12345');confirm.get_by_label('Код 2FA или recovery').fill('000000');confirm.get_by_role('button',name='Подтвердить личность').click();expect(confirm.get_by_role('alert')).to_be_visible();confirm.get_by_label('Код 2FA или recovery').fill('123456');confirm.get_by_role('button',name='Подтвердить личность').click();expect(page.get_by_text('Подтверждение действительно до',exact=False)).to_be_visible();assert confirm.get_by_label('Текущий пароль').input_value()==''
        print('PASS recent authentication settings form and fresh second-factor validation (fixture)')
        disable=page.locator('form').filter(has=page.get_by_role('button',name='Отключить 2FA',exact=True));disable.get_by_label('Текущий пароль').fill('admin-password-12345');disable.get_by_label('Код приложения или recovery-код').fill('123456');disable.get_by_role('button',name='Отключить 2FA').click();expect(page.get_by_role('heading',name='Вход в CajeerLogs')).to_be_visible();assert not errors,errors
        print('PASS mandatory enrollment, TOTP setup/enable, recovery code lifecycle, login factor and disable (fixture)')
        browser.close()

if __name__=='__main__': run()
