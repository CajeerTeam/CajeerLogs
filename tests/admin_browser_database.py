"""Real browser -> PHP HTTP -> PostgreSQL 17 E2E. No fixture API interception.

Requires CAJEER_TEST_DATABASE=1 and DB_* for a disposable PostgreSQL database,
PHP pdo_pgsql, and Playwright/Chromium. Creates and removes a random schema.
"""
import datetime
import json
import os
from pathlib import Path
import secrets
import subprocess
from playwright.sync_api import sync_playwright, expect
from admin_browser import ROOT, server, browser_for, nav, login, http


def run():
    if os.environ.get('CAJEER_TEST_DATABASE')!='1':
        raise SystemExit('Set CAJEER_TEST_DATABASE=1 and DB_* for an isolated PostgreSQL database.')
    schema='cl_admin_'+secrets.token_hex(8)
    subprocess.run(['php','tests/admin_database.php','setup',schema],cwd=ROOT,check=True)
    try:
        with server(DB_SCHEMA=schema, DB_CONNECTION='pgsql', INGEST_REQUIRE_HMAC='false', API_RATE_LIMIT_PER_MINUTE='600') as url, sync_playwright() as p:
            browser=browser_for(p);ctx=browser.new_context(accept_downloads=True);page=ctx.new_page();page.set_default_timeout(15000)
            errors=[];page.on('pageerror',lambda e: errors.append(str(e)))
            assert page.goto(url+'/admin/').status==200
            expect(page.get_by_role('heading',name='Вход в CajeerLogs')).to_be_visible()
            login(page);expect(page.get_by_role('heading',name='Обзор',exact=True)).to_be_visible();assert ctx.cookies()[0]['httpOnly']
            nav(page,'projects');form=page.locator('form').filter(has=page.get_by_role('button',name='Создать проект',exact=True));form.get_by_label('Название').fill('E2E Project');form.get_by_label('Slug').fill('e2e');form.get_by_role('button',name='Создать проект').click();expect(page.get_by_role('heading',name='E2E Project')).to_be_visible()
            project=page.get_by_label('Активный проект').input_value()
            form=page.locator('form').filter(has=page.get_by_role('button',name='Добавить источник'));form.get_by_label('Название').fill('Application');form.get_by_label('Slug').fill('app');form.get_by_role('button',name='Добавить источник').click();expect(page.get_by_text('Application',exact=True)).to_be_visible()
            nav(page,'tokens');form=page.locator('form').filter(has=page.get_by_label('logs:read'));form.get_by_label('Название').fill('E2E Writer');form.get_by_label('logs:write').check();form.get_by_role('button',name='Выпустить токен').click();expect(page.get_by_role('dialog')).to_be_visible();token=page.locator('#modal .secret').inner_text();assert token.startswith('cl_') and len(token)==67;page.locator('#close-modal').click()
            # A separate HTTP client sends real events, not the browser session cookie.
            now=datetime.datetime.now(datetime.timezone.utc).isoformat(timespec='milliseconds').replace('+00:00','Z')
            event={'timestamp':now,'source':'app','level':'error','message':'e2e database error <img src=x onerror="window.pwned=1">','context':{'error_code':'E2E','password':'should-be-redacted'},'tags':['e2e']}
            code,_,body=http(url+'/api/v1/ingest','POST',json.dumps(event).encode(),{'Content-Type':'application/json','Authorization':'Bearer '+token});assert code==201, (code,body)
            event_id=json.loads(body)['event_ids'][0]
            nav(page,'overview');expect(page.get_by_text('Всего событий')).to_be_visible();expect(page.locator('.metric').first.locator('strong')).to_have_text('1')
            nav(page,'logs');expect(page.get_by_text('e2e database error',exact=False)).to_be_visible();page.get_by_label('Поиск',exact=True).fill('database');page.get_by_label('Уровень',exact=True).select_option('error');page.get_by_role('button',name='Найти').click();expect(page.get_by_text('e2e database error',exact=False)).to_be_visible()
            page.get_by_role('button',name='Подробнее').click();expect(page.get_by_role('dialog')).to_contain_text(event_id);expect(page.get_by_role('dialog')).to_contain_text('[redacted]');assert page.locator('#modal img').count()==0;assert page.evaluate('window.pwned') is None;page.locator('#close-modal').click()
            with page.expect_download() as dl:page.get_by_role('button',name='CSV',exact=True).click()
            assert event_id in Path(dl.value.path()).read_text()
            nav(page,'users');form=page.locator('form').filter(has=page.get_by_role('button',name='Создать пользователя'));form.get_by_label('Email').fill('viewer@example.test');form.get_by_label('Пароль').fill('viewer-password-12345');form.get_by_role('button',name='Создать пользователя').click();row=page.get_by_role('row').filter(has_text='viewer@example.test');expect(row).to_be_visible();viewer=row.locator('code').inner_text()
            nav(page,'projects');page.get_by_label('UUID пользователя').fill(viewer);page.get_by_role('button',name='Добавить участника').click();expect(page.get_by_text('viewer@example.test',exact=True)).to_be_visible()
            nav(page,'tokens');page.get_by_role('button',name='Отозвать').click();page.get_by_role('dialog').get_by_role('button',name='Подтвердить').click();expect(page.get_by_text('Отозван',exact=True)).to_be_visible();assert http(url+'/api/v1/ingest','POST',json.dumps(event).encode(),{'Content-Type':'application/json','Authorization':'Bearer '+token})[0]==401
            nav(page,'system');expect(page.get_by_role('heading',name='Системный отчёт')).to_be_visible();expect(page.get_by_role('row').filter(has_text='PostgreSQL readiness')).to_contain_text('ok')
            page.get_by_role('button',name='Выйти',exact=True).click();expect(page.get_by_role('heading',name='Вход в CajeerLogs')).to_be_visible();assert ctx.request.get(url+'/api/v1/auth/me').status==401
            login(page,'viewer@example.test','viewer-password-12345');expect(page.get_by_role('heading',name='Обзор',exact=True)).to_be_visible();assert page.locator('#nav a[href="#users"]').count()==0;assert page.locator('#nav a[href="#tokens"]').count()==0
            nav(page,'logs');expect(page.get_by_text('e2e database error',exact=False)).to_be_visible();assert ctx.request.get(url+'/api/v1/users').status==403
            assert ctx.request.get(url+'/api/v1/logs?project_id='+project).json()['items'][0]['id']==event_id
            nav(page,'settings');page.get_by_role('button',name='Выйти на всех устройствах').click();page.get_by_role('dialog').get_by_role('button',name='Подтвердить').click();expect(page.get_by_role('heading',name='Вход в CajeerLogs')).to_be_visible();assert ctx.request.get(url+'/api/v1/auth/me').status==401
            assert not errors,errors
            browser.close();print('PASS real Chromium + PHP + PostgreSQL: login, project/source/token, ingest, explorer, export, user/membership, revoke, roles, logout')
    finally:
        subprocess.run(['php','tests/admin_database.php','cleanup',schema],cwd=ROOT,check=True)

if __name__=='__main__':run()
