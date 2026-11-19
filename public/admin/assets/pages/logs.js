import { levels, state, projectPath, active, render, hasPermission } from '../app/store.js?v=0.9.8';
import { h, button, card, field, input, select, date, message, notify, form, heading, needProject, clearModal } from '../components/ui.js?v=0.9.8';
import { projectSelector } from '../app/projects.js?v=0.9.8';
import { logTable } from '../components/log-table.js?v=0.9.8';
import { columns, defaultLayout, normalizeLayout, moveColumn, pageChanged } from '../app/explorer.js?v=0.9.8';
import { request, get } from '../api/client.js?v=0.9.8';

import { filterBuilder } from '../components/filter-builder.js?v=0.9.8';
import { savedViews } from '../components/saved-views.js?v=0.9.8';
import { queryFilters as buildQuery } from '../app/filters.js?v=0.9.8';
import { exportJobs } from './exports.js?v=0.9.8';
function queryFilters() { return buildQuery(state.filters,state.project); }
function logPager(query, signal, epoch) {
    const signature = JSON.stringify(query), project = state.project;
    const saved = state.logPosition?.signature === signature ? state.logPosition : null;
    let current = null, busy = false, probing = false, exporting = false, index = saved?.index || 0, headDigest = saved?.headDigest || null;
    let auto = false, timer = null, scroll = saved?.scroll || {left:0,top:0}, selected = saved?.selected || null;
    let layout = normalizeLayout(state.explorerLayout), restoreFocus = false;
    const box = h('div', {class:'explorer', 'aria-label':'Log Explorer'});
    const status = h('p',{class:'muted small',role:'status'},'Загрузка…');
    const notice = h('div',{class:'explorer-notice',role:'status',hidden:true});
    const error = h('div',{class:'explorer-errors'});
    const viewport = h('div',{class:'explorer-results'});
    const pager = h('div',{class:'pager'});
    const facetPanel = h('div',{class:'search-facets'});
    const config = h('details',{class:'explorer-config'},h('summary',{},'Настроить таблицу'));
    const configBody = h('div',{class:'explorer-config-body'}); config.append(configBody);
    const check = button('Проверить новые события',()=>void probe());
    const latest = button('К последним событиям',()=>void load(null,0,{reset:true}));
    const refresh = button('Обновить страницу',()=>void load(current?.page_cursor??null,index));
    const autoInput = input('auto_logs',{type:'checkbox', 'aria-label':'Проверять новые события автоматически'});
    autoInput.addEventListener('change',()=>{auto=autoInput.checked; schedule();});
    const toolbar = h('div',{class:'actions explorer-toolbar'},refresh,latest,query.consistency==='live'?check:null,query.consistency==='live'?h('label',{class:'check'},autoInput,'Проверять каждые 60 с'):null);
    box.append(config,toolbar,facetPanel,status,notice,error,viewport,pager);
    const valid=()=>active(epoch)&&!signal.aborted&&state.project===project;
    function schedule(){clearTimeout(timer); if(auto&&valid())timer=setTimeout(async()=>{if(!document.hidden)await probe(true);schedule();},60000);}
    signal.addEventListener('abort',()=>{clearTimeout(timer);},{once:true});
    function remember(){if(!valid()||!current)return;state.logPosition={signature,cursor:current.page_cursor,index,headDigest,scroll:{...scroll},selected};}
    function capture(){const el=viewport.querySelector('.explorer-scroll');if(el){scroll={left:el.scrollLeft,top:el.scrollTop};} const focused=document.activeElement?.closest('.explorer-open');if(focused)selected=focused.dataset.eventId;remember();}
    function syncControls(){const locked=busy||probing||exporting; refresh.disabled=locked||!current;latest.disabled=locked;check.disabled=locked||!current;autoInput.disabled=busy||!current;for(const b of pager.querySelectorAll('button'))b.disabled=locked||b.dataset.available==='false';box.setAttribute('aria-busy',String(busy||probing));}
    function setLayout(next,focusKey=null){capture();layout=normalizeLayout(next);state.explorerLayout=layout;drawConfig();drawTable();if(focusKey)configBody.querySelector(`[data-config-key="${focusKey}"]`)?.focus();}
    // Facets count every matching event in a bounded, authorized interval,
    // not just the current 25/50/100-row page.
    async function loadFacets(){
        const from=Date.parse(query.from||''),to=Date.parse(query.to||'');
        if(!Number.isFinite(from)||!Number.isFinite(to)||from>=to||to-from>7*86400000){
            facetPanel.replaceChildren(h('p',{class:'muted small'},'Фасеты: выберите диапазон поиска до 7 дней.'));
            return;
        }
        const {cursor,limit,consistency,format,interval,...filters}=query;
        try{
            const summary=await get('/logs/facets',filters,signal);
            if(!valid())return;
            const fields=[['levels','level','Уровни'],['sources','source_id','Источники'],['services','service','Сервисы'],['hosts','host','Хосты']];
            const sections=fields.map(([group,key,title])=>h('div',{class:'search-facet-group'},
                h('strong',{},title),...(summary[group]||[]).map(item=>button(`${item.label||item.value} (${item.count})`,()=>{
                    if(!valid())return;
                    const f={...state.filters,[key]:item.value};
                    for(const other of (key==='source_id'?['sources','source_ids','source']:key==='level'?['levels']:key==='service'?['services']:['hosts']))delete f[other];
                    state.filters=f;state.logPosition=null;void render();
                })),summary.truncated?.[group]?h('span',{class:'muted small'},'Показаны первые 20'):null));
            facetPanel.replaceChildren(h('details',{class:'explorer-facets'},h('summary',{},`Фасеты · ${summary.total} совпадений`),h('div',{class:'search-facet-groups'},...sections)));
        }catch(e){if(valid()&&e.name!=='AbortError')facetPanel.replaceChildren(h('p',{class:'muted small'},'Фасеты недоступны: '+e.message));}
    }
    function drawConfig(){
        const density=select('density',[['normal','Обычная'],['compact','Компактная']],layout.density);density.addEventListener('change',()=>setLayout({...layout,density:density.value},'density'));density.dataset.configKey='density';
        const zone=select('timezone',[['utc','UTC'],['local','Локальный']],layout.zone);zone.addEventListener('change',()=>setLayout({...layout,zone:zone.value},'zone'));zone.dataset.configKey='zone';
        const wrap=input('wrap',{type:'checkbox',checked:layout.wrap,'aria-label':'Перенос текста'});wrap.dataset.configKey='wrap';wrap.addEventListener('change',()=>setLayout({...layout,wrap:wrap.checked},'wrap'));
        const rows=layout.order.map((key,i)=>{const c=columns.find(c=>c.key===key);const visible=input('column_'+key,{type:'checkbox',checked:layout.visible.includes(key),'aria-label':'Показать '+c.label});visible.dataset.configKey='show_'+key;visible.disabled=visible.checked&&layout.visible.length===1;visible.addEventListener('change',()=>setLayout({...layout,visible:visible.checked?[...layout.visible,key]:layout.visible.filter(k=>k!==key)},'show_'+key));
            const width=input('width_'+key,{type:'number',min:90,max:800,step:10,value:layout.widths[key]??c.width,'aria-label':'Ширина '+c.label});width.dataset.configKey='width_'+key;width.addEventListener('change',()=>{if(!width.checkValidity()||width.value===''){width.value=layout.widths[key]??c.width;return;}setLayout({...layout,widths:{...layout.widths,[key]:Number(width.value)}},'width_'+key);});
            const up=button('↑',()=>setLayout(moveColumn(layout,key,-1),'up_'+key),'',{'aria-label':'Поднять '+c.label,disabled:i===0,'data-config-key':'up_'+key});
            const down=button('↓',()=>setLayout(moveColumn(layout,key,1),'down_'+key),'',{'aria-label':'Опустить '+c.label,disabled:i===layout.order.length-1,'data-config-key':'down_'+key});
            return h('div',{class:'explorer-column-setting'},h('label',{class:'check'},visible,c.label),width,up,down);});
        configBody.replaceChildren(h('div',{class:'form-grid'},field('Плотность',density),field('Часовой пояс',zone),h('label',{class:'check'},wrap,'Перенос текста')),h('p',{class:'muted small'},'Ширина колонок в пикселях (90–800). Настройки действуют в текущем сеансе; поиск и экспорт не изменяются.'),h('div',{class:'explorer-columns'},rows),button('Сбросить колонки',()=>setLayout(defaultLayout())));
    }
    function drawTable(){if(!current)return;viewport.replaceChildren(logTable(current.items,project,{layout,query:query.q}));const el=viewport.querySelector('.explorer-scroll');if(el){el.scrollLeft=scroll.left;el.scrollTop=scroll.top;el.addEventListener('scroll',()=>{scroll={left:el.scrollLeft,top:el.scrollTop};remember();},{passive:true});el.addEventListener('focusin',e=>{const b=e.target.closest('.explorer-open');if(b){selected=b.dataset.eventId;remember();}});if(restoreFocus){[...el.querySelectorAll('.explorer-open')].find(b=>b.dataset.eventId===selected)?.focus({preventScroll:true});restoreFocus=false;}}}
    function drawPager(){if(!current){pager.replaceChildren();return;} const data=current;
        const prev=button('Назад',()=>void load(data.prev_cursor,index-1,{resetScroll:true}),'',{'data-available':String(!!data.prev_cursor)});
        const next=button('Далее',()=>void load(data.next_cursor,index+1,{resetScroll:true}),'',{'data-available':String(!!data.next_cursor)});
        pager.replaceChildren(h('span',{class:'muted',role:'status'},`Страница ${index+1} · ${data.items.length} записей`),hasPermission('logs:export')?h('div',{class:'actions'},button('CSV',()=>void download('csv')),button('JSON',()=>void download('json'))):null,h('div',{class:'actions'},prev,next,button('Начать заново',()=>void load(null,0,{reset:true}))));syncControls();}
    function clearData(e){clearModal();current=null;headDigest=null;state.logPosition=null;viewport.replaceChildren();pager.replaceChildren();notice.hidden=true;status.textContent='Доступ к событиям недоступен.';error.replaceChildren(message(e));auto=false;autoInput.checked=false;clearTimeout(timer);}
    async function load(cursor=null,target=0,{reset=false,resetScroll=false}={}){
        if(busy||probing||exporting||!valid())return;
        const fromPager=pager.contains(document.activeElement);capture();busy=true;syncControls();error.replaceChildren();
        try{
            const data=await get('/logs',{...query,cursor},signal);if(!valid())return;
            current=data;index=Math.max(0,target);if(!cursor||(!data.prev_cursor&&index===0))headDigest=data.page_digest;
            if(reset||resetScroll){scroll={left:0,top:0};selected=null;}
            if(reset||!cursor){notice.hidden=true;notice.replaceChildren();}
            status.textContent=data.snapshot_at?'Снимок по received_at: '+date(data.snapshot_at)+' · ограниченный снимок; retention может удалить события. Номер страницы — позиция навигации.':'Живой поиск: новые события могут менять страницы. Порядок: время события ↓, UUID ↓. Номер страницы — позиция навигации.';
            remember();drawTable();drawPager();if(fromPager)viewport.querySelector('.explorer-scroll')?.focus({preventScroll:true});
        }catch(e){if(e.name==='AbortError'||!valid())return;if(e.status===401||e.status===403)clearData(e);else{error.replaceChildren(message(e),button('Повторить запрос',()=>void load(cursor,target,{reset,resetScroll})),button('Начать новый поиск',()=>void load(null,0,{reset:true})));if(!current)status.textContent='Не удалось загрузить события.';}}
        finally{busy=false;syncControls();}
    }
    async function probe(background=false){if(!valid()||busy||probing||exporting||!current||query.consistency!=='live')return;probing=true;syncControls();try{
        const data=await request('/logs',{query:{...query,cursor:null},signal,extraHeaders:background?{'X-Cajeer-Background':'1'}:{}});if(!valid())return;
        notice.hidden=false;
        if(!headDigest)notice.replaceChildren(h('span',{},'Проверена первая страница. Перейдите к последним событиям для обновления.'),button('Показать последние',()=>void load(null,0,{reset:true})));
        else if(pageChanged(headDigest,data))notice.replaceChildren(h('span',{},'Первая страница изменилась: появились новые события или изменился состав выборки.'),button('Показать последние',()=>void load(null,0,{reset:true})));
        else notice.replaceChildren(h('span',{},'Первая страница не изменилась.'));
    }catch(e){if(e.name==='AbortError'||!valid())return;if(e.status===401||e.status===403)clearData(e);else{notice.hidden=false;notice.replaceChildren(message(e));if(e.status===429){auto=false;autoInput.checked=false;notice.append(h('p',{},'Автоматическая проверка остановлена из-за лимита запросов.'));}}}finally{probing=false;syncControls();}}
    async function download(format){if(!hasPermission('logs:export')||!current||busy||probing||exporting||!valid())return;const page=current;exporting=true;syncControls();try{
        const r=await request('/logs/export',{query:{...query,cursor:page.page_cursor,format},signal,raw:true,ifMatch:'"'+page.page_digest+'"'});
        if(r.headers.get('X-Page-Digest')!==page.page_digest)throw new Error('Страница изменилась. Обновите поиск перед экспортом.');const blob=await r.blob();if(!valid()||current!==page)return;
        const url=URL.createObjectURL(blob),a=h('a',{href:url,download:'cajeerlogs.'+format});document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);notify('Экспорт текущей страницы подготовлен.');
    }catch(e){if(e.name!=='AbortError'&&valid()){if(e.status===401||e.status===403)clearData(e);else notify(e.message);}}finally{exporting=false;syncControls();}}
    drawConfig();restoreFocus=!!selected;void load(saved?.cursor||null,index);void loadFacets();return box;
}
async function logs(signal, epoch) { const root = h('div', {}, heading('Логи', 'Поиск, фильтры и просмотр сохранённых событий.'), projectSelector()); if (!state.project) {
    root.append(needProject());
    return root;
} if(!hasPermission('logs:read')){root.append(card(h('p',{},'Нет разрешения logs:read в этом проекте. Выберите другой проект.')));return root;} const src = await get(projectPath() + '/sources', { limit: 100 }, signal); root.append(card(savedViews(signal,epoch), filterBuilder(src.items,signal,epoch)), card(h('h2', {}, 'События'), logPager(queryFilters(), signal, epoch))); if (hasPermission('logs:export')) root.append(exportJobs(queryFilters,signal,epoch)); return root; }

export { logs };
