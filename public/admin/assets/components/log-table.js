import { state, active } from '../app/store.js?v=0.3.3';
import { $, h, button, empty, badge, text, date, enc, message, modalEpoch, modal, table, copy } from './ui.js?v=0.3.3';
import { get } from '../api/client.js?v=0.3.3';

function logTable(items, project) { if (!items.length)
    return empty('События не найдены.'); return h('div', { class: 'table-wrap', tabindex: '0', role: 'region', 'aria-label': 'Таблица событий' }, h('table', {}, h('thead', {}, h('tr', {}, ['Время (UTC)', 'Уровень', 'Источник / сервис', 'Сообщение', ''].map(t => h('th', { scope: 'col' }, t)))), h('tbody', {}, items.map(row => h('tr', {}, h('td', {}, date(row.timestamp)), h('td', {}, badge(row.level)), h('td', {}, text(row.source), h('div', { class: 'muted' }, text(row.service))), h('td', { class: 'message' }, String(row.message ?? '').slice(0, 600) + (String(row.message ?? '').length > 600 ? '…' : '')), h('td', {}, button('Подробнее', () => void detail(row.id, project)))))))); }
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

export { logTable, detail };
