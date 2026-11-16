import { roles, levels } from '../app/store.js?v=0.4.10';

const $ = id => document.getElementById(id);
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
function message(error) { return h('div', { class: 'error', role: 'alert' }, error instanceof Error ? error.message : String(error)); }
let noticeTimer;
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
function heading(title, desc, actions) { return h('div', { class: 'page-head' }, h('div', {}, h('h1', {}, title), h('p', { class: 'muted' }, desc)), actions); }
function needProject() { return card(empty('Нет доступного проекта. Создайте проект или попросите администратора предоставить доступ.'), h('a', { href: '#projects' }, 'Перейти к проектам')); }
function table(headers, rows) { return rows.length ? h('div', { class: 'table-wrap', tabindex: '0', role: 'region', 'aria-label': 'Таблица данных' }, h('table', {}, h('thead', {}, h('tr', {}, headers.map(x => h('th', { scope: 'col' }, x)))), h('tbody', {}, rows.map(row => h('tr', {}, row.map(cell => h('td', {}, cell))))))) : empty('Нет записей.'); }
async function copy(value) { try {
    await navigator.clipboard.writeText(value);
    notify('Скопировано.');
}
catch {
    notify('Копирование недоступно. Выделите и скопируйте текст вручную.');
} }

export { $, h, button, card, field, input, select, roleSelect, checkbox, empty, badge, text, date, enc, message, notify, modalEpoch, clearModal, modal, form, heading, needProject, table, copy };
