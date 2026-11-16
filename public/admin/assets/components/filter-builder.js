import { levels, state, active, render, projectPath } from '../app/store.js?v=0.4.9';
import { h, button, field, input, select, form, message } from './ui.js?v=0.4.9';
import { get } from '../api/client.js?v=0.4.9';
import { listFields, defaultFilters, readList, validateFilters, preset, removeFilter } from '../app/filters.js?v=0.4.9';

// A list of independently editable values; hidden field is solely the existing API wire format.
function listEditor(key,label,raw,max,options=[],ordered=false) {
 let values=readList(raw); const hidden=input(key,{type:'hidden',value:raw||''});
 const entry=key==='levels'?select('add_'+key,[['','Выберите уровень'],...levels.map(v=>[v,v])],'',{'aria-label':'Добавить '+label}):input('add_'+key,{maxlength:max,placeholder:ordered?'Ключ или индекс':'Значение', 'aria-label':'Добавить '+label,list:options.length?'filter-options-'+key:null});
 entry.removeAttribute('name'); const chips=h('div',{class:'filter-chips'}), err=h('span',{class:'muted small',role:'status'});
 const draw=()=>{hidden.value=values.length?JSON.stringify(values):'';chips.replaceChildren(...values.map((v,i)=>h('span',{class:'filter-chip'},v,button('×',()=>{values.splice(i,1);draw();entry.focus();},'',{'aria-label':`Удалить ${label}: ${v}`}))));};
 const add=()=>{const v=entry.value;if(!v.trim())return;if(values.length>=(ordered?8:16)){err.textContent='Достигнут лимит значений.';return;}if(!ordered&&values.includes(v)){err.textContent='Значение уже добавлено.';return;}values.push(v);entry.value='';err.textContent='';draw();entry.focus();};
 entry.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();add();}});
 const addButton=button('Добавить',add,'',{'aria-label':'Добавить значение: '+label});
 const suggestions=h('datalist',{id:'filter-options-'+key},options.map(([v,t])=>h('option',{value:v},t)));
 draw();return {node:h('div',{class:'filter-list'},h('span',{class:'filter-label'},label),h('div',{class:'filter-entry'},entry,addButton),suggestions,chips,hidden,err),entry,suggestions,add};
}
function sourcePicker(editor,key,signal,epoch) {
 const search=input('source_search_'+key,{placeholder:'Имя или slug',maxlength:255,'aria-label':'Поиск источников '+key});search.removeAttribute('name');
 const results=h('div',{class:'filter-source-results'}),status=h('span',{class:'muted small',role:'status'});let cursor=null,busy=false,appliedSearch='';
 const load=async(more=false)=>{if(busy)return;busy=true;status.textContent='Загрузка…';try{if(!more)appliedSearch=search.value;const data=await get(projectPath()+'/sources',{q:appliedSearch,limit:50,cursor:more?cursor:null},signal);if(!active(epoch)||signal.aborted)return;cursor=data.next_cursor;const rows=data.items.map(s=>button(`${s.name} (${s.slug})`,()=>{editor.entry.value=key==='sources'?s.slug:s.id;editor.add();},'',{'class':'filter-source-choice'}));if(!more)results.replaceChildren();results.append(...rows);next.disabled=!cursor;status.textContent=data.items.length?'':'Нет источников.';}catch(e){if(e.name!=='AbortError')status.textContent=e.message;}finally{busy=false;}};
 const next=button('Ещё источники',()=>void load(true),'',{disabled:true});search.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();void load();}});
 return h('details',{class:'filter-source-picker'},h('summary',{},'Выбрать из реестра'),h('div',{class:'filter-entry'},search,button('Поиск источников',()=>void load())),results,next,status);
}
export function filterBuilder(sources,signal,epoch) {
 const f=state.filters||defaultFilters(),val=k=>f[k]||'';
 const dateValue=k=>val(k).replace(/Z$/,'').replace(/(\.\d{3})\d+$/,'$1');
 const fields=[field('Поиск',input('q',{value:val('q'),maxlength:512,placeholder:'database OR timeout'})),field('Уровень',select('level',[['','Все'],...levels.map(l=>[l,l])],val('level'))),field('Источник',input('source',{value:val('source'),list:'log-sources',maxlength:100,placeholder:'slug'})),field('Сервис',input('service',{value:val('service'),maxlength:255})),field('С (UTC)',input('from',{type:'datetime-local',step:'0.001',value:dateValue('from')})),field('До (UTC)',input('to',{type:'datetime-local',step:'0.001',value:dateValue('to')})),field('На странице',select('limit',[...new Set(['25','50','100',val('limit')||'50'])].map(v=>[v,v]),val('limit')||'50')),field('Режим страницы',select('consistency',[['live','Живой поиск'],['snapshot','Ограниченный снимок']],val('consistency')||'live'))];
 const editors=listFields.map(([key,label,max])=>{const options=key==='sources'?sources.map(s=>[s.slug,s.name]):key==='source_ids'?sources.map(s=>[s.id,s.name]):[];const e=listEditor(key,label,val(key),max,options);if(key==='sources'||key==='source_ids')e.node.append(sourcePicker(e,key,signal,epoch));return e.node;});
 const path=listEditor('context_path','JSON-путь context',val('context_path'),256,[],true);
 const extra=h('details',{},h('summary',{},'Дополнительные фильтры'),h('div',{class:'form-grid'},field('Источник UUID',input('source_id',{value:val('source_id'),list:'log-source-ids',maxlength:36})),field('Host',input('host',{value:val('host'),maxlength:255})),field('Trace ID',input('trace_id',{value:val('trace_id'),maxlength:128})),field('Request ID',input('request_id',{value:val('request_id'),maxlength:128})),field('Тег',input('tag',{value:val('tag'),maxlength:128})),field('Режим поиска',select('q_mode',[['','Web (по умолчанию)'],['plain','Все слова'],['phrase','Фраза']],val('q_mode'))),...editors,field('JSON context',h('textarea',{name:'context',maxlength:4096,placeholder:'{"error_code":"DB_DOWN"}'},val('context'))),path.node,field('Значение JSON-пути (scalar)',input('context_equals',{value:val('context_equals'),maxlength:4096,placeholder:'"eu" / 123 / true / null'}))),h('p',{class:'muted small'},'Списки: OR внутри одного поля, AND между полями. Теги ALL требуют все значения, ANY — хотя бы одно. JSON-путь добавляйте по сегментам в порядке вложенности; пустое значение проверяет существование пути.'));
 if(['source_id','host','trace_id','request_id','tag','context','q_mode','context_path','context_equals',...listFields.map(x=>x[0])].some(k=>val(k)))extra.open=true;
 const apply=async(next)=>{if(!active(epoch)||signal.aborted)return;state.filters=next;state.logPosition=null;await render();};
 const filter=form('Найти',fields,async d=>{const next=Object.fromEntries(d);for(const key of ['from','to'])if(next[key]&&val(key)&&Date.parse(next[key]+'Z')===Date.parse(dateValue(key)+'Z'))next[key]=val(key);await apply(validateFilters(next));},{after:[button('Сбросить',()=>void apply(defaultFilters()))]});
 filter.insertBefore(extra,filter.querySelector('.actions'));
 filter.append(h('datalist',{id:'log-sources'},sources.map(s=>h('option',{value:s.slug},s.name))),h('datalist',{id:'log-source-ids'},sources.map(s=>h('option',{value:s.id},s.name))));
 const labels=Object.fromEntries([...listFields.map(([k,l])=>[k,l]),['q','Поиск'],['level','Уровень'],['source','Источник'],['source_id','Источник UUID'],['service','Сервис'],['host','Host'],['trace_id','Trace ID'],['request_id','Request ID'],['tag','Тег'],['from','С'],['to','До'],['q_mode','Режим поиска'],['context','JSON context'],['context_path','JSON-путь'],['context_equals','Значение пути']]);
 const chips=h('div',{class:'filter-chips','aria-label':'Активные фильтры'},Object.entries(f).filter(([k,v])=>labels[k]&&v).map(([k,v])=>h('span',{class:'filter-chip'},`${labels[k]}: ${v.length>100?v.slice(0,100)+'…':v}`,button('×',()=>void apply(removeFilter(f,k)),'',{'aria-label':'Снять фильтр '+labels[k]}))));
 return h('div',{class:'filter-builder'},h('div',{class:'actions'},[['errors','Ошибки'],['critical','Критические'],['warnings','Предупреждения'],['hour','Последний час']].map(([k,l])=>button(l,()=>void apply(preset(k))))),chips,filter);
}
