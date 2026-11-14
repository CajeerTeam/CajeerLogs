"""Chromium UI tests: real PHP delivery, plus a deterministic API fixture for UI interactions.

Run: python -m pip install playwright; python -m playwright install chromium
     python tests/admin_browser.py
Set CHROMIUM_PATH to use an installed system browser. Fixture responses are NOT DB tests.
"""
import contextlib
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
    path = os.environ.get('CHROMIUM_PATH') or shutil.which('chromium')
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
        self.user = None; self.totp_enabled=False; self.require_totp=False; self.role = 'administrator'; self.projects=[{'id':P1,'name':'Production','slug':'production','created_at':'2026-01-01T00:00:00Z'}]
        self.sources=[{'id':S1,'project_id':P1,'name':'Application','slug':'app','created_at':'2026-01-01T00:00:00Z'}]
        self.users=[{'id':U1,'email':'admin@example.test','role':'administrator','blocked_at':None}]
        self.members=[]; self.tokens=[]; self.calls=[]; self.fail_next=False
        self.event={'id':E1,'project_id':P1,'source_id':S1,'source':'app','service':'api','host':'host-1','level':'error','message':'<img src=x onerror="window.pwned=1"> database error','context':{'secret':'[redacted]','html':'<script>window.pwned=1</script>'},'trace_id':'trace-1','request_id':'req-1','tags':['prod'],'timestamp':'2026-11-14T00:00:00Z','received_at':'2026-11-14T00:00:01Z'}
    def route(self,r):
        req=r.request; path=urlsplit(req.url).path.removeprefix('/api/v1'); method=req.method; q={k:v[0] for k,v in parse_qs(urlsplit(req.url).query).items()}; body=req.post_data_json if req.post_data else None
        self.calls.append((method,path,q,body))
        def send(data=None,status=200,headers=None):
            if status==204: r.fulfill(status=status,headers=headers or {});return
            r.fulfill(status=status,content_type='application/json',body=json.dumps(data),headers=headers or {})
        def err(status,code): send({'error':code,'request_id':'test'},status)
        def listing(items,next_cursor=None): send({'items':items,'next_cursor':next_cursor})
        if path=='/auth/login':
            if body['password']=='bad' or (self.totp_enabled and body.get('otp')!='123456'): return err(401,'unauthorized')
            self.user={'id':U1 if self.role=='administrator' else U2,'email':body['email'],'role':self.role,'two_factor_enabled':self.totp_enabled}
            return send({'user':self.user,'csrf_token':CSRF,'expires_in':28800,'enrollment_required':self.require_totp and not self.totp_enabled},headers={'Set-Cookie':'cajeerlogs_session='+'b'*64+'; Path=/; HttpOnly; SameSite=Strict'})
        if path=='/health': return send({'status':'ok','version':'0.1.4'})
        if self.user is None: return err(401,'unauthorized')
        if method!='GET': assert req.headers.get('x-csrf-token')==CSRF, (method,path,req.headers)
        if path=='/auth/me': return send({'user':self.user,'csrf_token':CSRF,'enrollment_required':self.require_totp and not self.totp_enabled})
        if path=='/auth/2fa/setup':
            assert body['password']=='admin-password-12345'; return send({'secret':'JBSWY3DPEHPK3PXP','otpauth_uri':'otpauth://totp/test','expires_in':600})
        if path=='/auth/2fa/enable':
            assert body['otp']=='123456'; self.totp_enabled=True; self.user['two_factor_enabled']=True
            return send({'user':self.user,'csrf_token':CSRF,'expires_in':28800,'enrollment_required':False,'recovery_codes':['a'*32,'b'*32]})
        if path=='/auth/2fa/disable':
            assert body['otp']=='123456' and body['password']=='admin-password-12345'; self.totp_enabled=False; self.user=None; return send(status=204)
        if path in ['/auth/logout','/auth/logout-all','/auth/password']:
            self.user=None;return send(status=204,headers={'Set-Cookie':'cajeerlogs_session=; Path=/; Max-Age=0'})
        if path=='/projects':
            if method=='POST': self.projects.append({'id':P2,**body});return send({'id':P2},201)
            return listing(self.projects)
        if path in ['/projects/'+P1,'/projects/'+P2]: return send(next(p for p in self.projects if p['id']==path.split('/')[-1]))
        if path.endswith('/sources'):
            if method=='POST': self.sources.append({'id':'99999999-9999-4999-8999-999999999999','project_id':path.split('/')[2],**body});return send({'id':self.sources[-1]['id']},201)
            return listing([s for s in self.sources if s['project_id']==path.split('/')[2]])
        if path.endswith('/members'):
            return listing(self.members)
        if '/members/' in path:
            if method=='PUT': self.members=[self.users[-1]]
            else: self.members=[]
            return send(status=204)
        if path=='/users':
            if method=='POST': self.users.append({'id':U2,'email':body['email'],'role':body['role'],'blocked_at':None});return send({'id':U2},201)
            return listing(self.users)
        if path=='/users/'+U2:
            self.users[-1].update(email=body['email'],role=body['role'],blocked_at='2026-01-01' if body['blocked'] else None);return send({'id':U2})
        if path.endswith('/tokens'):
            if method=='POST': self.tokens.append({'id':T1,'name':body['name'],'scopes':body['scopes'],'source_id':body.get('source_id'),'expires_at':'2099-01-01T00:00:00Z','revoked_at':None});return send({'id':T1,'token':TOKEN},201)
            return listing(self.tokens if path.startswith('/projects/') else [])
        if path.endswith('/tokens/'+T1): self.tokens[0]['revoked_at']='2026-01-01';return send(status=204)
        if path=='/logs/stats': return send({'total':2,'by_level':{'error':2,'warning':0},'by_source':[{'source':'app','source_id':S1,'count':2}],'buckets':[{'timestamp':'2026-11-14T00:00:00Z','count':2}],'sources_truncated':False,'from':q.get('from'),'to':q.get('to')})
        if path=='/logs':
            if self.fail_next: self.fail_next=False;return err(503,'service_unavailable')
            assert q['project_id'] in [P1,P2]
            if q.get('cursor'): return listing([{**self.event,'id':E2,'message':'second page event'}])
            return listing([self.event], 'opaque-cursor')
        if path=='/logs/export':
            assert q.get('project_id') in [P1,P2]
            if q['format']=='csv': return r.fulfill(status=200,content_type='text/csv',body='id,message\n'+E1+',database error\n')
            return send({'items':[self.event],'next_cursor':None})
        if path.startswith('/logs/'): return send({'data':self.event})
        if path=='/system/report': return send({'status':'ok','environment':'test','php':'8.4','database':{'status':'ok'}})
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
        if target=='/ready': return {'status':503,'body':'{"status":"down"}','headers':{'Content-Type':'application/json'}}
        path=urlsplit(target).path
        if not path.startswith('/api/v1/'): raise AssertionError('Unexpected URL '+target)
        data=options.get('body'); req=SimpleNamespace(url=target,method=options.get('method','GET'),headers={k.lower():v for k,v in options.get('headers',{}).items()},post_data=data,post_data_json=json.loads(data) if data else None)
        response={}
        def fulfill(status=200,body=None,content_type=None,headers=None):
            response.update(status=status,body=body,headers={**(headers or {}),**({'Content-Type':content_type} if content_type else {})})
        fixture.route(SimpleNamespace(request=req,fulfill=fulfill)); return response
    return fetch


def offline_load(page, url, route=''):
    # Use a real Chromium document, with actual HTML/CSS/JS served by PHP.
    # This mode cannot verify cookie handling, CSP enforcement, or real downloads.
    page.goto('about:blank')
    html=http(url+'/admin/')[2].decode(); css=http(url+'/admin/assets/panel.css')[2].decode(); js=http(url+'/admin/assets/panel.js')[2].decode()
    html=re.sub(r'<script[^>]*src="[^"]*"[^>]*></script>', '', html)
    html=re.sub(r'<link[^>]*rel="stylesheet"[^>]*>', '', html)
    page.set_content(html); page.add_style_tag(content=css)
    page.evaluate("""([origin,hash,source])=>{
        if(hash) window.location.hash=hash;
        const memory=()=>{const data={};Object.defineProperties(data,{getItem:{value:k=>data[k]??null},setItem:{value:(k,v)=>{data[k]=String(v);}}});return data;};
        Object.defineProperty(window,'localStorage',{value:memory(),configurable:true});
        Object.defineProperty(window,'sessionStorage',{value:memory(),configurable:true});
        window.testDownload=null;
        const urls=new Map();let n=0;
        URL.createObjectURL=blob=>{const u='blob:test-'+(++n);urls.set(u,blob);return u;};
        URL.revokeObjectURL=u=>urls.delete(u);
        HTMLAnchorElement.prototype.click=function(){const blob=urls.get(this.getAttribute('href'));if(blob)blob.text().then(body=>window.testDownload={name:this.download,body});};
        const location={origin,get hash(){return window.location.hash;},set hash(v){window.location.hash=v;}};
        const fetch=async (target,options={})=>{const result=await window.fixtureFetch(String(target),options);if(options.signal?.aborted)throw new DOMException('Aborted','AbortError');return new Response(result.body??null,{status:result.status,headers:result.headers});};
        new Function('location','fetch',source)(location,fetch);
    }""",[url,route,js])


def run():
    offline=os.environ.get('CAJEER_BROWSER_OFFLINE')=='1'
    with server(DB_CONNECTION='unsupported') as url, sync_playwright() as p:
        browser=browser_for(p); ctx=browser.new_context(accept_downloads=True); page=ctx.new_page(); page.set_default_timeout(8000)
        errors=[]; page.on('pageerror',lambda e: errors.append(str(e)))
        # Actual PHP router, not intercepted, even in offline browser mode.
        status,headers,body=http(url+'/admin');assert status==200 and b'0.1.4' in body
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
            page.expose_function('fixtureFetch',offline_adapter(f,url));offline_load(page,url)
        else:
            page.route('**/api/v1/**',f.route);page.reload()
        expect(page.get_by_role('heading',name='Вход в CajeerLogs')).to_be_visible()
        login(page,password='bad');expect(page.get_by_role('alert')).to_contain_text('Неверные');login(page)
        expect(page.get_by_role('heading',name='Обзор',exact=True)).to_be_visible();expect(page.get_by_text('Всего событий')).to_be_visible()
        if not offline:
            assert ctx.cookies()[0]['httpOnly'];assert page.evaluate('document.cookie')==''
        print('PASS login and dashboard fixture')
        nav(page,'logs');expect(page.get_by_role('heading',name='Логи',exact=True)).to_be_visible();expect(page.get_by_text('database error',exact=False)).to_be_visible()
        page.get_by_label('Поиск',exact=True).fill('database');page.get_by_label('Уровень',exact=True).select_option('error');page.get_by_role('button',name='Найти').click()
        expect(page.get_by_text('database error',exact=False)).to_be_visible();assert any(c[1]=='/logs' and c[2].get('q')=='database' and c[2].get('level')=='error' for c in f.calls)
        page.get_by_role('button',name='Подробнее').click();expect(page.get_by_role('dialog')).to_be_visible();expect(page.get_by_role('heading',name='JSON context',exact=True)).to_be_visible();assert page.evaluate('window.pwned') is None;assert page.locator('#modal img, #modal script').count()==0
        page.locator('#close-modal').click();page.get_by_role('button',name='Далее',exact=True).click();expect(page.get_by_text('second page event')).to_be_visible();page.get_by_role('button',name='Назад',exact=True).click();expect(page.get_by_text('database error',exact=False)).to_be_visible()
        if offline:
            page.get_by_role('button',name='CSV',exact=True).click();page.wait_for_function('window.testDownload !== null');dl=page.evaluate('window.testDownload');assert dl['name']=='cajeerlogs.csv' and 'database error' in dl['body']
        else:
            with page.expect_download() as dl: page.get_by_role('button',name='CSV',exact=True).click()
            assert dl.value.suggested_filename=='cajeerlogs.csv' and 'database error' in Path(dl.value.path()).read_text()
        print('PASS filters, safe detail rendering, pagination and CSV '+('blob' if offline else 'download'))
        nav(page,'projects');expect(page.get_by_role('heading',name='Создать проект')).to_be_visible();form=page.locator('form').filter(has=page.get_by_role('button',name='Создать проект',exact=True));form.get_by_label('Название').fill('New project');form.get_by_label('Slug').fill('new-project');form.get_by_role('button',name='Создать проект').click();expect(page.get_by_role('heading',name='New project')).to_be_visible()
        form=page.locator('form').filter(has=page.get_by_role('button',name='Добавить источник'));form.get_by_label('Название').fill('New source');form.get_by_label('Slug').fill('new-source');form.get_by_role('button',name='Добавить источник').click();expect(page.get_by_text('New source',exact=True)).to_be_visible()
        nav(page,'users');form=page.locator('form').filter(has=page.get_by_role('button',name='Создать пользователя'));form.get_by_label('Email').fill('viewer@example.test');form.get_by_label('Пароль').fill('viewer-password-12345');form.get_by_role('button',name='Создать пользователя').click();expect(page.get_by_text('viewer@example.test',exact=True)).to_be_visible()
        page.get_by_role('row').filter(has_text='viewer@example.test').get_by_role('button',name='Изменить').click();page.get_by_role('dialog').get_by_label('Роль',exact=True).select_option('operator');page.get_by_role('dialog').get_by_role('button',name='Сохранить').click();expect(page.get_by_role('row').filter(has_text='viewer@example.test')).to_contain_text('Оператор')
        nav(page,'projects');page.get_by_label('UUID пользователя').fill(U2);page.get_by_role('button',name='Добавить участника').click();expect(page.get_by_text('viewer@example.test',exact=True)).to_be_visible();page.get_by_role('button',name='Убрать доступ').click();page.get_by_role('dialog').get_by_role('button',name='Подтвердить').click();expect(page.get_by_text('viewer@example.test',exact=True)).to_have_count(0)
        print('PASS create project/source/user, role update, project membership')
        nav(page,'tokens');form=page.locator('form').filter(has=page.get_by_label('logs:read'));form.get_by_label('Название').fill('Reader');form.get_by_role('button',name='Выпустить токен').click();expect(page.get_by_role('dialog')).to_contain_text(TOKEN);assert TOKEN not in page.evaluate('JSON.stringify(localStorage)+JSON.stringify(sessionStorage)');page.locator('#close-modal').click();expect(page.get_by_text(TOKEN,exact=True)).to_have_count(0);page.get_by_role('button',name='Отозвать').click();page.get_by_role('dialog').get_by_role('button',name='Подтвердить').click();expect(page.get_by_text('Отозван',exact=True)).to_be_visible()
        nav(page,'system');expect(page.get_by_role('heading',name='Системный отчёт')).to_be_visible();nav(page,'settings');page.get_by_role('button',name='Тёмная тема').first.click();assert page.locator('html').get_attribute('data-theme')=='dark';page.set_viewport_size({'width':390,'height':844});assert page.evaluate('document.documentElement.scrollWidth <= innerWidth');page.set_viewport_size({'width':1280,'height':720});
        print('PASS token creation, secret lifecycle, revoke, system report, theme and mobile layout')
        if offline: offline_load(page,url,'settings')
        else: page.reload()
        expect(page.get_by_role('heading',name='Настройки',exact=True)).to_be_visible();f.user=None;page.locator('#nav a[href="#logs"]').click();expect(page.get_by_role('heading',name='Вход в CajeerLogs')).to_be_visible();expect(page.get_by_text('Сессия истекла.',exact=False)).to_be_visible()
        f.role='viewer';login(page,email='viewer@example.test');expect(page.get_by_role('heading',name='Логи',exact=True)).to_be_visible();expect(page.locator('#nav a[href="#tokens"]')).to_have_count(0);expect(page.locator('#nav a[href="#users"]')).to_have_count(0);nav(page,'projects');expect(page.get_by_role('button',name='Создать проект')).to_have_count(0);expect(page.get_by_role('button',name='Добавить источник')).to_have_count(0)
        page.get_by_role('button',name='Выйти',exact=True).click();expect(page.get_by_role('heading',name='Вход в CajeerLogs')).to_be_visible();assert not errors, errors
        print('PASS session restore, expiry, viewer restrictions, logout; no uncaught browser errors')
        f.role='administrator'; f.require_totp=True; login(page)
        expect(page.get_by_role('heading',name='Настройка 2FA',exact=True)).to_be_visible();expect(page.locator('#nav a[href="#logs"]')).to_have_count(0)
        setup=page.locator('form').filter(has=page.get_by_role('button',name='Настроить 2FA',exact=True));setup.get_by_label('Текущий пароль').fill('admin-password-12345');setup.get_by_role('button',name='Настроить 2FA').click()
        expect(page.get_by_role('dialog')).to_contain_text('JBSWY3DPEHPK3PXP');page.get_by_role('dialog').get_by_label('6-значный код').fill('123456');page.get_by_role('dialog').get_by_role('button',name='Подтвердить').click();expect(page.get_by_role('dialog')).to_contain_text('a'*32)
        assert 'a'*32 not in page.evaluate('JSON.stringify(localStorage)+JSON.stringify(sessionStorage)');page.get_by_role('button',name='Сохранено').click();expect(page.get_by_text('a'*32,exact=True)).to_have_count(0)
        page.get_by_role('button',name='Выйти',exact=True).click();expect(page.get_by_role('heading',name='Вход в CajeerLogs')).to_be_visible();login(page);expect(page.get_by_role('alert')).to_contain_text('Неверные')
        page.get_by_label('Код 2FA / recovery (если включено)').fill('123456');login(page);expect(page.get_by_role('heading',name='Настройки',exact=True)).to_be_visible();f.require_totp=False
        disable=page.locator('form').filter(has=page.get_by_role('button',name='Отключить 2FA',exact=True));disable.get_by_label('Текущий пароль').fill('admin-password-12345');disable.get_by_label('Код приложения или recovery-код').fill('123456');disable.get_by_role('button',name='Отключить 2FA').click();expect(page.get_by_role('heading',name='Вход в CajeerLogs')).to_be_visible();assert not errors,errors
        print('PASS mandatory enrollment, TOTP setup/enable, recovery code lifecycle, login factor and disable (fixture)')
        browser.close()

if __name__=='__main__': run()
