import { state, active } from '../app/store.js?v=0.3.4';
import { $, h, button, empty, badge, text, date, enc, message, modalEpoch, modal, table, copy } from './ui.js?v=0.3.4';
import { get } from '../api/client.js?v=0.3.4';

import { visibleColumns, normalizeLayout, timestampLabel, highlightParts } from '../app/explorer.js?v=0.3.4';

function logTable(items, project, options = null) {
    if (!items.length) return empty('События не найдены.');
    if (!options) return h('div', { class: 'table-wrap', tabindex: '0', role: 'region', 'aria-label': 'Таблица событий' }, h('table', {}, h('thead', {}, h('tr', {}, ['Время (UTC)', 'Уровень', 'Источник / сервис', 'Сообщение', ''].map(t => h('th', { scope: 'col' }, t)))), h('tbody', {}, items.map(row => h('tr', {}, h('td', {}, date(row.timestamp)), h('td', {}, badge(row.level)), h('td', {}, text(row.source), h('div', { class: 'muted' }, text(row.service))), h('td', { class: 'message' }, String(row.message ?? '').slice(0, 600) + (String(row.message ?? '').length > 600 ? '…' : '')), h('td', {}, button('Подробнее', () => void detail(row.id, project))))))));
    const layout=normalizeLayout(options.layout), cols=visibleColumns(layout);
    const rows=items.map((row,i)=>{
        const open=button('Подробнее',()=>void detail(row.id,project,{items,index:i}), 'explorer-open', {'aria-label':'Подробнее', 'data-event-id':row.id});
        open.addEventListener('keydown',e=>{
            const offset=e.key==='ArrowDown'?1:e.key==='ArrowUp'?-1:0;
            if(!offset)return; e.preventDefault(); const buttons=[...wrap.querySelectorAll('.explorer-open')]; buttons[i+offset]?.focus();
        });
        return h('tr', {'data-event-id':row.id}, ...cols.map(c=>{
            const v=row[c.key], raw=Array.isArray(v)?v.join(', '):text(v);
            const time=['timestamp','received_at'].includes(c.key);
            const display=time?timestampLabel(v,layout.zone):raw;
            const truncated=display.length>2000?display.slice(0,2000)+'…':display;
            const contents=c.key==='level'?badge(v):c.key==='message'?highlightParts(truncated,options.query).map(p=>p.match?h('mark',{},p.text):p.text):truncated;
            const cell=h('td',{class:'explorer-cell col-'+c.key,title:time?String(v??''):null},h('div',{class:'explorer-value'},contents));
            if(['message','id','trace_id','request_id'].includes(c.key)&&v!==null&&v!==undefined&&v!=='') cell.append(button('Копировать',()=>void copy(String(v)),'explorer-copy',{'aria-label':'Копировать '+c.label}));
            return cell;
        }),h('td',{class:'explorer-action'},open));
    });
    const t=h('table',{class:'explorer-table '+layout.density+(layout.wrap?' wrap':' nowrap')},h('colgroup',{},cols.map(c=>{const el=h('col');el.style.width=c.width+'px';return el;}),h('col',{style:'width:115px'})),h('thead',{},h('tr',{},cols.map(c=>h('th',{scope:'col'},c.label+(['timestamp','received_at'].includes(c.key)?(layout.zone==='utc'?' (UTC)':' (лок.)'):''))),h('th',{scope:'col'},'Действия'))),h('tbody',{},rows));
    t.style.width=(cols.reduce((sum,c)=>sum+c.width,115))+'px';
    const wrap=h('div',{class:'table-wrap explorer-scroll',tabindex:'0',role:'region','aria-label':'Таблица событий'},t);
    return wrap;
}
async function detail(id, project, navigation = null) { const epoch = state.epoch; const loading=h('p',{role:'status'},'Загрузка…'); if(navigation?.continued && $('modal').open){$('modal-body').replaceChildren(loading);}else modal('Событие',loading); const dialogEpoch = modalEpoch; try {
    const r = await get('/logs/' + enc(id), { project_id: project }, state.controller.signal);
    if (!active(epoch) || !$('modal').open || dialogEpoch !== modalEpoch)
        return;
    const row = r.data;
    const adjacent = navigation ? h('div',{class:'actions explorer-detail-nav'},
        button('Предыдущее событие',()=>void detail(navigation.items[navigation.index-1].id,project,{...navigation,index:navigation.index-1,continued:true}),'',{disabled:navigation.index===0}),
        h('span',{class:'muted'},`${navigation.index+1} / ${navigation.items.length}`),
        button('Следующее событие',()=>void detail(navigation.items[navigation.index+1].id,project,{...navigation,index:navigation.index+1,continued:true}),'',{disabled:navigation.index===navigation.items.length-1})) : null;
    $('modal-body').replaceChildren(...(adjacent?[adjacent]:[]), h('div', { class: 'inline' }, badge(row.level), date(row.timestamp)), h('h3', {}, 'Сообщение'), h('pre', {}, text(row.message)), table(['Поле', 'Значение'], ['id', 'project_id', 'source_id', 'source', 'service', 'host', 'trace_id', 'request_id', 'received_at', 'tags'].map(k => [k, k === 'received_at' ? date(row[k]) : Array.isArray(row[k]) ? row[k].join(', ') : text(row[k])])), h('h3', {}, 'JSON context'), h('pre', {}, JSON.stringify(row.context, null, 2)), button('Скопировать JSON', () => void copy(JSON.stringify(row, null, 2))));
    if(navigation?.continued) (adjacent?.querySelector('button:not(:disabled)') || $('close-modal')).focus();
}
catch (e) {
    if (active(epoch) && $('modal').open && dialogEpoch === modalEpoch && e.name !== 'AbortError')
        $('modal-body').replaceChildren(message(e));
} }

export { logTable, detail };
