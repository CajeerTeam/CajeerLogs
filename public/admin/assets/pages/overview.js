import { levels, state, render, hasPermission } from '../app/store.js?v=1.0.1';
import { h, button, card, empty, date, heading, needProject, table, field, select, input, form, checkbox, message } from '../components/ui.js?v=1.0.1';
import { projectSelector } from '../app/projects.js?v=1.0.1';
import { navigate } from '../app/routes.js?v=1.0.1';
import { logTable } from '../components/log-table.js?v=1.0.1';
import { get, request } from '../api/client.js?v=1.0.1';
import { rangeFor, previousRange, denseBuckets, levelBuckets, rateBuckets, dimensionComparison, sourceHealth, delta, explorerFilters, utcInput, errorLevels } from '../app/analytics.js?v=1.0.1';

const format = n => Number(n).toLocaleString('ru-RU',{maximumFractionDigits:2});
const labels = {'15m':'15 минут','1h':'1 час','6h':'6 часов','24h':'24 часа','7d':'7 дней','30d':'30 дней','custom':'Свой период'};
const intervalLabels={auto:'Автоматически',minute:'1 минута','5min':'5 минут',hour:'1 час',day:'1 день'};
const newConfig = project => ({project,period:'24h',interval:'auto',source:'',sourceName:'',from:'',to:'',compare:false,refresh:false,threshold:60,critical:false,chartLevel:'error',sourceQuery:'',sourceStatus:'all',cursor:null,previous:[]});
function drill(project,range,source,extra={}) {
    if (state.project!==project || state.route!=='overview') return;
    state.filters=explorerFilters(range,source,extra); state.logPosition=null; navigate('logs');
}
function metricValues(stats,range) {
    const errors=errorLevels.reduce((n,k)=>n+Number(stats.by_level[k]||0),0);
    const mins=(Date.parse(range.to)-Date.parse(range.from))/60000;
    return [stats.total,errors,stats.by_level.warning||0,stats.total?errors/stats.total*100:0,stats.total/mins,stats.source_count??stats.by_source.length];
}
function metrics(stats,previous,range,project,source) {
    const values=metricValues(stats,range), prior=previous?metricValues(previous,previousRange(range)):null;
    const entries=[['Всего событий',{}],['Ошибки и выше',{levels:JSON.stringify(errorLevels)}],['Предупреждения',{level:'warning'}],['Доля ошибок',{levels:JSON.stringify(errorLevels)}],['Событий в минуту',{}],['Источники с событиями',null]];
    return h('div',{class:'metric-grid analytics-metrics'},entries.map(([label,filters],i)=>h('div',{class:'metric'},h('span',{class:'muted'},label),h('strong',{},format(values[i])+(i===3?'%':'')),prior?h('small',{class:'muted'},'К предыдущему: '+(i===3?format(values[i]-prior[i])+' п.п.':delta(values[i],prior[i]))):null,filters?button('Открыть логи',()=>drill(project,range,source,filters),'analytics-link'):null)));
}
function bars(title,entries,onSelect) {
    const max=Math.max(1,...entries.map(x=>Number(x.count)));
    return card(h('h2',{},title),entries.length?entries.map(x=>h('div',{class:'bar-row analytics-rank'},button(x.label,()=>onSelect(x),'analytics-link',{title:x.label}),h('progress',{max,value:x.count,'aria-label':x.label}),h('span',{},format(x.count)))):empty('Нет данных.'));
}
function timeline(stats,range,project,source) {
    const data=denseBuckets(stats,range);
    const max=Math.max(1,...data.map(x=>x.count));
    const detail=h('p',{class:'analytics-chart-detail muted','aria-live':'polite'},'Наведите курсор или выберите столбец. Нажатие открывает логи интервала.');
    const graph=h('div',{class:'analytics-plot',role:'group','aria-label':'График событий и ошибок UTC'});
    const axis=h('div',{class:'analytics-axis','aria-hidden':'true'},[1,.75,.5,.25,0].map(x=>h('span',{},format(max*x))));
    const cols=h('div',{class:'analytics-columns'});
    cols.style.setProperty('--bucket-count',String(data.length));
    data.forEach((b,i)=>{
        const label=`${date(new Date(b.from).toISOString())} — ${date(new Date(b.to).toISOString())}: ${format(b.count)} событий, ${format(b.errors)} ошибок`;
        const bar=button('',()=>drill(project,{from:new Date(b.from).toISOString(),to:new Date(b.to).toISOString()},source),'analytics-bucket',{'aria-label':label,title:label});
        const errorFill=h('span',{class:'analytics-error-fill'});
        const fill=h('span',{class:'analytics-fill'},errorFill);
        fill.style.height=`${b.count/max*100}%`; errorFill.style.height=`${b.count?b.errors/b.count*100:0}%`;
        bar.append(fill);bar.addEventListener('focus',()=>detail.textContent=label);bar.addEventListener('pointerenter',()=>detail.textContent=label);
        cols.append(h('div',{class:'analytics-column'},bar,h('span',{class:'analytics-tick','aria-hidden':'true'},i%Math.max(1,Math.ceil(data.length/8))===0?b.timestamp.slice(range.interval==='day'?5:11,range.interval==='day'?10:16):'')));
    });
    graph.append(axis,cols);
    return card(h('h2',{},'Динамика событий (UTC)'),h('div',{class:'inline analytics-legend'},h('span',{},'Все события'),h('span',{},'Ошибки и выше'),h('span',{class:'muted'},intervalLabels[range.interval])),detail,h('div',{class:'chart-scroll analytics-scroll'},graph),!stats.total?empty('За выбранный период событий нет.'):null,h('details',{},h('summary',{},'Данные графика'),table(['Начало (UTC)','Конец (UTC)','События','Ошибки'],data.map(b=>[date(new Date(b.from).toISOString()),date(new Date(b.to).toISOString()),button(format(b.count),()=>drill(project,{from:new Date(b.from).toISOString(),to:new Date(b.to).toISOString()},source),'analytics-link'),button(format(b.errors),()=>drill(project,{from:new Date(b.from).toISOString(),to:new Date(b.to).toISOString()},source,{levels:JSON.stringify(errorLevels)}),'analytics-link')]))));
}

/** A real time series. Every focusable bin opens its exact half-open event range. */
function scalarTimeline(title, data, range, project, source, accessor, suffix, extra={}) {
    const peak=suffix==='%'?100:Math.max(1,...data.map(accessor));
    const detail=h('p',{class:'analytics-chart-detail muted','aria-live':'polite'},'Выберите столбец для детализации в Explorer.');
    const columns=h('div',{class:'analytics-columns'});
    columns.style.setProperty('--bucket-count',String(data.length));
    for (let i=0;i<data.length;i++) {
        const b=data[i],value=accessor(b);
        const exact={from:new Date(b.from).toISOString(),to:new Date(b.to).toISOString()};
        const label=`${date(exact.from)} — ${date(exact.to)}: ${format(value)}${suffix}`;
        const item=button('',()=>drill(project,exact,source,extra),'analytics-bucket',{'aria-label':label,title:label});
        const fill=h('span',{class:'analytics-fill analytics-series-fill'});
        fill.style.height=`${Math.max(0,Math.min(100,value/peak*100))}%`;
        item.append(fill);
        item.addEventListener('focus',()=>{detail.textContent=label;});
        item.addEventListener('pointerenter',()=>{detail.textContent=label;});
        columns.append(h('div',{class:'analytics-column'},item,h('span',{class:'analytics-tick','aria-hidden':'true'},i%Math.max(1,Math.ceil(data.length/8))===0?b.timestamp.slice(range.interval==='day'?5:11,range.interval==='day'?10:16):'')));
    }
    const graph=h('div',{class:'analytics-plot',role:'group','aria-label':title},
        h('div',{class:'analytics-axis','aria-hidden':'true'},[1,.75,.5,.25,0].map(x=>h('span',{},format(peak*x)+suffix))),columns);
    return card(h('h2',{},title),detail,h('div',{class:'chart-scroll analytics-scroll'},graph),
        h('details',{},h('summary',{},'Точные значения'),table(['Начало (UTC)','Конец (UTC)','Значение'],data.map(b=>[date(new Date(b.from).toISOString()),date(new Date(b.to).toISOString()),button(format(accessor(b))+suffix,()=>drill(project,{from:new Date(b.from).toISOString(),to:new Date(b.to).toISOString()},source,extra),'analytics-link')]))));
}
function levelTrend(stats,range,project,source,config) {
    const selectLevel=select('chartLevel',levels.map(l=>[l,l]),levels.includes(config.chartLevel)?config.chartLevel:'error');
    selectLevel.addEventListener('change',()=>{config.chartLevel=selectLevel.value;void render();});
    const data=levelBuckets(stats,range,selectLevel.value);
    const chart=scalarTimeline('Уровень '+selectLevel.value+' по времени (UTC)',data,range,project,source,b=>b.count,' событий',{level:selectLevel.value});
    chart.prepend(h('div',{class:'analytics-level-control'},field('Уровень для графика',selectLevel)));
    return chart;
}
function errorRateTrend(stats,range,project,source) {
    return scalarTimeline('Доля ошибок по времени (UTC)',rateBuckets(stats,range),range,project,source,b=>b.rate,'%',{levels:JSON.stringify(errorLevels)});
}
function comparisonPanel(current,previous,range,project,source) {
    const before=previousRange(range);
    const rows=[
        ['Уровни','by_level',x=>({level:x.key})],
        ['Источники','by_source',x=>({source:x.key})],
        ['Сервисы','by_service',x=>({service:x.key})]
    ];
    const blocks=rows.map(([title,dimension,toFilter])=>{
        const items=dimensionComparison(current,previous,dimension);
        return card(h('h3',{},title),items.length?table(['Значение','Сейчас','Ранее','Изменение'],items.map(x=>[
            h('div',{class:'analytics-comparison-name'},button(x.label,()=>drill(project,range,dimension==='by_source'?x.key:source,dimension==='by_source'?{}:toFilter(x)),'analytics-link'),button('Ранее',()=>drill(project,before,dimension==='by_source'?x.key:source,dimension==='by_source'?{}:toFilter(x)),'analytics-link muted small')),
            x.count===null?'—':format(x.count),x.previous===null?'—':format(x.previous),h('span',{class:x.change===null?'muted':x.change>0?'analytics-change-up':x.change<0?'analytics-change-down':'muted'},x.change===null?'—':`${x.change>0?'+':''}${format(x.change)}`)
        ])):empty('Нет данных в обоих периодах.'));
    });
    const previousData=denseBuckets(previous,before);
    const previousChart=scalarTimeline('Предыдущий период: события (UTC)',previousData,before,project,source,b=>b.count,' событий');
    return h('section',{class:'analytics-compare'},h('h2',{},'Сравнение периодов'),h('p',{class:'muted small'},`${date(before.from)} — ${date(before.to)}. «—» означает, что значение отсутствует в ограниченном топе и точное изменение неизвестно.`),previousChart,h('div',{class:'chart-grid'},blocks));
}
function controls(config,range,sourceItems,signal) {
    const options=[['','Все источники'],...sourceItems.map(s=>[s.id,`${s.name} (${s.slug})`])];
    if(config.source&&!options.some(([id])=>id===config.source)) options.push([config.source,config.sourceName||config.source]);
    const period=select('period',Object.entries(labels),config.period);
    const from=input('from',{type:'datetime-local',step:'0.001',value:config.from||utcInput(Date.parse(range.from))});
    const to=input('to',{type:'datetime-local',step:'0.001',value:config.to||utcInput(Date.parse(range.to))});
    const custom=h('div',{class:'form-grid analytics-custom'},field('Начало (UTC)',from),field('Конец (UTC)',to));
    function toggle(){custom.hidden=period.value!=='custom';from.disabled=to.disabled=custom.hidden;from.required=to.required=!custom.hidden;}
    toggle();period.addEventListener('change',toggle);
    const source=select('source',options,config.source);
    const f=form('Применить',[field('Период',period),field('Группировка',select('interval',Object.entries(intervalLabels),config.interval)),field('Источник dashboard',source),custom,checkbox('compare','Сравнить с предыдущим периодом',config.compare),checkbox('refresh','Автообновление (60 сек.)',config.refresh),field('Нет поступлений после (мин)',input('threshold',{type:'number',min:1,max:10080,step:1,required:true,value:config.threshold}))],async d=>{
        const next={...config,period:d.get('period'),interval:d.get('interval'),source:d.get('source'),sourceName:source.selectedOptions[0]?.textContent,from:d.get('from')||'',to:d.get('to')||'',compare:d.has('compare'),refresh:d.has('refresh'),threshold:Number(d.get('threshold'))};
        rangeFor(next); if(!Number.isInteger(next.threshold)||next.threshold<1||next.threshold>10080)throw new Error('Порог: 1–10080 минут.');
        if(signal.aborted)return;state.dashboard=next;await render();
    });
    f.classList.add('analytics-controls');
    return card(f,h('p',{class:'muted small'},'Период по времени события (UTC), начало включено, конец исключён. Для выбора других источников используйте поиск и страницы ниже.'));
}
function sourcesPanel(config,sourcePage,range,project,now,signal) {
    const search=form('Найти источники',[field('Поиск источников',input('q',{value:config.sourceQuery,maxlength:255})),field('Статус источника',select('status',[['all','Все'],['active','Активные'],['disabled','Отключённые'],['archived','Архивные']],config.sourceStatus))],async d=>{if(signal.aborted)return;Object.assign(config,{sourceQuery:d.get('q'),sourceStatus:d.get('status'),cursor:null,previous:[]});await render();});
    const root=card(h('h2',{},'Состояние источников'),h('p',{class:'muted small'},'Текущий статус и последнее принятое событие (last_seen_at), а не время записи в хранилище. Отсутствие недавних поступлений не означает отказ сервиса.'),search);
    if(!sourcePage){root.append(empty('Список источников недоступен.'));return root;}
    const items=sourcePage.items;
    root.append(h('p',{class:'muted'},`Страница ${config.previous.length+1} · ${items.length} источников · порог ${config.threshold} мин.`),table(['Источник','Состояние','Последний приём (UTC)','Принято всего','Отклонено всего','Действия'],items.map(s=>[h('div',{},h('strong',{},s.name),h('div',{class:'muted small'},s.slug)),sourceHealth(s,now,config.threshold),date(s.last_seen_at),s.accepted_events_total??'—',s.rejected_events_total??'—',h('div',{class:'inline'},button('Выбрать',()=>{if(signal.aborted)return;config.source=s.id;config.sourceName=s.name;void render();},'',{disabled:config.source===s.id}),button('Логи',()=>drill(project,range,s.id)))])),h('div',{class:'actions'},button('Предыдущие источники',()=>{if(signal.aborted)return;config.cursor=config.previous.pop()??null;void render();},'',{disabled:!config.previous.length}),button('Следующие источники',()=>{if(signal.aborted)return;config.previous.push(config.cursor);config.cursor=sourcePage.next_cursor;void render();},'',{disabled:!sourcePage.next_cursor})));
    return root;
}
async function overview(signal, epoch, background=false) {
    const read=(path,query,signal)=>background?request(path,{query,signal,extraHeaders:{'X-Cajeer-Background':'1'}}):get(path,query,signal);
    const project=state.project;
    const root=h('div',{},heading('Обзор','Аналитика событий и состояние источников.',button('Обновить',()=>void render())),projectSelector());
    if(!project){root.append(needProject());return root;}
    if(!hasPermission('logs:read')){root.append(card(empty('Нет разрешения logs:read в этом проекте. Выберите другой проект.')));return root;}
    const config=state.dashboard?.project===project?state.dashboard:(state.dashboard=newConfig(project));
    const now=Date.now(),range=rangeFor(config,now),query={project_id:project,source_id:config.source||null,from:range.from,to:range.to};
    const results=await Promise.allSettled([read('/logs/stats',{...query,interval:range.interval},signal),read('/logs',{...query,limit:10,levels:config.critical?JSON.stringify(errorLevels.slice(1)):null},signal),read(`/projects/${encodeURIComponent(project)}/sources`,{limit:50,cursor:config.cursor,q:config.sourceQuery,status:config.sourceStatus},signal),config.compare?read('/logs/stats',{...query,...previousRange(range)},signal):Promise.resolve(null)]);
    if(signal.aborted)throw new DOMException('Route changed','AbortError');
    const [statsResult,logsResult,sourcesResult,previousResult]=results;
    const value=r=>r.status==='fulfilled'?r.value:null;
    const errors=(r,label)=>r.status==='rejected'?card(h('h2',{},label),message(r.reason)):null;
    const stats=value(statsResult),sources=value(sourcesResult),previous=value(previousResult),logs=value(logsResult);
    if(state.projects.find(p=>p.id===project)?.status==='archived')root.append(card(h('p',{class:'muted'},'Проект архивирован: новые события не принимаются; история доступна.')));
    root.append(controls(config,range,sources?.items||[],signal),h('p',{class:'muted small'},`${date(range.from)} — ${date(range.to)} · обновлено ${date(new Date(now).toISOString())}`));
    if(stats)root.append(metrics(stats,previous,range,project,config.source),timeline(stats,range,project,config.source),errorRateTrend(stats,range,project,config.source),levelTrend(stats,range,project,config.source,config),h('div',{class:'chart-grid'},bars('По уровням',levels.map(l=>({label:l,count:stats.by_level[l]||0})),x=>drill(project,range,config.source,{level:x.label})),bars('Топ источников (10)',stats.by_source.slice(0,10).map(x=>({...x,label:x.source})),x=>drill(project,range,x.source_id)),bars('Топ сервисов (10)',(stats.by_service||[]).map(x=>({...x,label:x.service})),x=>drill(project,range,config.source,{service:x.service})),bars('Топ хостов (10)',(stats.by_host||[]).map(x=>({...x,label:x.host})),x=>drill(project,range,config.source,{host:x.host}))));
    else root.append(errors(statsResult,'Метрики недоступны'));
    if(config.compare&&previous)root.append(comparisonPanel(stats,previous,range,project,config.source));
    if(config.compare&&previousResult.status==='rejected')root.append(errors(previousResult,'Сравнение недоступно'));
    root.append(sourcesPanel(config,sources,range,project,now,signal));if(!sources)root.append(errors(sourcesResult,'Состояние источников недоступно'));
    const recent=card(h('div',{class:'page-head'},h('h2',{},'Последние события'),h('div',{class:'inline'},button(config.critical?'Все уровни':'Только критические',()=>{config.critical=!config.critical;void render();}),button('Все логи',()=>drill(project,range,config.source,config.critical?{levels:JSON.stringify(errorLevels.slice(1))}:{})))));
    recent.append(logs?logTable(logs.items,project):message(logsResult.reason));root.append(recent);
    if(config.refresh){
        // Cancel on route/session changes. Do not overwrite an in-progress form.
        let timer;let dirty=false;root.addEventListener('input',()=>{dirty=true;});root.addEventListener('change',()=>{dirty=true;});
        const tick=()=>{if(signal.aborted)return;if(document.hidden||dirty){timer=setTimeout(tick,60000);return;}void render({background:true});};
        timer=setTimeout(tick,60000);signal.addEventListener('abort',()=>clearTimeout(timer),{once:true});
    }
    return root;
}
export { overview };
