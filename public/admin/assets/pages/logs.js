import { levels, state, projectPath, active, render } from '../app/store.js?v=0.3.4';
import { h, button, card, field, input, select, date, message, notify, form, heading, needProject, clearModal } from '../components/ui.js?v=0.3.4';
import { projectSelector } from '../app/projects.js?v=0.3.4';
import { logTable } from '../components/log-table.js?v=0.3.4';
import { columns, defaultLayout, normalizeLayout, moveColumn, pageChanged } from '../app/explorer.js?v=0.3.4';
import { request, get } from '../api/client.js?v=0.3.4';

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
    const config = h('details',{class:'explorer-config'},h('summary',{},'Настроить таблицу'));
    const configBody = h('div',{class:'explorer-config-body'}); config.append(configBody);
    const check = button('Проверить новые события',()=>void probe());
    const latest = button('К последним событиям',()=>void load(null,0,{reset:true}));
    const refresh = button('Обновить страницу',()=>void load(current?.page_cursor??null,index));
    const autoInput = input('auto_logs',{type:'checkbox', 'aria-label':'Проверять новые события автоматически'});
    autoInput.addEventListener('change',()=>{auto=autoInput.checked; schedule();});
    const toolbar = h('div',{class:'actions explorer-toolbar'},refresh,latest,query.consistency==='live'?check:null,query.consistency==='live'?h('label',{class:'check'},autoInput,'Проверять каждые 60 с'):null);
    box.append(config,toolbar,status,notice,error,viewport,pager);
    const valid=()=>active(epoch)&&!signal.aborted&&state.project===project;
    function schedule(){clearTimeout(timer); if(auto&&valid())timer=setTimeout(async()=>{if(!document.hidden)await probe();schedule();},60000);}
    signal.addEventListener('abort',()=>{clearTimeout(timer);},{once:true});
    function remember(){if(!valid()||!current)return;state.logPosition={signature,cursor:current.page_cursor,index,headDigest,scroll:{...scroll},selected};}
    function capture(){const el=viewport.querySelector('.explorer-scroll');if(el){scroll={left:el.scrollLeft,top:el.scrollTop};} const focused=document.activeElement?.closest('.explorer-open');if(focused)selected=focused.dataset.eventId;remember();}
    function syncControls(){const locked=busy||probing||exporting; refresh.disabled=locked||!current;latest.disabled=locked;check.disabled=locked||!current;autoInput.disabled=busy||!current;for(const b of pager.querySelectorAll('button'))b.disabled=locked||b.dataset.available==='false';box.setAttribute('aria-busy',String(busy||probing));}
    function setLayout(next,focusKey=null){capture();layout=normalizeLayout(next);state.explorerLayout=layout;drawConfig();drawTable();if(focusKey)configBody.querySelector(`[data-config-key="${focusKey}"]`)?.focus();}
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
        pager.replaceChildren(h('span',{class:'muted',role:'status'},`Страница ${index+1} · ${data.items.length} записей`),h('div',{class:'actions'},button('CSV',()=>void download('csv')),button('JSON',()=>void download('json'))),h('div',{class:'actions'},prev,next,button('Начать заново',()=>void load(null,0,{reset:true}))));syncControls();}
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
    async function probe(){if(!valid()||busy||probing||exporting||!current||query.consistency!=='live')return;probing=true;syncControls();try{
        const data=await get('/logs',{...query,cursor:null},signal);if(!valid())return;
        notice.hidden=false;
        if(!headDigest)notice.replaceChildren(h('span',{},'Проверена первая страница. Перейдите к последним событиям для обновления.'),button('Показать последние',()=>void load(null,0,{reset:true})));
        else if(pageChanged(headDigest,data))notice.replaceChildren(h('span',{},'Первая страница изменилась: появились новые события или изменился состав выборки.'),button('Показать последние',()=>void load(null,0,{reset:true})));
        else notice.replaceChildren(h('span',{},'Первая страница не изменилась.'));
    }catch(e){if(e.name==='AbortError'||!valid())return;if(e.status===401||e.status===403)clearData(e);else{notice.hidden=false;notice.replaceChildren(message(e));if(e.status===429){auto=false;autoInput.checked=false;notice.append(h('p',{},'Автоматическая проверка остановлена из-за лимита запросов.'));}}}finally{probing=false;syncControls();}}
    async function download(format){if(!current||busy||probing||exporting||!valid())return;const page=current;exporting=true;syncControls();try{
        const r=await request('/logs/export',{query:{...query,cursor:page.page_cursor,format},signal,raw:true,ifMatch:'"'+page.page_digest+'"'});
        if(r.headers.get('X-Page-Digest')!==page.page_digest)throw new Error('Страница изменилась. Обновите поиск перед экспортом.');const blob=await r.blob();if(!valid()||current!==page)return;
        const url=URL.createObjectURL(blob),a=h('a',{href:url,download:'cajeerlogs.'+format});document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);notify('Экспорт текущей страницы подготовлен.');
    }catch(e){if(e.name!=='AbortError'&&valid()){if(e.status===401||e.status===403)clearData(e);else notify(e.message);}}finally{exporting=false;syncControls();}}
    drawConfig();restoreFocus=!!selected;void load(saved?.cursor||null,index);return box;
}
async function logs(signal, epoch) { const root = h('div', {}, heading('Логи', 'Поиск, фильтры и просмотр сохранённых событий.'), projectSelector()); if (!state.project) {
    root.append(needProject());
    return root;
} const src = await get(projectPath() + '/sources', { limit: 100 }, signal); root.append(card(logFilters(src.items)), card(h('h2', {}, 'События'), logPager(queryFilters(), signal, epoch))); return root; }

export { logs };
