"""Real browser -> PHP HTTP -> PostgreSQL E2E. No fixture API interception.

Requires CAJEER_TEST_DATABASE=1 and DB_* for a disposable PostgreSQL database,
PHP pdo_pgsql, and Playwright (Chromium, Firefox or WebKit). Creates and removes a random schema.
"""
import base64
import datetime
import hashlib
import hmac
import struct
import time
import json
import os
from pathlib import Path
import secrets
import subprocess
from playwright.sync_api import sync_playwright, expect
from admin_browser import ROOT, server, browser_for, nav, login, http


def database_error_rows(page):
    # The event is ingested twice (with the original and rotated token).
    # Restrict assertions to the actual log message column, not page-wide text.
    return page.locator('.explorer-table tbody .col-message .explorer-value').filter(
        has_text='e2e database error'
    )


def run():
    if os.environ.get('CAJEER_TEST_DATABASE')!='1':
        raise SystemExit('Set CAJEER_TEST_DATABASE=1 and DB_* for an isolated PostgreSQL database.')
    schema='cl_admin_'+secrets.token_hex(8)
    subprocess.run(['php','tests/admin_database.php','setup',schema],cwd=ROOT,check=True)
    try:
        with server(DB_SCHEMA=schema, DB_CONNECTION='pgsql', INGEST_REQUIRE_HMAC='false', API_RATE_LIMIT_PER_MINUTE='600', SECURITY_2FA_REQUIRED_FOR_ADMINS='false', SECURITY_REAUTH_SECONDS='3600', SECURITY_SESSION_IDLE_SECONDS='1800', SECURITY_TOTP_KEY=secrets.token_hex(32)) as url, sync_playwright() as p:
            browser=browser_for(p);ctx=browser.new_context(accept_downloads=True);page=ctx.new_page();page.set_default_timeout(15000)
            errors=[];page.on('pageerror',lambda e: errors.append(str(e)))
            assert page.goto(url+'/admin/').status==200
            expect(page.get_by_role('heading',name='Вход в CajeerLogs')).to_be_visible()
            login(page);expect(page.get_by_role('heading',name='Обзор',exact=True)).to_be_visible();assert ctx.cookies()[0]['httpOnly']
            # A real independent browser context produces a second cookie/session.
            other=browser.new_context(); other_page=other.new_page(); other_page.goto(url+'/admin/'); login(other_page)
            expect(other_page.get_by_role('heading',name='Обзор',exact=True)).to_be_visible()
            nav(page,'settings'); expect(page.get_by_text('Текущая сессия',exact=True)).to_be_visible()
            page.get_by_label('Отображаемое имя').fill('E2E Admin'); page.get_by_role('button',name='Сохранить профиль').click(); expect(page.locator('#account')).to_contain_text('E2E Admin')
            page.get_by_role('button',name='Завершить остальные сессии').click(); page.get_by_role('dialog').get_by_role('button',name='Подтвердить').click()
            expect(page.get_by_text('Страница 1 · 1 записей',exact=True)).to_be_visible()
            assert other.request.get(url+'/api/v1/auth/me').status==401; assert ctx.request.get(url+'/api/v1/auth/me').status==200; other.close()
            nav(page,'projects');form=page.locator('form').filter(has=page.get_by_role('button',name='Создать проект',exact=True));form.get_by_label('Название').fill('E2E Project');form.get_by_label('Slug').fill('e2e');form.get_by_role('button',name='Создать проект').click();expect(page.get_by_role('heading',name='E2E Project')).to_be_visible()
            project=page.get_by_label('Активный проект').input_value()
            form=page.locator('form').filter(has=page.get_by_role('button',name='Добавить источник'));form.get_by_label('Название').fill('Application');form.get_by_label('Slug').fill('app');form.get_by_role('button',name='Добавить источник').click();expect(page.get_by_text('Application',exact=True)).to_be_visible()
            nav(page,'tokens');page.get_by_label('Название сервиса').fill('E2E Backend');page.get_by_role('button',name='Создать сервисную учётную запись').click();acc=page.get_by_role('row').filter(has_text='E2E Backend');expect(acc).to_be_visible();account=acc.locator('code').inner_text();form=page.locator('form').filter(has=page.get_by_label('logs:read'));form.get_by_label('Название').fill('E2E Writer');form.get_by_label('Сервисная учётная запись (UUID, необязательно)').fill(account);form.get_by_label('logs:write').check();form.get_by_role('button',name='Выпустить токен').click();expect(page.get_by_role('dialog')).to_be_visible();token=page.locator('#modal .secret').inner_text();assert token.startswith('cl_') and len(token)==67;page.locator('#close-modal').click()
            # Real PostgreSQL-backed credential details, search and audit; no secret re-exposure.
            page.get_by_label('Поиск токена',exact=True).first.fill('E2E Writer');page.get_by_role('button',name='Найти токены',exact=True).first.click();page.get_by_role('button',name='Карточка токена',exact=True).click();dlg=page.get_by_role('dialog');expect(dlg).to_contain_text('E2E Writer');assert token not in dlg.inner_text();page.locator('#close-modal').click()
            page.get_by_role('button',name='Карточка сервиса').click();expect(dlg).to_contain_text('Активных токенов');dlg.get_by_role('button',name='Показать токены сервиса').click();expect(page.get_by_label('Фильтр UUID сервиса')).to_have_value(account)
            page.get_by_text('Журнал безопасности проекта',exact=True).click();expect(page.get_by_text('token.created',exact=True)).to_be_visible();assert token not in page.locator('main').inner_text()
            nav(page,'security');expect(page.get_by_role('heading',name='Безопасность и аудит',exact=True)).to_be_visible();expect(page.get_by_text('token.created',exact=True)).to_be_visible()
            page.get_by_text('Глобальный журнал безопасности',exact=True).click();expect(page.get_by_text('token.created',exact=True)).to_have_count(2)
            assert token not in page.locator('main').inner_text()
            # A separate HTTP client sends real events, not the browser session cookie.
            now=datetime.datetime.now(datetime.timezone.utc).isoformat(timespec='milliseconds').replace('+00:00','Z')
            event={'timestamp':now,'source':'app','level':'error','message':'e2e database error <img src=x onerror="window.pwned=1">','context':{'error_code':'E2E','password':'should-be-redacted'},'trace_id':'e2e-inspector-trace','request_id':'e2e-inspector-request','tags':['e2e']}
            code,_,body=http(url+'/api/v1/ingest','POST',json.dumps(event).encode(),{'Content-Type':'application/json','Authorization':'Bearer '+token});assert code==201, (code,body)
            event_id=json.loads(body)['event_ids'][0]
            nav(page,'overview');expect(page.get_by_text('Всего событий')).to_be_visible();expect(page.locator('.metric').first.locator('strong')).to_have_text('1')
            page.get_by_label('Период',exact=True).select_option('15m');page.get_by_role('button',name='Применить',exact=True).click();expect(page.locator('.metric').first.locator('strong')).to_have_text('1')
            page.get_by_role('button',name='Выбрать',exact=True).first.click();expect(page.get_by_label('Источник dashboard')).not_to_have_value('')
            page.get_by_role('button',name='Все логи',exact=True).click();expect(page.get_by_role('heading',name='Логи',exact=True)).to_be_visible();expect(database_error_rows(page).first).to_be_visible()
            page.get_by_role('button',name='Сбросить',exact=True).click()
            nav(page,'logs');expect(database_error_rows(page).first).to_be_visible();page.get_by_label('Поиск',exact=True).fill('database');page.get_by_label('Уровень',exact=True).select_option('error');page.get_by_role('button',name='Найти').click();expect(database_error_rows(page).first).to_be_visible()
            page.get_by_text('Сохранённые представления',exact=True).click();page.get_by_role('button',name='Сохранить текущее').click();page.get_by_label('Название',exact=True).fill('E2E saved search');page.get_by_role('dialog').get_by_role('button',name='Сохранить',exact=True).click()
            expect(page.get_by_label('Сохранённое представление').locator('option')).to_have_count(2); saved_id=page.get_by_label('Сохранённое представление').input_value();assert saved_id
            page.get_by_role('button',name='Сбросить',exact=True).click();page.get_by_text('Сохранённые представления',exact=True).click();page.get_by_label('Сохранённое представление').select_option(saved_id);page.get_by_role('button',name='Применить',exact=True).click();expect(page.get_by_label('Поиск',exact=True)).to_have_value('database');expect(database_error_rows(page).first).to_be_visible()
            page.get_by_role('button',name='Подробнее').click();expect(page.get_by_role('dialog')).to_contain_text(event_id);expect(page.get_by_role('dialog')).to_contain_text('[redacted]');assert page.locator('#modal img').count()==0;assert page.evaluate('window.pwned') is None;dlg=page.get_by_role('dialog');dlg.get_by_role('button',name='JSON context',exact=True).click();dlg.get_by_label('Поиск по JSON').fill('E2E');dlg.get_by_role('button',name='Найти в JSON').click();expect(dlg.locator('.json-result')).to_contain_text('/error_code');dlg.get_by_role('button',name='Связанные события').click();expect(dlg.get_by_text('Текущее',exact=True)).to_be_visible();dlg.get_by_role('button',name='Request ID',exact=True).click();expect(dlg.get_by_text('Текущее',exact=True)).to_be_visible();page.locator('#close-modal').click()
            with page.expect_download() as dl:page.get_by_role('button',name='CSV',exact=True).click()
            assert event_id in Path(dl.value.path()).read_text()
            # Exercise the actual signed cursors against PostgreSQL, not fixture pages.
            batch={'events':[{**event,'message':'e2e pagination batch','context':{'n':i}} for i in range(26)]}
            code,_,body=http(url+'/api/v1/ingest','POST',json.dumps(batch).encode(),{'Content-Type':'application/json','Authorization':'Bearer '+token});assert code==201,(code,body)
            page.get_by_label('Поиск',exact=True).fill('pagination');page.get_by_label('На странице',exact=True).select_option('25');page.get_by_label('Режим страницы',exact=True).select_option('snapshot');page.get_by_role('button',name='Найти').click()
            expect(page.get_by_text('Страница 1 · 25 записей',exact=True)).to_be_visible()
            page.get_by_text('Настроить таблицу',exact=True).click();page.get_by_label('Показать UUID события',exact=True).check();expect(page.get_by_role('columnheader',name='UUID события',exact=True)).to_be_visible();page.get_by_text('Настроить таблицу',exact=True).click()
            buttons=page.locator('.explorer-open');first_id=buttons.nth(0).get_attribute('data-event-id');second_id=buttons.nth(1).get_attribute('data-event-id');buttons.nth(0).focus();page.keyboard.press('ArrowDown');expect(buttons.nth(1)).to_be_focused();page.keyboard.press('Enter');expect(page.get_by_role('dialog')).to_contain_text(second_id)
            page.get_by_role('button',name='Предыдущее событие').click();expect(page.get_by_role('dialog')).to_contain_text(first_id);page.get_by_role('button',name='Следующее событие').click();expect(page.get_by_role('dialog')).to_contain_text(second_id);page.locator('#close-modal').click()
            page.get_by_role('button',name='Далее',exact=True).click();expect(page.get_by_text('Страница 2 · 1 записей',exact=True)).to_be_visible()
            nav(page,'overview');nav(page,'logs');expect(page.get_by_text('Страница 2 · 1 записей',exact=True)).to_be_visible();page.get_by_role('button',name='Назад',exact=True).click();expect(page.get_by_text('Страница 1 · 25 записей',exact=True)).to_be_visible()

            nav(page,'projects');page.get_by_role('button',name='Открыть источник').click();page.get_by_label('Название источника').fill('Application updated');page.get_by_role('dialog').get_by_label('Тип источника').select_option('nginx');page.get_by_role('button',name='Сохранить источник').click();expect(page.get_by_text('Application updated',exact=True)).to_be_visible()
            for action,status in [('Отключить источник','Отключён'),('Архивировать источник','Архивный'),('Восстановить источник','Отключён'),('Включить источник','Активный')]:
                page.get_by_role('button',name='Открыть источник').click();page.get_by_role('dialog').get_by_role('button',name=action).click();page.get_by_role('dialog').get_by_role('button',name='Подтвердить').click();expect(page.get_by_role('row').filter(has_text='Application updated')).to_contain_text(status)
            page.get_by_role('button',name='Открыть источник').click();page.get_by_label('Токен logs:write').fill(token);page.get_by_role('button',name='Отправить тестовый лог').click();expect(page.get_by_text('Тестовое событие сохранено:',exact=False)).to_be_visible();page.locator('#close-modal').click()
            nav(page,'users');form=page.locator('form').filter(has=page.get_by_role('button',name='Создать пользователя'));form.get_by_label('Email').fill('viewer@example.test');form.get_by_label('Пароль').fill('viewer-password-12345');form.get_by_role('button',name='Создать пользователя').click();row=page.get_by_role('row').filter(has_text='viewer@example.test');expect(row).to_be_visible();viewer=row.locator('code').inner_text()
            nav(page,'projects');page.get_by_label('UUID пользователя').fill(viewer);page.get_by_role('button',name='Добавить участника').click();expect(page.get_by_text('viewer@example.test',exact=True)).to_be_visible()
            nav(page,'tokens');page.get_by_role('button',name='Ротация',exact=True).click();dlg=page.get_by_role('dialog');dlg.get_by_label('Перекрытие, секунд (0 = немедленно)').fill('0');dlg.get_by_role('button',name='Выпустить замену').click();expect(dlg.locator('.secret')).to_be_visible();successor=dlg.locator('.secret').inner_text();assert successor!=token;page.locator('#close-modal').click();expect(page.get_by_text('Отозван',exact=True)).to_be_visible();assert http(url+'/api/v1/ingest','POST',json.dumps(event).encode(),{'Content-Type':'application/json','Authorization':'Bearer '+token})[0]==401;assert http(url+'/api/v1/ingest','POST',json.dumps(event).encode(),{'Content-Type':'application/json','Authorization':'Bearer '+successor})[0]==201;page.get_by_role('button',name='Отключить сервис').click();page.get_by_role('dialog').get_by_role('button',name='Подтвердить').click();expect(page.get_by_text('Отключена',exact=True)).to_be_visible();assert http(url+'/api/v1/ingest','POST',json.dumps(event).encode(),{'Content-Type':'application/json','Authorization':'Bearer '+successor})[0]==401
            nav(page,'projects');page.get_by_role('button',name='Мастер подключения').click();dlg=page.get_by_role('dialog');dlg.get_by_label('Название',exact=True).fill('Wizard app');dlg.get_by_label('Slug').fill('wizard-app');dlg.get_by_label('Тип подключения').select_option('server');dlg.get_by_role('button',name='Создать источник подключения').click()
            dlg.get_by_label('Режим подписи подключения').select_option('separate');dlg.get_by_role('button',name='Выпустить токен подключения').click();expect(dlg.locator('.secret')).to_have_count(2);wizard_token=dlg.locator('.secret').first.inner_text();assert wizard_token.startswith('cl_');assert dlg.locator('.secret').last.inner_text().startswith('sk_')
            dlg.get_by_role('button',name='Проверить подключение').click();expect(dlg).to_contain_text('Тестовое событие сохранено и найдено:');dlg.get_by_role('button',name='Открыть тестовое событие в логах').click();expect(page.get_by_text('CajeerLogs source connection test',exact=True)).to_be_visible()
            # The wizard credential must be write-only and restricted to its source.
            status,_,raw=http(url+'/api/v1/auth/token',headers={'Authorization':'Bearer '+wizard_token});assert status==200;meta=json.loads(raw)['token'];assert meta['scopes']==['logs:write'] and meta['source_id'] and meta['project_id']==project
            assert http(url+'/api/v1/ingest','POST',json.dumps(event).encode(),{'Content-Type':'application/json','Authorization':'Bearer '+wizard_token})[0] in (401,403)
            page.get_by_role('button',name='Сбросить',exact=True).click()
            # Exercise actual RBAC changes in a second browser context without logging out.
            api=ctx.request; csrf=api.get(url+'/api/v1/auth/me').json()['csrf_token']; headers={'X-CSRF-Token':csrf,'Origin':url}
            # Switching projects must discard source/query/cursor state rather than leak the old workspace.
            resp=api.post(url+'/api/v1/projects',headers=headers,data={'name':'Other Project','slug':'other-project'});assert resp.status==201;other_project=resp.json()['id']
            page.get_by_label('Поиск',exact=True).fill('database');page.get_by_role('button',name='Найти').click();expect(database_error_rows(page).first).to_be_visible()
            page.get_by_role('button',name='Обновить проекты').click();expect(page.get_by_label('Активный проект').locator('option[value="'+other_project+'"]')).to_have_count(1)
            page.get_by_role('button',name='Быстрый переход').click();dlg=page.get_by_role('dialog');dlg.get_by_label('Поиск проекта для перехода').fill(other_project);dlg.get_by_role('button',name='Найти проекты').click();dlg.get_by_label('Открыть раздел').select_option('logs');dlg.get_by_role('button',name='Other Project',exact=False).click();expect(page.get_by_label('Активный проект')).to_have_value(other_project);expect(page.get_by_label('Поиск',exact=True)).to_have_value('');expect(database_error_rows(page)).to_have_count(0)
            page.get_by_label('Активный проект').select_option(project);expect(database_error_rows(page).first).to_be_visible()
            resp=api.post(url+'/api/v1/users',headers=headers,data={'email':'operator@example.test','password':'operator-password-12345','role':'operator'});assert resp.status==201;operator=resp.json()['id']
            membership=url+'/api/v1/projects/'+project+'/members/'+operator
            assert api.put(membership,headers=headers,data={'role':'operator'}).status==204
            operator_ctx=browser.new_context();op=operator_ctx.new_page();op.goto(url+'/admin/');login(op,'operator@example.test','operator-password-12345');expect(op.get_by_role('heading',name='Обзор',exact=True)).to_be_visible()
            nav(op,'security');expect(op.get_by_text('token.created',exact=True).first).to_be_visible();assert op.locator('#nav a[href="#users"]').count()==0;assert op.get_by_text('Глобальный журнал безопасности',exact=True).count()==0
            # Real UI role creation and assignment to an already logged-in operator.
            nav(page,'rbac');page.get_by_role('button',name='Новая роль',exact=True).click();dlg=page.get_by_role('dialog');dlg.get_by_label('Название роли').fill('E2E restricted operator');dlg.get_by_role('button',name='Создать роль',exact=True).click()
            role_row=page.get_by_role('row').filter(has_text='E2E restricted operator');expect(role_row).to_be_visible();custom_id=role_row.locator('code').inner_text()
            nav(page,'users');page.get_by_label('Поиск пользователей').fill('operator@example.test');page.get_by_role('button',name='Найти пользователей',exact=True).click();page.get_by_role('row').filter(has_text='operator@example.test').get_by_role('button',name='Карточка',exact=True).click()
            page.get_by_role('button',name='Назначить глобальную роль',exact=True).click();dlg.get_by_label('Пользовательская роль').select_option(custom_id);dlg.get_by_role('button',name='Сохранить назначение').click();expect(page.get_by_text(custom_id,exact=True).first).to_be_visible()
            op.bring_to_front();op.evaluate("window.dispatchEvent(new Event('focus'))");expect(op.locator('#nav a[href="#tokens"]')).to_have_count(0);nav(op,'logs');expect(database_error_rows(op).first).to_be_visible();expect(op.get_by_role('button',name='CSV',exact=True)).to_have_count(0)
            assert operator_ctx.request.get(url+'/api/v1/logs/export?project_id='+project+'&format=json').status==403
            assert api.put(url+'/api/v1/users/'+operator+'/role-assignment',headers=headers,data={'role_id':None,'expected_role_id':custom_id}).status==204
            op.evaluate("window.dispatchEvent(new Event('focus'))");expect(op.locator('#nav a[href="#tokens"]')).to_have_count(1)
            assert api.put(membership,headers=headers,data={'role':'viewer'}).status==204
            nav(op,'tokens');expect(op.get_by_text('Нет разрешений на токены или сервисные аккаунты в этом проекте.')).to_be_visible();assert operator_ctx.request.get(url+'/api/v1/projects/'+project+'/tokens').status==403
            nav(op,'logs');expect(database_error_rows(op).first).to_be_visible();op.get_by_role('button',name='Подробнее').first.click();expect(op.get_by_role('dialog')).to_be_visible();assert api.delete(membership,headers=headers).status==204
            op.bring_to_front();op.evaluate("window.dispatchEvent(new Event('focus'))");expect(op.get_by_label('Активный проект')).to_be_disabled();expect(op.get_by_role('dialog')).not_to_be_visible();expect(database_error_rows(op)).to_have_count(0);assert operator_ctx.request.get(url+'/api/v1/logs?project_id='+project).status==403;operator_ctx.close()
            nav(page,'system');expect(page.get_by_role('heading',name='Системный отчёт')).to_be_visible();expect(page.get_by_role('row').filter(has_text='PostgreSQL readiness')).to_contain_text('ok')
            page.get_by_role('button',name='Выйти',exact=True).click();expect(page.get_by_role('heading',name='Вход в CajeerLogs')).to_be_visible();assert ctx.request.get(url+'/api/v1/auth/me').status==401
            login(page,'viewer@example.test','viewer-password-12345');expect(page.get_by_role('heading',name='Обзор',exact=True)).to_be_visible();assert page.locator('#nav a[href="#users"]').count()==0;assert page.locator('#nav a[href="#tokens"]').count()==0
            nav(page,'logs');expect(database_error_rows(page).first).to_be_visible();assert ctx.request.get(url+'/api/v1/users').status==403
            assert event_id in [e['id'] for e in ctx.request.get(url+'/api/v1/logs?project_id='+project).json()['items']]
            nav(page,'settings');page.get_by_role('button',name='Выйти на всех устройствах').click();page.get_by_role('dialog').get_by_role('button',name='Подтвердить').click();expect(page.get_by_role('heading',name='Вход в CajeerLogs')).to_be_visible();assert ctx.request.get(url+'/api/v1/auth/me').status==401
            # Real local TOTP enrollment, QR and recovery-code login against PostgreSQL.
            login(page);expect(page.get_by_role('heading',name='Настройки',exact=True)).to_be_visible();setup=page.locator('form').filter(has=page.get_by_role('button',name='Настроить 2FA',exact=True));setup.get_by_label('Текущий пароль').fill('admin-password-12345');setup.get_by_role('button',name='Настроить 2FA').click()
            dlg=page.get_by_role('dialog');expect(dlg.get_by_role('img',name='QR-код настройки TOTP')).to_be_visible();secret=dlg.locator('pre').inner_text().strip();key=base64.b32decode(secret+'='*((-len(secret))%8));digest=hmac.new(key,struct.pack('>Q',int(time.time())//30),hashlib.sha1).digest();offset=digest[-1]&15;otp=f'{(struct.unpack(">I",digest[offset:offset+4])[0]&0x7fffffff)%1000000:06d}'
            dlg.get_by_label('6-значный код').fill(otp);dlg.get_by_role('button',name='Подтвердить').click();expect(dlg.get_by_text('Сохраните recovery-коды',exact=True)).to_be_visible();codes=dlg.locator('pre').inner_text().splitlines();assert len(codes)==10;dlg.get_by_role('button',name='Сохранено').click()
            page.get_by_role('button',name='Выйти',exact=True).click();expect(page.get_by_role('heading',name='Вход в CajeerLogs')).to_be_visible();login(page);expect(page.get_by_role('heading',name='Подтверждение 2FA')).to_be_visible();page.get_by_label('Код 2FA / recovery (если включено)').fill(codes[0]);page.get_by_role('button',name='Подтвердить вход').click();expect(page.get_by_role('heading',name='Настройки',exact=True)).to_be_visible()
            confirm=page.locator('form').filter(has=page.get_by_role('button',name='Подтвердить личность',exact=True));confirm.get_by_label('Текущий пароль').fill('admin-password-12345');confirm.get_by_label('Код 2FA или recovery').fill(codes[1]);confirm.get_by_role('button',name='Подтвердить личность').click();expect(page.get_by_text('Подтверждение действительно до',exact=False)).to_be_visible();assert confirm.get_by_label('Текущий пароль').input_value()==''
            page.get_by_role('button',name='Выйти',exact=True).click();expect(page.get_by_role('heading',name='Вход в CajeerLogs')).to_be_visible();login(page);expect(page.get_by_role('heading',name='Подтверждение 2FA')).to_be_visible();page.get_by_label('Код 2FA / recovery (если включено)').fill(codes[0]);page.get_by_role('button',name='Подтвердить вход').click();expect(page.get_by_role('alert')).to_be_visible();assert ctx.request.get(url+'/api/v1/auth/me').status==401
            assert not errors,errors
            browser.close();print('PASS real '+os.environ.get('CAJEER_BROWSER_ENGINE','chromium')+' + PHP + PostgreSQL: login, project/source/token, ingest, explorer, export, user/membership, revoke, roles, audit, TOTP/recovery, logout')
    finally:
        subprocess.run(['php','tests/admin_database.php','cleanup',schema],cwd=ROOT,check=True)

if __name__=='__main__':run()
