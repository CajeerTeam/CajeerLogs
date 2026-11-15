import { levels, state, projectPath, active, render } from '../app/store.js?v=0.3.3';
import { h, button, card, field, input, select, date, message, notify, form, heading, needProject } from '../components/ui.js?v=0.3.3';
import { projectSelector } from '../app/projects.js?v=0.3.3';
import { logTable } from '../components/log-table.js?v=0.3.3';
import { request, get } from '../api/client.js?v=0.3.3';

function defaultFilters() { return { limit: '50', consistency: 'live' }; }
function logFilters(sources) { const f = state.filters || defaultFilters(); const val = k => f[k] || ''; const fields = [field('Поиск', input('q', { value: val('q'), maxlength: 512, placeholder: 'database OR timeout' })), field('Уровень', select('level', [['', 'Все'], ...levels.map(l => [l, l])], val('level'))), field('Источник', input('source', { value: val('source'), list: 'log-sources', maxlength: 100, placeholder: 'slug' })), field('Сервис', input('service', { value: val('service'), maxlength: 255 })), field('С (UTC)', input('from', { type: 'datetime-local', step: '0.001', value: val('from') })), field('До (UTC)', input('to', { type: 'datetime-local', step: '0.001', value: val('to') })), field('На странице', select('limit', [['25', '25'], ['50', '50'], ['100', '100']], val('limit') || '50')), field('Режим страницы', select('consistency', [['live', 'Живой поиск'], ['snapshot', 'Ограниченный снимок']], val('consistency') || 'live'))]; const extra = h('details', {}, h('summary', {}, 'Дополнительные фильтры'), h('div', { class: 'form-grid' }, field('Источник UUID', input('source_id', { value: val('source_id'), list: 'log-source-ids', maxlength: 36 })), field('Host', input('host', { value: val('host'), maxlength: 255 })), field('Trace ID', input('trace_id', { value: val('trace_id'), maxlength: 128 })), field('Request ID', input('request_id', { value: val('request_id'), maxlength: 128 })), field('Тег', input('tag', { value: val('tag'), maxlength: 128 })), field('JSON context', h('textarea', { name: 'context', maxlength: 4096, placeholder: '{"error_code":"DB_DOWN"}' }, val('context'))), field('Режим поиска', select('q_mode', [['', 'Web (по умолчанию)'], ['plain', 'Все слова'], ['phrase', 'Фраза']], val('q_mode'))), ...[['levels','Уровни (JSON-массив)','["error","warning"]'],['sources','Источники (JSON-массив)','["app","worker"]'],['services','Сервисы (JSON-массив)','["api","worker"]'],['hosts','Hosts (JSON-массив)','["host1","host2"]'],['trace_ids','Trace IDs (JSON-массив)','["trace-1","trace-2"]'],['request_ids','Request IDs (JSON-массив)','["req-1","req-2"]'],['source_ids','UUID источников (JSON-массив)','["UUID"]'],['tags_all','Все теги (JSON-массив)','["db","prod"]'],['tags_any','Любой тег (JSON-массив)','["db","queue"]'],['context_path','JSON-путь context','["nested","region"]']].map(([key,title,example]) => field(title,input(key,{value:val(key),maxlength:4096,placeholder:example}))), field('Значение JSON-пути (scalar)',input('context_equals',{value:val('context_equals'),maxlength:4096,placeholder:'"eu"'})))); if (['source_id', 'host', 'trace_id', 'request_id', 'tag', 'context','q_mode','levels','sources','services','hosts','source_ids','trace_ids','request_ids','tags_all','tags_any','context_path','context_equals'].some(k => val(k)))
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
} for (const key of ['levels','sources','services','hosts','source_ids','trace_ids','request_ids','tags_all','tags_any','context_path']) {
    if (!next[key]) continue;
    let values; try { values = JSON.parse(next[key]); } catch { throw new Error('Некорректный JSON-массив: ' + key); }
    if (!Array.isArray(values) || !values.length || values.length > (key === 'context_path' ? 8 : 16) || values.some(v => typeof v !== 'string' || !v.trim())) throw new Error('Ожидается непустой массив строк: ' + key);
    next[key] = JSON.stringify(values);
} if (next.q_mode && !next.q) throw new Error('Для режима поиска введите текст.');
if (next.context_path && (!next.from || !next.to)) throw new Error('Для JSON-пути укажите начало и конец периода.');
if (next.context_equals) {
    if (!next.context_path) throw new Error('Укажите JSON-путь.');
    let v; try { v = JSON.parse(next.context_equals); } catch { throw new Error('Некорректное JSON-значение.'); }
    if (v !== null && typeof v === 'object') throw new Error('Значение JSON-пути должно быть скаляром.');
    // Keep the original text; JavaScript may lose decimal precision.
} state.filters = next; state.logPosition = null; await render(); }, { after: [button('Сбросить', () => { state.filters = null; state.logPosition = null; void render(); })] }); filter.insertBefore(extra, filter.querySelector('.actions')); filter.append(h('datalist', { id: 'log-sources' }, sources.map(s => h('option', { value: s.slug }, s.name))), h('datalist', { id: 'log-source-ids' }, sources.map(s => h('option', { value: s.id }, s.name)))); return filter; }
function queryFilters() { const f = { ...(state.filters || defaultFilters()), project_id: state.project }; for (const k of ['from', 'to'])
    if (f[k])
        f[k] = new Date(f[k] + 'Z').toISOString(); return f; }
function logPager(query, signal, epoch) {
    const box = h('div', {}, h('p', { role: 'status' }, 'Загрузка…'));
    const signature = JSON.stringify(query), project = state.project;
    const saved = state.logPosition?.signature === signature ? state.logPosition : null;
    let current = null, busy = false, index = saved?.index || 0;
    async function load(cursor = null, target = 0) {
        if (busy) return;
        busy = true; box.setAttribute('aria-busy', 'true');
        const controls = [...box.querySelectorAll('button')];
        const disabled = controls.map(b => b.disabled); controls.forEach(b => b.disabled = true);
        try {
            const data = await get('/logs', { ...query, cursor }, signal);
            if (!active(epoch) || signal.aborted) return;
            current = data; index = Math.max(0, target);
            state.logPosition = { signature, cursor: data.page_cursor, index };
            const info = data.snapshot_at ? 'Снимок по received_at: ' + date(data.snapshot_at) : 'Живой поиск: новые события могут менять страницы';
            const actions = h('div', { class: 'actions' },
                button('Назад', () => void load(data.prev_cursor, index - 1), '', { disabled: !data.prev_cursor }),
                button('Далее', () => void load(data.next_cursor, index + 1), '', { disabled: !data.next_cursor }),
                button('Начать заново', () => void load(null, 0)));
            const exports = h('div', { class: 'actions' }, button('CSV', e => void download('csv', e.currentTarget)), button('JSON', e => void download('json', e.currentTarget)));
            box.replaceChildren(logTable(data.items, project), h('p', {class:'muted small'}, info),
                h('div', {class:'pager'}, h('span', {class:'muted', role:'status'}, `Страница ${index + 1} · ${data.items.length} записей`), exports, actions));
        } catch (e) {
            if (e.name === 'AbortError' || !active(epoch)) return;
            if (e.status === 401 || e.status === 403) { current = null; state.logPosition = null; box.replaceChildren(message(e)); }
            else {
                box.querySelector('.error')?.remove();
                if (!current) box.replaceChildren();
                box.prepend(h('div', {class:'error'}, message(e), button('Начать новый поиск', () => void load(null, 0))));
            }
        } finally {
            busy = false; box.removeAttribute('aria-busy');
            controls.forEach((b,i) => { if (b.isConnected) b.disabled = disabled[i]; });
        }
    }
    async function download(format,b) {
        if (!current || busy) return;
        const page = current; b.disabled = true;
        try {
            const r = await request('/logs/export', {query:{...query,cursor:page.page_cursor,format}, signal,raw:true,ifMatch:'"'+page.page_digest+'"'});
            if (r.headers.get('X-Page-Digest') !== page.page_digest) throw new Error('Страница изменилась. Обновите поиск перед экспортом.');
            const blob = await r.blob();
            if (!active(epoch) || signal.aborted || current !== page) return;
            const url = URL.createObjectURL(blob), a = h('a',{href:url,download:'cajeerlogs.'+format});
            document.body.append(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(url),1000);
            notify('Экспорт текущей страницы подготовлен.');
        } catch(e) { if(e.name!=='AbortError' && active(epoch)) notify(e.message); }
        finally { b.disabled = false; }
    }
    void load(saved?.cursor || null,index);
    return box;
}
async function logs(signal, epoch) { const root = h('div', {}, heading('Логи', 'Поиск, фильтры и просмотр сохранённых событий.'), projectSelector()); if (!state.project) {
    root.append(needProject());
    return root;
} const src = await get(projectPath() + '/sources', { limit: 100 }, signal); root.append(card(logFilters(src.items)), card(h('h2', {}, 'События'), logPager(queryFilters(), signal, epoch))); return root; }

export { logs };
