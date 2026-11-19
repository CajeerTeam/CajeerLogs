import { state, active, render } from '../app/store.js?v=0.9.8';
import { $, h, button, empty, badge, text, date, enc, message, modalEpoch, modal, table, copy } from './ui.js?v=0.9.8';
import { detail as inspectEvent } from './event-inspector.js?v=0.9.8';

import { visibleColumns, normalizeLayout, timestampLabel, highlightParts } from '../app/explorer.js?v=0.9.8';

function quickFilter(key,value,project) {
    if(state.project!==project||!state.user||state.route!=='logs')return;
    const f={...state.filters}; f[key]=String(value);
    const plural={level:'levels',source:'sources',source_id:'source_ids',service:'services',host:'hosts',trace_id:'trace_ids',request_id:'request_ids'}[key];if(plural)delete f[plural];
    // An explicit source selection replaces both slug and UUID restrictions.
    if(key==='source'){delete f.source_id;delete f.source_ids;}if(key==='source_id'){delete f.source;delete f.sources;}
    state.filters=f;state.logPosition=null;void render();
}
const filterable=['level','source','source_id','service','host','trace_id','request_id'];
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
            if(filterable.includes(c.key)&&v!==null&&v!==undefined&&v!=='') cell.append(button('Фильтр',()=>quickFilter(c.key,v,project),'explorer-copy',{'aria-label':'Фильтр по '+c.label}));
            return cell;
        }),h('td',{class:'explorer-action'},open));
    });
    const t=h('table',{class:'explorer-table '+layout.density+(layout.wrap?' wrap':' nowrap')},h('colgroup',{},cols.map(c=>{const el=h('col');el.style.width=c.width+'px';return el;}),h('col',{style:'width:115px'})),h('thead',{},h('tr',{},cols.map(c=>h('th',{scope:'col'},c.label+(['timestamp','received_at'].includes(c.key)?(layout.zone==='utc'?' (UTC)':' (лок.)'):''))),h('th',{scope:'col'},'Действия'))),h('tbody',{},rows));
    t.style.width=(cols.reduce((sum,c)=>sum+c.width,115))+'px';
    const wrap=h('div',{class:'table-wrap explorer-scroll',tabindex:'0',role:'region','aria-label':'Таблица событий'},t);
    return wrap;
}
function detail(id,project,navigation=null) { return inspectEvent(id,project,navigation,quickFilter); }
export { logTable, detail };
