// Same-origin administration client. All untrusted values are inserted as text, never HTML.
const $ = id => document.getElementById(id);
const apiPrefix = document.querySelector('meta[name=cajeer-api]').content;
const version = document.querySelector('meta[name=cajeer-version]').content;
const roles = { administrator: 'Администратор', operator: 'Оператор', viewer: 'Наблюдатель' };
const levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
const state = { user: null, csrf: null, projects: [], project: null, projectCursor: null, route: 'overview', epoch: 0, authEpoch: 0, controller: null, filters: null };
const names = { overview: 'Обзор', logs: 'Логи', projects: 'Проекты и источники', tokens: 'API-токены', users: 'Пользователи', system: 'Система', settings: 'Настройки' };
const errors = { unauthorized: 'Неверные учётные данные или сессия истекла.', forbidden: 'Недостаточно прав для операции.', csrf_failed: 'Сессия изменилась. Обновите страницу и повторите операцию.', service_unavailable: 'Сервис или база данных временно недоступны.', rate_limited: 'Слишком много запросов. Повторите позже.', last_administrator: 'Нельзя отключить последнего администратора.', conflict: 'Конфликт с существующими данными.', invalid_password: 'Текущий пароль неверен.', not_found: 'Объект не найден.' };
class ApiError extends Error {
    constructor(status, data) { super((errors[data?.error] || data?.message || `HTTP ${status}`) + (data?.request_id ? ` (ID: ${data.request_id})` : '')); this.status = status; this.data = data; }
}
function h(tag, attrs = {}, ...children) { const n = document.createElement(tag); for (const [k, v] of Object.entries(attrs)) {
    if (v === null || v === undefined || v === false)
        continue;
    if (k === 'class')
        n.className = v;
    else if (k === 'on')
        for (const [event, fn] of Object.entries(v))
            n.addEventListener(event, fn);
    else if (k === 'value')
        n.value = v;
    else if (v === true)
        n.setAttribute(k, '');
    else
        n.setAttribute(k, String(v));
} for (const c of children.flat(Infinity)) {
    if (c === null || c === undefined || c === false)
        continue;
    n.append(c instanceof Node ? c : document.createTextNode(String(c)));
} return n; }
const button = (text, fn, cls = '', attrs = {}) => h('button', { type: 'button', class: cls, on: { click: fn }, ...attrs }, text);
const card = (...children) => h('section', { class: 'card' }, children);
const field = (label, input) => { input.setAttribute('aria-label', label); return h('label', { class: 'field' }, h('span', {}, label), input); };
const input = (name, attrs = {}) => h('input', { name, ...attrs });
function select(name, options, value, attrs = {}) { const el = h('select', { name, ...attrs }, options.map(([v, t]) => h('option', { value: v }, t))); el.value = value ?? options[0]?.[0] ?? ''; return el; }
const roleSelect = (value = 'viewer') => select('role', Object.entries(roles), value);
const checkbox = (name, label, checked = false) => h('label', { class: 'check' }, h('input', { type: 'checkbox', name, checked }), label);
const empty = message => h('div', { class: 'empty' }, message);
const badge = value => h('span', { class: `badge ${levels.includes(value) ? 'level-' + value : ''}` }, value);
const text = value => value === null || value === undefined || value === '' ? '—' : String(value);
const date = value => value ? new Date(value).toLocaleString('ru-RU', { timeZone: 'UTC' }) + ' UTC' : '—';
const enc = encodeURIComponent;
const projectPath = () => `/projects/${enc(state.project)}`;
const admin = () => state.user?.role === 'administrator';
const manage = () => admin() || state.user?.role === 'operator';
function message(error) { return h('div', { class: 'error', role: 'alert' }, error instanceof Error ? error.message : String(error)); }
let noticeTimer;
let tokenIssuing = false;
let sessionChecking = false;
function notify(msg) { $('notice').textContent = msg; $('notice').hidden = false; clearTimeout(noticeTimer); noticeTimer = setTimeout(() => { $('notice').hidden = true; $('notice').textContent = ''; }, 5000); }
let modalEpoch = 0;
function clearModal() { modalEpoch++; const d = $('modal'); if (d.open)
    d.close(); $('modal-body').replaceChildren(); $('modal-title').textContent = ''; }
$('close-modal').addEventListener('click', clearModal);
$('modal').addEventListener('close', () => { if (!$('modal').open) {
    modalEpoch++;
    $('modal-body').replaceChildren();
    $('modal-title').textContent = '';
} });
function modal(title, content) { clearModal(); $('modal-title').textContent = title; $('modal-body').append(content); $('modal').showModal(); }
function active(epoch) { return epoch === state.epoch && !!state.user; }
function clearSession(reason) { state.authEpoch++; state.epoch++; state.controller?.abort(); state.user = null; state.csrf = null; state.projects = []; state.project = null; state.projectCursor = null; state.filters = null; clearModal(); $('notice').hidden = true; $('notice').textContent = ''; renderLogin(reason); }
async function request(path, { method = 'GET', body, query, signal, raw = false, login = false, allowStatus = [] } = {}) {
    const url = new URL(apiPrefix + path, location.origin);
    if (url.origin !== location.origin)
        throw new Error('Недопустимый адрес API.');
    for (const [k, v] of Object.entries(query || {}))
        if (v !== '' && v !== null && v !== undefined)
            url.searchParams.set(k, String(v));
    const headers = { Accept: raw ? '*/*' : 'application/json' };
    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(body);
    }
    if (!['GET', 'HEAD'].includes(method) && !login && state.csrf)
        headers['X-CSRF-Token'] = state.csrf;
    const authEpoch = state.authEpoch;
    const r = await fetch(url, { method, headers, body, signal, credentials: 'same-origin', cache: 'no-store', redirect: 'error' });
    if (!r.ok && !allowStatus.includes(r.status)) {
        let d;
        try {
            d = await r.json();
        }
        catch {
            d = {};
        }
        const err = new ApiError(r.status, d);
        if (r.status === 401 && !login && authEpoch === state.authEpoch)
            clearSession('Сессия истекла. Войдите снова.');
        throw err;
    }
    if (authEpoch !== state.authEpoch)
        throw new DOMException('Session changed', 'AbortError');
    if (raw)
        return r;
    if (r.status === 204)
        return null;
    return r.json();
}
function get(path, query, signal) { return request(path, { query, signal }); }
function write(path, method, body) { return request(path, { method, body }); }
function currentRoute() { const r = location.hash.slice(1); return Object.hasOwn(names, r) ? r : 'overview'; }
function navigate(route) { if (location.hash === '#' + route)
    void render();
else
    location.hash = route; }
function permitted(route) { if (state.enrollmentRequired) return route === 'settings'; return !(['users', 'system'].includes(route) && !admin()) && !(route === 'tokens' && !manage()); }
function themeButton() { return button(document.documentElement.dataset.theme === 'dark' ? 'Светлая тема' : 'Тёмная тема', e => { const theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark'; document.documentElement.dataset.theme = theme; try {
    localStorage.setItem('cajeer-theme', theme);
}
catch { } e.currentTarget.textContent = theme === 'dark' ? 'Светлая тема' : 'Тёмная тема'; header(); }); }
try {
    if (localStorage.getItem('cajeer-theme') === 'dark')
        document.documentElement.dataset.theme = 'dark';
}
catch { }
function header() { const a = $('account'); a.replaceChildren(themeButton()); if (state.user) {
    a.prepend(h('span', { class: 'who', title: state.user.email }, state.user.email + ' · ' + roles[state.user.role]));
    a.append(button('Выйти', async (e) => { const b = e.currentTarget; b.disabled = true; try {
        await write('/auth/logout', 'POST');
        clearSession('Вы вышли из системы.');
    }
    catch (err) {
        if (state.user)
            notify(err.message);
    }
    finally {
        b.disabled = false;
    } }));
} }
function navigation() { header(); $('sidebar').hidden = !state.user; const nav = $('nav'); nav.replaceChildren(); if (!state.user)
    return; for (const [route, label] of Object.entries(names))
    if (permitted(route))
        nav.append(h('a', { href: '#' + route, 'aria-current': state.route === route ? 'page' : null }, label)); }
function form(submitLabel, fields, handler, { cls = 'form-grid', after = [] } = {}) {
    const err = h('div', { 'aria-live': 'polite' });
    const submit = h('button', { type: 'submit', class: 'primary' }, submitLabel);
    const f = h('form', {}, h('div', { class: cls }, fields), h('div', { class: 'actions' }, submit, after), err);
    f.addEventListener('submit', async (e) => { e.preventDefault(); if (submit.disabled)
        return; err.replaceChildren(); submit.disabled = true; f.setAttribute('aria-busy', 'true'); try {
        await handler(new FormData(f), f);
    }
    catch (ex) {
        if (ex.name !== 'AbortError')
            err.replaceChildren(message(ex));
    }
    finally {
        submit.disabled = false;
        f.removeAttribute('aria-busy');
    } });
    return f;
}
function renderLogin(reason = '') {
    navigation();
    $('main').replaceChildren(h('section', { class: 'card login' }, h('h1', {}, 'Вход в CajeerLogs'), h('p', { class: 'muted' }, 'Войдите с учётной записью, созданной установщиком.'), reason ? h('p', { role: 'status' }, reason) : null, form('Войти', [
        field('Email', input('email', { type: 'email', required: true, autocomplete: 'username', maxlength: 255 })), field('Пароль', input('password', { type: 'password', required: true, autocomplete: 'current-password', maxlength: 1024 })), field('Код 2FA / recovery (если включено)', input('otp', { autocomplete: 'one-time-code', maxlength: 32 }))
    ], async (d, f) => { const credentials = { email: String(d.get('email')), password: String(d.get('password')) }; if (d.get('otp')) credentials.otp=String(d.get('otp')).trim(); const res = await request('/auth/login', { method: 'POST', body: credentials, login: true }); f.reset(); await startSession(res); }, { cls: 'stack' }), h('p', { class: 'muted small' }, 'CajeerLogs ' + version)));
}
async function startSession(res) { state.authEpoch++; state.user = res.user; state.enrollmentRequired = Boolean(res.enrollment_required); state.csrf = res.csrf_token; state.projects = []; state.project = null; state.projectCursor = null; state.filters = null; await render(); }
async function bootstrap() { try {
    await startSession(await get('/auth/me'));
}
catch (e) {
    if (e.status !== 401) {
        renderLogin('Не удалось проверить сессию: ' + e.message);
    }
} }
async function revalidateSession() { if (!state.user || sessionChecking || document.hidden)
    return; const authEpoch = state.authEpoch; sessionChecking = true; try {
    const res = await get('/auth/me');
    if (authEpoch !== state.authEpoch)
        return;
    if (res.user.id !== state.user.id || res.user.role !== state.user.role) {
        clearSession('Учётная запись изменилась. Войдите снова.');
        return;
    }
    state.csrf = res.csrf_token; if (state.enrollmentRequired !== Boolean(res.enrollment_required)) { state.enrollmentRequired=Boolean(res.enrollment_required); void render(); }
}
catch (e) { /* 401 is handled centrally; transient connectivity errors must not imply logout. */ }
finally {
    sessionChecking = false;
} }
window.addEventListener('focus', () => void revalidateSession());
setInterval(() => void revalidateSession(), 60000);
async function loadProjects(signal, more = false) { const result = await get('/projects', { limit: 100, cursor: more ? state.projectCursor : null }, signal); if (signal?.aborted)
    return; state.projects = more ? [...state.projects, ...result.items.filter(p => !state.projects.some(x => x.id === p.id))] : result.items; state.projectCursor = result.next_cursor; if (!state.project || !state.projects.some(p => p.id === state.project))
    state.project = state.projects[0]?.id ?? null; }
function projectSelector() { const picker = select('active_project', state.projects.map(p => [p.id, `${p.name} (${p.slug})`]), state.project, { 'aria-label': 'Активный проект' }); picker.disabled = !state.projects.length; picker.addEventListener('change', () => { state.project = picker.value; state.filters = null; void render(); }); return h('div', { class: 'project-strip' }, h('strong', {}, 'Проект:'), picker, state.projectCursor ? button('Ещё проекты', async (e) => { const b = e.currentTarget; const signal = state.controller.signal; b.disabled = true; try {
    await loadProjects(signal, true);
    if (!signal.aborted)
        void render();
}
catch (err) {
    if (err.name !== 'AbortError')
        notify(err.message);
}
finally {
    b.disabled = false;
} }) : null, h('a', { href: '#projects' }, 'Управление проектами'), button('Обновить проекты', async (e) => { const b = e.currentTarget; const signal = state.controller.signal; b.disabled = true; try {
    await loadProjects(signal);
    if (!signal.aborted)
        void render();
}
catch (err) {
    if (err.name !== 'AbortError')
        notify(err.message);
}
finally {
    b.disabled = false;
} })); }
function heading(title, desc, actions) { return h('div', { class: 'page-head' }, h('div', {}, h('h1', {}, title), h('p', { class: 'muted' }, desc)), actions); }
function needProject() { return card(empty('Нет доступного проекта. Создайте проект или попросите администратора предоставить доступ.'), h('a', { href: '#projects' }, 'Перейти к проектам')); }
async function render() {
    if (!state.user)
        return;
    state.controller?.abort();
    clearModal();
    const controller = new AbortController();
    state.controller = controller;
    const signal = controller.signal;
    const epoch = ++state.epoch;
    state.route = currentRoute();
    if (!permitted(state.route)) {
        state.route = state.enrollmentRequired ? 'settings' : 'overview';
        history.replaceState(null, '', '#'+state.route);
    }
    navigation();
    const main = $('main');
    main.replaceChildren(h('p', { role: 'status' }, 'Загрузка…'));
    try {
        if (['overview', 'logs', 'projects', 'tokens'].includes(state.route) && !state.projects.length)
            await loadProjects(signal);
        if (!active(epoch) || signal.aborted)
            return;
        const view = await pages[state.route](signal, epoch);
        if (!active(epoch) || signal.aborted)
            return;
        main.replaceChildren(view);
    }
    catch (e) {
        if (e.name === 'AbortError' || !active(epoch))
            return;
        main.replaceChildren(heading(names[state.route], 'Не удалось загрузить раздел.'), message(e), button('Повторить', () => void render()));
    }
}
document.querySelector('.skip').addEventListener('click', e => { e.preventDefault(); $('main').focus(); });
window.addEventListener('hashchange', () => { if (state.user)
    void render(); });
// Revalidate on browser back/forward cache restoration; do not keep an old authenticated view.
window.addEventListener('pageshow', e => { if (e.persisted) {
    clearSession();
    void bootstrap();
} });
function table(headers, rows) { return rows.length ? h('div', { class: 'table-wrap' }, h('table', {}, h('thead', {}, h('tr', {}, headers.map(x => h('th', { scope: 'col' }, x)))), h('tbody', {}, rows.map(row => h('tr', {}, row.map(cell => h('td', {}, cell))))))) : empty('Нет записей.'); }
function logTable(items, project) { if (!items.length)
    return empty('События не найдены.'); return h('div', { class: 'table-wrap' }, h('table', {}, h('thead', {}, h('tr', {}, ['Время (UTC)', 'Уровень', 'Источник / сервис', 'Сообщение', ''].map(t => h('th', { scope: 'col' }, t)))), h('tbody', {}, items.map(row => h('tr', {}, h('td', {}, date(row.timestamp)), h('td', {}, badge(row.level)), h('td', {}, text(row.source), h('div', { class: 'muted' }, text(row.service))), h('td', { class: 'message' }, String(row.message ?? '').slice(0, 600) + (String(row.message ?? '').length > 600 ? '…' : '')), h('td', {}, button('Подробнее', () => void detail(row.id, project)))))))); }
async function detail(id, project) { const epoch = state.epoch; modal('Событие', h('p', { role: 'status' }, 'Загрузка…')); const dialogEpoch = modalEpoch; try {
    const r = await get('/logs/' + enc(id), { project_id: project }, state.controller.signal);
    if (!active(epoch) || !$('modal').open || dialogEpoch !== modalEpoch)
        return;
    const row = r.data;
    $('modal-body').replaceChildren(h('div', { class: 'inline' }, badge(row.level), date(row.timestamp)), h('h3', {}, 'Сообщение'), h('pre', {}, text(row.message)), table(['Поле', 'Значение'], ['id', 'project_id', 'source_id', 'source', 'service', 'host', 'trace_id', 'request_id', 'received_at', 'tags'].map(k => [k, k === 'received_at' ? date(row[k]) : Array.isArray(row[k]) ? row[k].join(', ') : text(row[k])])), h('h3', {}, 'JSON context'), h('pre', {}, JSON.stringify(row.context, null, 2)), button('Скопировать JSON', () => void copy(JSON.stringify(row, null, 2))));
}
catch (e) {
    if (active(epoch) && $('modal').open && dialogEpoch === modalEpoch && e.name !== 'AbortError')
        $('modal-body').replaceChildren(message(e));
} }
async function copy(value) { try {
    await navigator.clipboard.writeText(value);
    notify('Скопировано.');
}
catch {
    notify('Копирование недоступно. Выделите и скопируйте текст вручную.');
} }
function metrics(stats) { const vals = [['Всего событий', stats.total], ['Ошибки и выше', levels.slice(4).reduce((sum, l) => sum + Number(stats.by_level[l] || 0), 0)], ['Источники' + (stats.sources_truncated ? ' (не менее)' : ''), stats.by_source.length], ['Предупреждения', stats.by_level.warning || 0]]; return h('div', { class: 'metric-grid' }, vals.map(([label, v]) => h('div', { class: 'metric' }, h('span', { class: 'muted' }, label), h('strong', {}, Number(v).toLocaleString('ru-RU'))))); }
function bars(title, entries) { const max = Math.max(1, ...entries.map(x => Number(x[1]))); return card(h('h2', {}, title), entries.length ? entries.map(([label, n]) => h('div', { class: 'bar-row' }, h('span', {}, label), h('progress', { max, value: n, 'aria-label': label }), h('span', {}, n))) : empty('Нет данных.')); }
function svg(tag, attrs = {}, value) { const el = document.createElementNS('http://www.w3.org/2000/svg', tag); for (const [k, v] of Object.entries(attrs))
    el.setAttribute(k, String(v)); if (value !== undefined)
    el.textContent = String(value); return el; }
function timeline(stats) {
    if (!stats.buckets.length)
        return card(h('h2', {}, 'События по часам (UTC)'), empty('Нет событий за период.'));
    let data = stats.buckets;
    const start = Date.parse(stats.from), end = Date.parse(stats.to);
    if (Number.isFinite(start) && Number.isFinite(end) && end > start && end - start <= 86400000) {
        const byHour = new Map(data.map(x => [Date.parse(x.timestamp), x.count]));
        data = [];
        for (let t = Math.floor(start / 3600000) * 3600000; t < end; t += 3600000)
            data.push({ timestamp: new Date(t).toISOString(), count: byHour.get(t) || 0 });
    }
    const width = 720, height = 220, left = 58, right = 14, base = 172, top = 16, max = Math.ceil(Math.max(1, ...data.map(x => Number(x.count) || 0)) / 4) * 4, step = (width - left - right) / data.length;
    const chart = svg('svg', { viewBox: `0 0 ${width} ${height}`, role: 'img', 'aria-label': 'Почасовое количество событий в UTC', class: 'hour-chart' });
    chart.append(svg('title', {}, 'Почасовое количество событий в UTC'));
    for (let i = 0; i <= 4; i++) {
        const y = base - (base - top) * i / 4;
        chart.append(svg('line', { x1: left, y1: y, x2: width - right, y2: y, class: 'grid-line' }), svg('text', { x: left - 8, y: y + 4, 'text-anchor': 'end', class: 'axis-label' }, Math.round(max * i / 4).toLocaleString('ru-RU')));
    }
    data.forEach((b, i) => { const count = Math.max(0, Number(b.count) || 0), barHeight = (base - top) * count / max, x = left + i * step + step * .13; const bar = svg('rect', { x, y: base - barHeight, width: step * .74, height: barHeight, rx: 2, class: 'chart-bar' }); bar.append(svg('title', {}, `${date(b.timestamp)}: ${count}`)); chart.append(bar); if (i % Math.max(1, Math.ceil(data.length / 8)) === 0)
        chart.append(svg('text', { x: x + step * .37, y: base + 20, 'text-anchor': 'middle', class: 'axis-label' }, b.timestamp.slice(11, 16))); });
    return card(h('h2', {}, 'События по часам (UTC)'), h('div', { class: 'chart-scroll' }, chart), h('details', {}, h('summary', {}, 'Данные графика'), table(['Час (UTC)', 'События'], data.map(b => [date(b.timestamp), b.count]))));
}
async function overview(signal) { const root = h('div', {}, heading('Обзор', 'События за последние 24 часа.', button('Обновить', () => void render())), projectSelector()); if (!state.project) {
    root.append(needProject());
    return root;
} const to = new Date(); const from = new Date(to.getTime() - 86400000); const q = { project_id: state.project, from: from.toISOString(), to: to.toISOString() }; const [stats, logs] = await Promise.all([get('/logs/stats', { ...q, interval: 'hour' }, signal), get('/logs', { ...q, limit: 10 }, signal)]); root.append(metrics(stats), timeline(stats), h('div', { class: 'chart-grid' }, bars('По уровням', Object.entries(stats.by_level)), bars('Источники', stats.by_source.slice(0, 10).map(x => [x.source, x.count]))), card(h('div', { class: 'page-head' }, h('h2', {}, 'Последние события'), h('a', { href: '#logs' }, 'Все логи')), logTable(logs.items, state.project))); return root; }
function defaultFilters() { return { limit: '50' }; }
function logFilters(sources) { const f = state.filters || defaultFilters(); const val = k => f[k] || ''; const fields = [field('Поиск', input('q', { value: val('q'), maxlength: 512, placeholder: 'database OR timeout' })), field('Уровень', select('level', [['', 'Все'], ...levels.map(l => [l, l])], val('level'))), field('Источник', input('source', { value: val('source'), list: 'log-sources', maxlength: 100, placeholder: 'slug' })), field('Сервис', input('service', { value: val('service'), maxlength: 255 })), field('С (UTC)', input('from', { type: 'datetime-local', value: val('from') })), field('До (UTC)', input('to', { type: 'datetime-local', value: val('to') })), field('На странице', select('limit', [['25', '25'], ['50', '50'], ['100', '100']], val('limit') || '50'))]; const extra = h('details', {}, h('summary', {}, 'Дополнительные фильтры'), h('div', { class: 'form-grid' }, field('Источник UUID', input('source_id', { value: val('source_id'), list: 'log-source-ids', maxlength: 36 })), field('Host', input('host', { value: val('host'), maxlength: 255 })), field('Trace ID', input('trace_id', { value: val('trace_id'), maxlength: 128 })), field('Request ID', input('request_id', { value: val('request_id'), maxlength: 128 })), field('Тег', input('tag', { value: val('tag'), maxlength: 128 })), field('JSON context', h('textarea', { name: 'context', maxlength: 4096, placeholder: '{"error_code":"DB_DOWN"}' }, val('context'))))); if (['source_id', 'host', 'trace_id', 'request_id', 'tag', 'context'].some(k => val(k)))
    extra.open = true; const filter = form('Найти', fields, async (d) => { const next = Object.fromEntries(d); for (const key of ['from', 'to'])
    if (next[key]) {
        if (!Number.isFinite(Date.parse(next[key] + 'Z')))
            throw new Error('Некорректная дата.');
    } if (next.from && next.to && next.from >= next.to)
    throw new Error('Начало периода должно быть раньше конца.'); if (next.context) {
    let obj;
    try {
        obj = JSON.parse(next.context);
    }
    catch {
        throw new Error('JSON context: некорректный JSON.');
    }
    if (!obj || Array.isArray(obj) || typeof obj !== 'object')
        throw new Error('JSON context должен быть объектом.');
} state.filters = next; await render(); }, { after: [button('Сбросить', () => { state.filters = null; void render(); })] }); filter.insertBefore(extra, filter.querySelector('.actions')); filter.append(h('datalist', { id: 'log-sources' }, sources.map(s => h('option', { value: s.slug }, s.name))), h('datalist', { id: 'log-source-ids' }, sources.map(s => h('option', { value: s.id }, s.name)))); return filter; }
function queryFilters() { const f = { ...(state.filters || defaultFilters()), project_id: state.project }; for (const k of ['from', 'to'])
    if (f[k])
        f[k] = new Date(f[k] + 'Z').toISOString(); return f; }
function pagedList({ path, query = {}, headers, rows, signal, epoch, renderItems, exportPage = false }) {
    const box = h('div', {}, h('p', { role: 'status' }, 'Загрузка…'));
    let cursors = [null], index = 0, next = null, busy = false, pageData = null;
    const project = state.project;
    async function load(target = 0) {
        if (busy)
            return;
        busy = true;
        const previous = box.querySelectorAll('button');
        previous.forEach(x => x.disabled = true);
        try {
            const data = await get(path, { ...query, cursor: cursors[target] }, signal);
            if (!active(epoch) || signal.aborted)
                return;
            index = target;
            next = data.next_cursor;
            pageData = data;
            const items = data.items;
            const controls = h('div', { class: 'pager' }, h('span', { class: 'muted', role: 'status' }, `Страница ${index + 1} · ${items.length} записей`), h('div', { class: 'actions' }, button('Назад', () => void load(index - 1), '', { disabled: index === 0 }), button('Далее', () => { cursors[index + 1] = next; cursors = cursors.slice(0, index + 2); void load(index + 1); }, '', { disabled: !next })));
            box.replaceChildren(renderItems ? renderItems(items, project) : table(headers, items.map(rows)), controls);
            if (exportPage) {
                controls.prepend(h('div', { class: 'actions' }, button('CSV', e => void download('csv', e.currentTarget)), button('JSON', e => void download('json', e.currentTarget))));
            }
        }
        catch (e) {
            if (e.name !== 'AbortError' && active(epoch)) {
                if (pageData && e.status !== 403) {
                    box.querySelector('.error')?.remove();
                    box.prepend(message(e));
                }
                else {
                    pageData = null;
                    box.replaceChildren(message(e), button('Повторить', () => void load(target)));
                }
            }
        }
        finally {
            busy = false;
            previous.forEach(x => { if (x.isConnected)
                x.disabled = false; });
            if (pageData) {
                const p = box.querySelector('.pager .actions:last-child'); /* newly rendered controls have their own disabled states */
                if (p && p.children.length === 2) {
                    p.children[0].disabled = index === 0;
                    p.children[1].disabled = !next;
                }
            }
        }
    }
    async function download(format, b) { b.disabled = true; try {
        const r = await request('/logs/export', { query: { ...query, cursor: cursors[index], format }, signal, raw: true });
        const blob = await r.blob();
        if (!active(epoch))
            return;
        const url = URL.createObjectURL(blob);
        const a = h('a', { href: url, download: 'cajeerlogs.' + format });
        document.body.append(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
        notify('Экспорт текущей страницы подготовлен.');
    }
    catch (e) {
        if (e.name !== 'AbortError' && active(epoch))
            notify(e.message);
    }
    finally {
        b.disabled = false;
    } }
    void load();
    return box;
}
async function logs(signal, epoch) { const root = h('div', {}, heading('Логи', 'Поиск, фильтры и просмотр сохранённых событий.'), projectSelector()); if (!state.project) {
    root.append(needProject());
    return root;
} const src = await get(projectPath() + '/sources', { limit: 100 }, signal); root.append(card(logFilters(src.items)), card(h('h2', {}, 'События'), pagedList({ path: '/logs', query: queryFilters(), signal, epoch, renderItems: logTable, exportPage: true }))); return root; }
const nameFields = () => [field('Название', input('name', { required: true, maxlength: 255 })), field('Slug', input('slug', { required: true, maxlength: 100, pattern: '[a-z0-9]+(-[a-z0-9]+)*', placeholder: 'my-project' }))];
const nameBody = d => ({ name: String(d.get('name')).trim(), slug: String(d.get('slug')).trim() });
function mutationSuccess(epoch, label) { notify(label); if (active(epoch))
    return render(); }
function confirmAction(title, description, action) { const epoch = state.epoch; let dialogEpoch; const err = h('div', { 'aria-live': 'polite' }); const ok = button('Подтвердить', async () => { ok.disabled = true; err.replaceChildren(); try {
    await action();
    if (active(epoch) && dialogEpoch === modalEpoch)
        clearModal();
}
catch (e) {
    if (e.name !== 'AbortError')
        err.replaceChildren(message(e));
}
finally {
    ok.disabled = false;
} }, 'danger'); modal(title, h('div', {}, h('p', {}, description), h('div', { class: 'actions' }, ok, button('Отмена', clearModal)), err)); dialogEpoch = modalEpoch; }
async function projects(signal, epoch) {
    const root = h('div', {}, heading('Проекты и источники', 'Управление доступными проектами и подключёнными приложениями.'), projectSelector());
    if (admin())
        root.append(card(h('h2', {}, 'Создать проект'), form('Создать проект', nameFields(), async (d) => { const r = await write('/projects', 'POST', nameBody(d)); if (!active(epoch))
            return; const created = await get('/projects/' + enc(r.id)); if (!active(epoch))
            return; state.projects.push(created); state.project = created.id; state.filters = null; await mutationSuccess(epoch, 'Проект создан.'); })));
    if (!state.project) {
        root.append(needProject());
        return root;
    }
    const path = projectPath();
    const project = state.projects.find(p => p.id === state.project);
    root.append(card(h('h2', {}, project.name), h('p', { class: 'muted' }, 'Slug: ' + project.slug), h('code', {}, project.id), h('div', { class: 'actions' }, h('a', { href: '#logs' }, 'Открыть логи'), manage() ? h('a', { href: '#tokens' }, 'Управление токенами') : null)));
    const sources = pagedList({ path: path + '/sources', query: { limit: 50 }, signal, epoch, headers: ['Название', 'Slug', 'UUID', 'Создан'], rows: r => [r.name, r.slug, h('code', {}, r.id), date(r.created_at)] });
    root.append(card(h('h2', {}, 'Источники'), manage() ? form('Добавить источник', nameFields(), async (d) => { await write(path + '/sources', 'POST', nameBody(d)); await mutationSuccess(epoch, 'Источник создан.'); }) : null, sources));
    if (admin()) {
        root.append(card(h('h2', {}, 'Участники проекта'), h('p', { class: 'muted' }, 'Укажите UUID пользователя из раздела «Пользователи». Администраторы имеют доступ ко всем проектам независимо от членства.'), form('Добавить участника', [field('UUID пользователя', input('user', { required: true, pattern: '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}' }))], async (d) => { await write(path + '/members/' + enc(String(d.get('user'))), 'PUT'); await mutationSuccess(epoch, 'Доступ предоставлен.'); }), pagedList({ path: path + '/members', query: { limit: 50 }, signal, epoch, headers: ['Email', 'Роль', 'UUID', ''], rows: r => [r.email, roles[r.role] || r.role, h('code', {}, r.id), button('Убрать доступ', () => confirmAction('Удалить участника', `Убрать ${r.email} из проекта?`, async () => { await write(path + '/members/' + enc(r.id), 'DELETE'); await mutationSuccess(epoch, 'Доступ удалён.'); }), 'danger')] })));
    }
    return root;
}
function tokenSection(path, sources, signal, epoch, system = false) { const fields = [field('Название', input('name', { required: true, maxlength: 255 })), field('Срок, дней', input('days', { type: 'number', min: 1, max: 365, step: 1, value: 30, required: true }))]; if (!system) {
    fields.push(field('Источник (UUID, необязательно)', input('source_id', { list: 'token-sources', maxlength: 36, placeholder: 'Весь проект' })), h('div', {}, h('strong', { class: 'small' }, 'Разрешения'), checkbox('read', 'logs:read', true), checkbox('write', 'logs:write')));
} const formEl = form('Выпустить токен', fields, async (d) => { const scopes = system ? ['system:read'] : [...(d.has('read') ? ['logs:read'] : []), ...(d.has('write') ? ['logs:write'] : [])]; if (!scopes.length)
    throw new Error('Выберите хотя бы одно разрешение.'); const body = { name: String(d.get('name')).trim(), scopes, expires_in: Number(d.get('days')) * 86400 }; if (!system && d.get('source_id'))
    body.source_id = String(d.get('source_id')).trim(); if (tokenIssuing)
    throw new Error('Дождитесь завершения выпуска предыдущего токена.'); tokenIssuing = true; const authEpoch = state.authEpoch; try {
    const result = await write(path, 'POST', body);
    if (authEpoch !== state.authEpoch || !state.user)
        return;
    if (active(epoch))
        await render();
    if (authEpoch !== state.authEpoch || !state.user)
        return;
    modal('Новый API-токен', h('div', {}, h('p', {}, 'Сохраните секрет сейчас. Сервер не покажет его повторно.'), h('pre', { class: 'secret' }, result.token), h('div', { class: 'actions' }, button('Скопировать токен', () => void copy(result.token), 'primary'), button('Закрыть', clearModal))));
}
finally {
    tokenIssuing = false;
} }); if (!system)
    formEl.append(h('datalist', { id: 'token-sources' }, sources.map(s => h('option', { value: s.id }, s.name + ' (' + s.slug + ')')))); return card(h('h2', {}, system ? 'Системные токены' : 'Токены проекта'), h('p', { class: 'muted' }, system ? 'Только scope system:read.' : 'Токен может читать и/или записывать логи всего проекта либо одного источника.'), formEl, pagedList({ path, query: { limit: 50 }, signal, epoch, headers: ['Название', 'Scopes', 'Источник', 'Истекает', 'Последнее использование', 'Статус', ''], rows: r => [r.name, (r.scopes || []).join(', '), r.source_id ? h('code', {}, r.source_id) : 'Все', date(r.expires_at), date(r.last_used_at), badge(r.revoked_at ? 'Отозван' : r.expires_at && Date.parse(r.expires_at) <= Date.now() ? 'Истёк' : 'Активен'), r.revoked_at ? '—' : button('Отозвать', () => confirmAction('Отозвать токен', `Токен «${r.name}» перестанет работать немедленно.`, async () => { await write(path + '/' + enc(r.id), 'DELETE'); await mutationSuccess(epoch, 'Токен отозван.'); }), 'danger')] })); }
async function tokens(signal, epoch) { const root = h('div', {}, heading('API-токены', 'Выпуск и отзыв сервисных credentials.'), projectSelector()); if (state.project) {
    const path = projectPath();
    const sources = await get(path + '/sources', { limit: 100 }, signal);
    root.append(tokenSection(path + '/tokens', sources.items, signal, epoch));
}
else
    root.append(needProject()); if (admin())
    root.append(tokenSection('/system/tokens', [], signal, epoch, true)); return root; }
function userFields(user = null) { return [field('Email', input('email', { type: 'email', required: true, maxlength: 255, value: user?.email ?? '' })), field('Роль', roleSelect(user?.role)), field(user ? 'Новый пароль (необязательно)' : 'Пароль', input('password', { type: 'password', minlength: 12, maxlength: 1024, required: !user, autocomplete: 'new-password' })), user ? checkbox('blocked', 'Заблокирован', !!user.blocked_at) : null]; }
function editUser(user, epoch) { modal('Редактировать пользователя', h('div', {}, h('p', { class: 'muted' }, 'Изменение пользователя отзывает все его сессии.'), form('Сохранить', userFields(user), async (d) => { const body = { email: String(d.get('email')), role: String(d.get('role')), blocked: d.has('blocked') }; if (d.get('password'))
    body.password = String(d.get('password')); await write('/users/' + enc(user.id), 'PATCH', body); if (user.id === state.user?.id) {
    clearSession('Учётная запись изменена. Войдите снова.');
    return;
} clearModal(); await mutationSuccess(epoch, 'Пользователь обновлён.'); }))); }
async function users(signal, epoch) { return h('div', {}, heading('Пользователи', 'Управление ролями, паролями и блокировкой.'), card(h('h2', {}, 'Создать пользователя'), form('Создать пользователя', userFields(), async (d) => { await write('/users', 'POST', { email: String(d.get('email')), password: String(d.get('password')), role: String(d.get('role')) }); await mutationSuccess(epoch, 'Пользователь создан.'); })), card(h('h2', {}, 'Список пользователей'), pagedList({ path: '/users', query: { limit: 50 }, signal, epoch, headers: ['Email', 'Роль', 'UUID', 'Статус', ''], rows: r => [r.email, roles[r.role] || r.role, h('code', {}, r.id), badge(r.blocked_at ? 'Заблокирован' : 'Активен'), button('Изменить', () => editUser(r, epoch))] }))); }
async function system(signal) { const root = h('div', {}, heading('Система', 'Диагностика runtime, PostgreSQL и миграций.', button('Обновить', () => void render()))); const [health, ready, report] = await Promise.all([get('/health', {}, signal), fetch('/ready', { credentials: 'same-origin', cache: 'no-store', signal }).then(async (r) => ({ status: r.status, data: await r.json() })), request('/system/report', { signal, raw: true, allowStatus: [503] }).then(r => r.json())]); root.append(card(h('h2', {}, 'Состояние'), table(['Проверка', 'Результат'], [['Runtime', badge(health.status)], ['PostgreSQL readiness', badge(ready.status === 200 ? 'ok' : 'down')], ['Версия', health.version], ['Среда', report.environment || '—'], ['PHP', report.php || '—'], ['Общий статус', badge(report.status || 'down')]])), card(h('h2', {}, 'Системный отчёт'), h('pre', {}, JSON.stringify(report, null, 2)), button('Скопировать отчёт', () => void copy(JSON.stringify(report, null, 2))))); return root; }
function twoFactorCard() {
    const pw=()=>field('Текущий пароль',input('password',{type:'password',required:true,autocomplete:'current-password',maxlength:1024}));
    const otp=()=>field('Код приложения или recovery-код',input('otp',{required:true,autocomplete:'one-time-code',maxlength:32}));
    const section=card(h('h2',{},'Двухфакторная аутентификация'),h('p',{},state.user.two_factor_enabled?'TOTP включён.':'TOTP не включён.'));
    if (state.user.two_factor_enabled) {
        section.append(form('Отключить 2FA',[pw(),otp()],async d=>{await write('/auth/2fa/disable','POST',{password:String(d.get('password')),otp:String(d.get('otp')).trim()});clearSession('2FA отключена. Войдите снова.');}));
    } else {
        section.append(form('Настроить 2FA',[pw()],async (d,f)=>{
            const data=await write('/auth/2fa/setup','POST',{password:String(d.get('password'))}); f.reset();
            modal('Настройка TOTP',h('div',{},h('p',{},'Добавьте ключ вручную в приложение-аутентификатор. SHA-1, 6 цифр, 30 секунд. Ключ действителен 10 минут.'),h('pre',{},data.secret),form('Подтвердить',[field('6-значный код',input('otp',{required:true,inputmode:'numeric',autocomplete:'one-time-code',maxlength:6,pattern:'[0-9]{6}'}))],async d=>{
                const result=await write('/auth/2fa/enable','POST',{otp:String(d.get('otp'))});
                await startSession(result);
                modal('Сохраните recovery-коды',h('div',{},h('p',{},'Каждый код действует один раз. Сохраните их в защищённом месте. Они больше не будут показаны.'),h('pre',{},result.recovery_codes.join('\n')),button('Сохранено',()=>clearModal())));
            })));
        }));
    }
    return section;
}
async function settings() { if (state.enrollmentRequired) return h('div',{},heading('Настройка 2FA','Для администратора необходим второй фактор.'),twoFactorCard()); return h('div', {}, heading('Настройки', 'Настройки учётной записи и интерфейса.'),twoFactorCard(), card(h('h2', {}, 'Профиль'), table(['Поле', 'Значение'], [['Email', state.user.email], ['Роль', roles[state.user.role]], ['UUID', state.user.id]]), h('div', { class: 'actions' }, themeButton())), card(h('h2', {}, 'Сменить пароль'), h('p', { class: 'muted' }, 'После смены пароля все сессии будут отозваны.'), form('Сменить пароль', [field('Текущий пароль', input('current_password', { type: 'password', required: true, autocomplete: 'current-password', maxlength: 1024 })), field('Новый пароль', input('password', { type: 'password', required: true, minlength: 12, maxlength: 1024, autocomplete: 'new-password' })), field('Повторите новый пароль', input('repeat', { type: 'password', required: true, minlength: 12, maxlength: 1024, autocomplete: 'new-password' }))], async (d) => { if (d.get('password') !== d.get('repeat'))
    throw new Error('Пароли не совпадают.'); await write('/auth/password', 'POST', { current_password: String(d.get('current_password')), password: String(d.get('password')) }); clearSession('Пароль изменён. Войдите снова.'); })), card(h('h2', {}, 'Сессии'), h('p', {}, 'Завершить все сессии этой учётной записи, включая текущую.'), button('Выйти на всех устройствах', () => confirmAction('Отозвать все сессии', 'Все активные сессии будут завершены.', async () => { await write('/auth/logout-all', 'POST'); clearSession('Все сессии завершены.'); }), 'danger'))); }
const pages = { overview, logs, projects, tokens, users, system, settings };
void bootstrap();
