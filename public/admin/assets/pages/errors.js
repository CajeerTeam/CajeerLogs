import { state, active, render, hasPermission } from '../app/store.js?v=1.0.6';
import { h, button, card, empty, date, heading, needProject, table, field, select, input, form, message, notify } from '../components/ui.js?v=1.0.6';
import { projectSelector } from '../app/projects.js?v=1.0.6';
import { navigate } from '../app/routes.js?v=1.0.6';
import { get, request } from '../api/client.js?v=1.0.6';
import { explorerFilters, utcInput, errorLevels } from '../app/analytics.js?v=1.0.6';

const num=n=>Number(n).toLocaleString('ru-RU');
const configFor=project=>({project,period:'24h',source_id:'',from:'',to:''});
function periodRange(c,now=Date.now()) {
    const widths={'24h':86400000,'7d':7*86400000,'30d':30*86400000};
    const from=c.period==='custom'?Date.parse(c.from+'Z'):now-widths[c.period];
    const to=c.period==='custom'?Date.parse(c.to+'Z'):now;
    if(!Number.isFinite(from)||!Number.isFinite(to)||from>=to||to-from>31*86400000)throw new Error('Диапазон должен быть до 31 дня. Время указывается в UTC.');
    if(c.source_id&&!/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(c.source_id))throw new Error('Некорректный UUID источника.');
    return {project_id:c.project,from:new Date(from).toISOString(),to:new Date(to).toISOString(),
        interval:to-from<=86400000?'hour':'day',...(c.source_id?{source_id:c.source_id}:{})};
}
function drill(project,range,fingerprint){
    if(!state.project||state.project!==project)return;
    state.filters={...explorerFilters(range,range.source_id||'',{
        levels:JSON.stringify(errorLevels),error_fingerprint:fingerprint
    }),limit:'50'};
    state.logPosition=null;navigate('logs');
}
function barChart(data){
    const rows=data.buckets||[],max=Math.max(1,...rows.map(x=>Number(x.count)));
    return card(h('h2',{},'Тренд ошибок (UTC)'),rows.length?rows.map(x=>h('div',{class:'bar-row'},
        h('span',{class:'small muted'},date(x.timestamp)),
        h('progress',{max,value:Number(x.count),'aria-label':x.timestamp}),
        h('strong',{},num(x.count)))):empty('За выбранный период ошибок нет.'));
}
function groupTable(data,project,range){
    const label={new:'Новая',regressed:'Рост',recurring:'Повторяется',unknown:'Нет точного сравнения'};
    return card(h('h2',{},'Группы ошибок'),
        h('p',{class:'muted small'},`Найдено ${num(data.group_count)} групп. ${data.groups_truncated?'Показаны первые 100.':''} Группировка по нормализованному сообщению и сервису; пример взят из сохранённого события.`),
        data.groups.length?table(['Группа / пример','Сейчас','Ранее','Тренд','Первый / последний','Действия'],
            data.groups.map(x=>[
                h('div',{},h('strong',{},x.example_message||x.fingerprint.slice(0,16)),h('div',{class:'muted small'},x.fingerprint.slice(0,20)+'… · Источников: '+num(x.source_count))),
                num(x.count),x.previous_count===null?'—':num(x.previous_count),
                h('span',{class:'muted small'},label[x.classification]||'—',x.change===null?'':` (${x.change>0?'+':''}${num(x.change)})`),
                h('div',{class:'small muted'},date(x.first_seen),' — ',date(x.last_seen)),
                button('События',()=>drill(project,range,x.fingerprint),'',{'aria-label':'Показать события группы '+x.fingerprint.slice(0,12)})
            ])):empty('Ошибки в этом периоде не обнаружены.'));
}
async function errors(signal,epoch){
    const root=h('div',{},heading('Аналитика ошибок','Группировка, сравнение периодов, тренды и отчёты JSON/CSV.'),projectSelector());
    if(!state.project){root.append(needProject());return root;}
    if(!hasPermission('logs:read')){root.append(card('Нет разрешения logs:read.'));return root;}
    const project=state.project;
    if(state.errorAnalytics?.project!==project)state.errorAnalytics=configFor(project);
    const c=state.errorAnalytics;
    const from=input('from',{type:'datetime-local',value:c.from,step:'1'}),to=input('to',{type:'datetime-local',value:c.to,step:'1'}),
        period=select('period',[['24h','24 часа'],['7d','7 дней'],['30d','30 дней'],['custom','Свой период (UTC)']],c.period);
    const source=input('source_id',{value:c.source_id,placeholder:'UUID (необязательно)',maxlength:36});
    const custom=h('div',{class:'form-grid'},field('Начало UTC',from),field('Конец UTC',to));
    function toggle(){custom.hidden=period.value!=='custom';from.disabled=to.disabled=custom.hidden;from.required=to.required=!custom.hidden;}
    period.addEventListener('change',toggle);toggle();
    const status=h('p',{role:'status',class:'muted'},'Загрузка…'),output=h('section',{}),actions=h('div',{class:'actions'});
    const apply=form('Применить',[field('Период',period),field('Источник',source),custom],async d=>{
        const next={...c,period:d.get('period'),source_id:d.get('source_id'),from:d.get('from'),to:d.get('to')};
        periodRange(next);state.errorAnalytics=next;await render();
    });
    root.append(card(apply),card(status,actions),output);
    let params;
    try{params=periodRange(c);}catch(e){status.replaceChildren(message(e));return root;}
    const valid=()=>active(epoch)&&!signal.aborted&&state.project===project;
    async function download(format){
        if(!hasPermission('logs:export')||!valid())return;
        try{
            const response=await request('/logs/errors/report',{query:{...params,format},signal,raw:true});
            const blob=await response.blob();if(!valid())return;
            const url=URL.createObjectURL(blob),a=h('a',{href:url,download:'cajeerlogs-error-report.'+format});
            document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);
        }catch(e){if(e.name!=='AbortError'&&valid())notify(e.message);}
    }
    if(hasPermission('logs:export'))actions.append(button('Скачать JSON',()=>void download('json')),button('Скачать CSV',()=>void download('csv')));
    try{
        const data=await get('/logs/errors',params,signal);if(!valid())return root;
        status.textContent=`Ошибок: ${num(data.total)} · В предыдущем равном периоде: ${num(data.previous_total)} · Изменение: ${data.change>0?'+':''}${num(data.change)}. Интервал по occurred_at (UTC).`;
        output.replaceChildren(barChart(data),groupTable(data,project,params));
    }catch(e){if(valid()&&e.name!=='AbortError')status.replaceChildren(message(e));}
    return root;
}
export {errors};
