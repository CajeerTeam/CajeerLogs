import { state, active, render } from '../app/store.js?v=0.4.4';
import { $, h, button, badge, text, date, enc, message, modalEpoch, modal, table, copy, notify } from './ui.js?v=0.4.4';
import { get, request } from '../api/client.js?v=0.4.4';
import { jsonDocument, pointer, searchJson, prettyJson } from '../app/event-json.js?v=0.4.4';
import { validateFilters, defaultFilters } from '../app/filters.js?v=0.4.4';

let pending;
const branch=n=>n.type==='object'||n.type==='array';
const clip=(s,n=300)=>s.length>n?s.slice(0,n)+'…':s;
const fields=['id','project_id','source_id','source','service','host','level','timestamp','received_at','trace_id','request_id','tags'];
const filterable=['level','source','source_id','service','host','trace_id','request_id'];
function applyContext(path,node,doc,row,project){
    if(state.project!==project||state.route!=='logs'||!state.user)return;
    try {
        const t=Date.parse(row.timestamp);if(!Number.isFinite(t))throw new Error('Некорректное время события.');
        const f={...state.filters,context_path:JSON.stringify(path)};delete f.context_equals;
        if(!branch(node))f.context_equals=doc.raw(node);
        // A bounded event-centred day includes the inspected event; other filters remain.
        const utc=v=>Date.parse(typeof v==='string'&&!v.endsWith('Z')?v+'Z':v);
        const from=utc(f.from),to=utc(f.to);
        if(!Number.isFinite(from)||!Number.isFinite(to)||to-from>31*86400000||t<from||t>=to){f.from=new Date(t-43200000).toISOString();f.to=new Date(t+43200000).toISOString();}
        state.filters=validateFilters(f);state.logPosition=null;void render();
    }catch(e){notify(e.message);}
}
function downloadJson(value,id){const url=URL.createObjectURL(new Blob([value],{type:'application/json'}));const a=h('a',{href:url,download:'event-'+id+'.json'});document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);}
function treeView(doc,root,onFilter){
    let rendered=0;const maxRows=3000;
    function make(node,path,key){
        rendered++;const composite=branch(node), raw=composite?'':doc.raw(node);
        const tools=h('span',{class:'inspector-node-actions'},path.length?button('Ключ',()=>void copy(key),'',{'aria-label':'Копировать JSON-ключ'}):null,button('Путь',()=>void copy(pointer(path)),'',{'aria-label':'Копировать JSON-путь'}),button('Значение',()=>void copy(doc.raw(node)),'',{'aria-label':'Копировать JSON-значение'}),path.length&&onFilter?button('Фильтр',()=>onFilter(path,node),'',{'aria-label':'Фильтр по JSON-полю'}):null);
        const label=h('span',{class:'json-key'},clip(key)+': '), value=h('span',{class:'json-'+node.type},composite?(node.type==='array'?'Array […]':'Object {…}'):clip(raw,500));
        if(!composite)return h('div',{class:'json-node'},label,value,tools);
        const content=h('div',{class:'json-children'}), details=h('details',{class:'json-branch'},h('summary',{},label,value),tools,content);
        let iterator,done=false;const more=button('Следующие 100 полей',()=>load());
        function load(){if(done)return;iterator??=doc.children(node);more.remove();const batch=[];for(let i=0;i<100&&rendered<maxRows;i++){const next=iterator.next();if(next.done){done=true;break;}batch.push(make(next.value.node,[...path,next.value.key],next.value.key));}content.append(...batch);if(!done)content.append(rendered>=maxRows?h('p',{class:'muted'},'Лимит дерева: 3000 отображённых узлов. Откройте JSON целиком в Raw или заново откройте вкладку дерева.'):more);}
        details.addEventListener('toggle',()=>{if(details.open&&!iterator)load();});return details;
    }
    const tree=make(root,[],'context');if(tree.tagName==='DETAILS')tree.open=true;
    return tree;
}
function contextPanel(doc,root,onFilter){
    if(!root)return h('p',{class:'muted'},'Контекст отсутствует.');
    const results=h('div',{class:'inspector-search-results','aria-live':'polite'}), q=h('input',{type:'search',placeholder:'Ключ или значение','aria-label':'Поиск по JSON'});
    const f=h('form',{class:'actions'},q,h('button',{type:'submit'},'Найти в JSON'),button('Сбросить',()=>{q.value='';results.replaceChildren();}));
    f.addEventListener('submit',e=>{e.preventDefault();results.replaceChildren();const query=q.value.trim();if(!query)return;const iterator=searchJson(doc,root,query);let count=0,done=false;while(count<100){const n=iterator.next();if(n.done){done=true;if(n.value)results.append(h('p',{class:'muted'},'Поиск ограничен 20 000 узлами.'));break;}const {path,node}=n.value;results.append(h('div',{class:'json-result'},h('code',{},pointer(path)||'(корень)'),h('span',{},branch(node)?node.type:clip(doc.raw(node),250)),button('Копировать путь',()=>void copy(pointer(path))),button('Копировать значение',()=>void copy(doc.raw(node))),path.length&&onFilter?button('Фильтр',()=>onFilter(path,node)):null));count++;}if(!done)results.append(h('p',{class:'muted'},'Первые 100 совпадений. Уточните запрос.'));if(!count)results.append(h('p',{},'Совпадений не найдено в проверенной области.'));});
    return h('div',{},h('h3',{},'JSON context'),f,results,treeView(doc,root,onFilter));
}
function rawPanel(doc,node,id){const raw=doc.raw(node),pretty=prettyJson(doc,node);const area=h('textarea',{class:'inspector-raw',readonly:true,'aria-label':'JSON события',spellcheck:'false'});area.value=pretty??raw;return h('div',{},h('div',{class:'actions'},button('Скопировать JSON',()=>void copy(raw)),button('Скачать JSON',()=>downloadJson(raw,id)),pretty!==null?button('Исходный / форматированный',()=>{area.value=area.value===raw?pretty:raw;}):null),h('p',{class:'muted'},pretty===null?'Большой JSON: исходный ответ без форматирования.':'JSON ответа API; числовые литералы сохранены.'),area);}
function relatedPanel(row,project,signal,valid,openRelated){
    const box=h('div',{}), result=h('div',{'aria-live':'polite'});let kind=null, seq=0, controller;
    const choices=filterable.filter(k=>['trace_id','request_id'].includes(k)&&typeof row[k]==='string'&&row[k]!=='');
    if(!choices.length)return h('p',{},'У события нет Trace ID или Request ID.');
    const controls=choices.map(k=>button(k==='trace_id'?'Trace ID':'Request ID',()=>{kind=k;void load(null);},'',{'aria-pressed':'false'}));
    const explorer=button('Открыть в Log Explorer',()=>{if(!valid()||!kind)return;state.filters={...defaultFilters(),[kind]:row[kind]};state.logPosition=null;if(state.route==='logs')void render();else location.hash='logs';});
    box.append(h('p',{class:'muted'},'Поиск по точному идентификатору во всём текущем проекте, независимо от фильтров исходной таблицы. Текущее событие отмечено.'),h('div',{class:'actions'},controls,explorer),result);
    async function load(cursor){const token=++seq;controller?.abort();controller=new AbortController();const querySignal=AbortSignal.any([signal,controller.signal]);controls.forEach((b,i)=>b.setAttribute('aria-pressed',String(choices[i]===kind)));result.replaceChildren(h('p',{role:'status'},'Загрузка…'));try{const data=await get('/logs',{project_id:project,[kind]:row[kind],limit:25,consistency:'snapshot',cursor},querySignal);if(!valid()||token!==seq||querySignal.aborted)return;result.replaceChildren(table(['Время (UTC)','Уровень','Источник','Сообщение',''],data.items.map((item,i)=>[date(item.timestamp),badge(item.level),text(item.source),clip(text(item.message),500),item.id===row.id?h('span',{},'Текущее'):button('Открыть',()=>openRelated(item.id,{items:data.items,index:i,continued:true}))])),h('div',{class:'actions'},button('Назад',()=>void load(data.prev_cursor),'',{disabled:!data.prev_cursor}),button('Далее',()=>void load(data.next_cursor),'',{disabled:!data.next_cursor})));}catch(e){if(valid()&&token===seq&&!querySignal.aborted){if(e.status===401||e.status===403){$('modal-body').replaceChildren(message(e));return;}result.replaceChildren(message(e),button('Повторить',()=>void load(cursor)));}}}
    kind=choices[0];void load(null);return box;
}
export async function detail(id,project,navigation=null,quickFilter=null){
    pending?.abort();const controller=new AbortController();pending=controller;
    const epoch=state.epoch,signal=AbortSignal.any([controller.signal,state.controller.signal]);
    const loading=h('p',{role:'status'},'Загрузка…');if(navigation?.continued&&$('modal').open)$('modal-body').replaceChildren(loading);else modal('Событие',loading);
    const dialogEpoch=modalEpoch,dialog=$('modal');dialog.addEventListener('close',()=>{if(!dialog.open)controller.abort();},{signal:controller.signal});
    state.controller.signal.addEventListener('abort',()=>controller.abort(),{once:true,signal:controller.signal});
    const valid=()=>!signal.aborted&&active(epoch)&&state.project===project&&dialog.open&&dialogEpoch===modalEpoch;
    try{
        const wire=await request('/logs/'+enc(id),{query:{project_id:project},signal,wireText:true});if(!valid())return;
        const doc=jsonDocument(wire),event=doc.member(doc.root,'data');if(!event||event.type!=='object')throw new Error('Некорректный ответ API.');
        const row={};let context=null;for(const {key,node} of doc.children(event)){if(key==='context')context=node;else if(fields.includes(key)||key==='message')row[key]=JSON.parse(doc.raw(node));}
        const tabs=h('div',{class:'actions inspector-tabs',role:'group','aria-label':'Разделы события'}), panel=h('div',{class:'inspector-panel'});let tabController;
        const open=(next,nav)=>void detail(next,project,nav,quickFilter);
        const adjacent=navigation?h('div',{class:'actions explorer-detail-nav'},button('Предыдущее событие',()=>open(navigation.items[navigation.index-1].id,{...navigation,index:navigation.index-1,continued:true}),'',{disabled:navigation.index===0}),h('span',{class:'muted'},`${navigation.index+1} / ${navigation.items.length}`),button('Следующее событие',()=>open(navigation.items[navigation.index+1].id,{...navigation,index:navigation.index+1,continued:true}),'',{disabled:navigation.index===navigation.items.length-1})):null;
        const values=()=>table(['Поле','Значение'],fields.map(k=>[k,h('div',{},['timestamp','received_at'].includes(k)?date(row[k]):Array.isArray(row[k])?row[k].join(', '):text(row[k]),row[k]!=null&&row[k]!==''?button('Копировать',()=>void copy(Array.isArray(row[k])?JSON.stringify(row[k]):String(row[k])),'explorer-copy'):null,quickFilter&&state.route==='logs'&&filterable.includes(k)&&row[k]!=null&&row[k]!==''?button('Фильтр',()=>quickFilter(k,row[k],project),'explorer-copy',{'aria-label':'Фильтр по '+k}):null)]));
        const contextView=()=>contextPanel(doc,context,state.route==='logs'?(p,n)=>applyContext(p,n,doc,row,project):null);
        const overview=()=>h('div',{},h('h3',{},'Сообщение'),h('pre',{class:'inspector-message'},text(row.message)),values(),contextView());
        const sections=[['Обзор',overview],['Поля',values],['JSON context',contextView],['Raw JSON',()=>rawPanel(doc,event,id)],['Связанные события',()=>{const tabSignal=AbortSignal.any([signal,tabController.signal]);return relatedPanel(row,project,tabSignal,()=>valid()&&!tabSignal.aborted,open);}]];
        function show(i){if(!valid())return;tabController?.abort();tabController=new AbortController();[...tabs.children].forEach((b,j)=>b.setAttribute('aria-pressed',String(i===j)));panel.replaceChildren(sections[i][1]());}
        sections.forEach(([label],i)=>tabs.append(button(label,()=>show(i),'',{'aria-pressed':'false'})));
        $('modal-body').replaceChildren(...(adjacent?[adjacent]:[]),h('div',{class:'inline'},badge(row.level),date(row.timestamp)),h('p',{class:'muted inspector-id'},id),tabs,panel);show(0);if(navigation?.continued)(adjacent?.querySelector('button:not(:disabled)')||$('close-modal')).focus();
    }catch(e){if(valid()&&e.name!=='AbortError')$('modal-body').replaceChildren(message(e),e.status===404?h('p',{},'Событие могло быть удалено политикой retention.'):e.status===413?h('p',{},'Событие превышает серверный лимит просмотра.'):null,e.status!==401&&e.status!==403?button('Повторить',()=>void detail(id,project,navigation?{...navigation,continued:true}:null,quickFilter)):null);}
}
