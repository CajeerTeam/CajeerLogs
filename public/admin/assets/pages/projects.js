import { roles, state, projectPath, admin, manage, hasPermission, active, render, selectProject } from '../app/store.js?v=0.7.5';
import { h, button, card, field, input, select, badge, date, enc, message, notify, modalEpoch, clearModal, modal, form, heading, needProject, table } from '../components/ui.js?v=0.7.5';
import { loadProjects, projectSelector } from '../app/projects.js?v=0.7.5';
import { assignmentDialog } from './rbac.js?v=0.7.5';
import { directoryPicker } from './users.js?v=0.7.5';
import { pagedList } from '../components/pagination.js?v=0.7.5';
import { nameFields, nameBody, mutationSuccess, confirmAction } from '../components/mutations.js?v=0.7.5';
import { get, write } from '../api/client.js?v=0.7.5';
import { sourceHealth } from '../app/analytics.js?v=0.7.5';
import { effectivePolicy, testPayload } from '../app/source-connection.js?v=0.7.5';
import { onboarding, verifyConnection, openSourceLogs } from '../components/source-onboarding.js?v=0.7.5';

const canConnect=()=>hasPermission('tokens:create')&&hasPermission('tokens:signing')&&hasPermission('logs:write')&&hasPermission('logs:read');
const sourceTypes = [['application','Приложение'],['server','Сервер'],['nginx','Nginx'],['aapanel','aaPanel'],['custom','Другой']];
const sourceStates = {active:'Активный',disabled:'Отключён',archived:'Архивный'};
// Rules are edited as JSON; preview uses synthetic sample events and never persists them.
async function redactionDialog(url, slug='app') {
    const epoch=state.epoch, signal=state.controller?.signal, previousModal=modalEpoch;
    const policy=await get(url,{},signal); if(!active(epoch)||signal?.aborted||previousModal!==modalEpoch)return;
    const rules=h('textarea',{name:'rules',rows:8,required:true,maxlength:16384},JSON.stringify(policy.rules,null,2));
    const sample=h('textarea',{name:'sample',rows:8,maxlength:65536},JSON.stringify({source:slug,level:'info',message:'Synthetic sample',timestamp:new Date().toISOString(),context:{customer_id:'example-id'}},null,2));
    const output=h('pre',{},'');
    const parseRules=()=>{const value=JSON.parse(rules.value);if(!Array.isArray(value))throw new Error('Правила должны быть JSON-массивом.');return value;};
    modal('Политика маскирования',h('div',{},h('p',{},'До 16 правил: {"type":"path","value":"/context/customer_id"}, key (имя поля), regex (маскирует всю строку). Политики складываются; базовая защита не отключается.'),
        form('Сохранить политику',[field('Правила JSON',rules)],async()=>{await write(url,'PUT',{revision:policy.revision,rules:parseRules()});if(!active(epoch)||signal?.aborted||!output.isConnected)return;clearModal();}),
        form('Предпросмотр без записи',[field('Синтетическое событие JSON',sample)],async()=>{output.textContent='';const event=JSON.parse(sample.value);const result=await write(url+'/preview','POST',{rules:parseRules(),event});if(active(epoch)&&!signal?.aborted&&output.isConnected)output.textContent=JSON.stringify(result.event,null,2);}),output));
}
function redactionButton(url,slug='app') { return button('Настроить маскирование',()=>{const epoch=state.epoch; redactionDialog(url,slug).catch(e=>{if(active(epoch)&&e.name!=='AbortError')modal('Политика маскирования',message(e));});}); }
function sourceFields(r) {
    const num = (key,label,min,max) => field(label,input(key,{type:'number',min,max,step:1,value:r[key] ?? ''}));
    return [field('Название источника',input('name',{value:r.name,required:true,maxlength:255})),
        field('Тип источника',select('source_type',sourceTypes,r.source_type)),
        field('Описание источника',h('textarea',{name:'description',maxlength:4096},r.description||'')),
        field('Метаданные источника JSON',h('textarea',{name:'metadata',required:true,maxlength:8192},JSON.stringify(r.metadata||{},null,2))),
        num('retention_days','Хранение, дней (пусто = без отдельного срока)',1,3650),
        num('max_body_bytes','Максимум байт запроса (пусто = политика проекта)',1024,1048576),
        num('max_batch_events','Событий этого источника в пакете (пусто = без отдельного лимита)',1,100),
        num('events_per_minute','Событий в минуту (пусто = без отдельной квоты)',1,1000000)];
}
function sourceSection(path,project,signal,epoch) {
    const list=h('div');
    const cfg=state.sourceManagement?.project===project.id?state.sourceManagement:{project:project.id,q:'',status:'all',source_type:'all',threshold:60};
    state.sourceManagement=cfg;
    function display() {
        list.replaceChildren(pagedList({path:path+'/sources',query:{limit:50,q:cfg.q,status:cfg.status,source_type:cfg.source_type},signal,epoch,headers:['Название','Slug / тип','Статус / активность','Последний приём','Принято / отклонено','UUID',''],rows:r=>[r.name,h('div',{},r.slug,h('small',{class:'muted'},sourceTypes.find(([id])=>id===r.source_type)?.[1]||r.source_type)),h('div',{},badge(sourceStates[r.status]||r.status),h('small',{class:'muted'},project.status==='archived'?'Проект архивный':sourceHealth(r,Date.now(),cfg.threshold))),date(r.last_seen_at),h('div',{},String(r.accepted_events_total??'—')+' / '+String(r.rejected_events_total??'—')),h('code',{},r.id),h('div',{class:'actions'},button('Открыть источник',()=>void sourceDetail(path,r.id,epoch,signal)),hasPermission('logs:read')?button('Логи источника',()=>openSourceLogs(project.id,r.id)):null,canConnect()&&project.status!=='archived'&&r.status==='active'?button('Подключить приложение',()=>onboarding(path,project,r,epoch,signal,sourceTypes)):null)]}));
    }
    display();
    return card(h('div',{class:'page-head'},h('h2',{},'Источники'),hasPermission('sources:create')&&canConnect()&&project.status!=='archived'?button('Мастер подключения',()=>onboarding(path,project,null,epoch,signal,sourceTypes),'primary'):null),
        h('p',{class:'muted'},'Активность определяется временем последнего принятого события, а не сетевой доступностью. Счётчики поступлений накопительные; retention удаляет только сохранённые события.'),
        hasPermission('sources:create')?form('Добавить источник',[...nameFields(),field('Тип источника',select('source_type',sourceTypes,'application'))],async d=>{await write(path+'/sources','POST',{...nameBody(d),source_type:String(d.get('source_type'))});await mutationSuccess(epoch,'Источник создан.');}):null,
        form('Найти источники',[field('Поиск источников',input('q',{maxlength:255,value:cfg.q,placeholder:'Имя, slug или UUID'})),field('Статус источника',select('status',[['all','Все'],...Object.entries(sourceStates)],cfg.status)),field('Фильтр типа источника',select('source_type',[['all','Все'],...sourceTypes],cfg.source_type)),field('Порог отсутствия поступлений, мин',input('threshold',{type:'number',min:1,max:10080,step:1,required:true,value:cfg.threshold}))],async d=>{Object.assign(cfg,{q:String(d.get('q')),status:String(d.get('status')),source_type:String(d.get('source_type')),threshold:Number(d.get('threshold'))});display();}),list);
}
function policyTable(project,source) {
    const effective=effectivePolicy(project,source);const fmt=v=>v==null?'Нет ограничения':String(v);
    return h('div',{},h('h3',{},'Действующие политики'),table(['Ограничение','Проект','Источник','Результат'],[
        ['Хранение, дней',fmt(project.retention_days),source.retention_days==null?'Наследуется':fmt(source.retention_days),fmt(effective.retention_days)],
        ['Размер запроса, байт',fmt(project.max_body_bytes),source.max_body_bytes==null?'Нет отдельного':fmt(source.max_body_bytes),fmt(effective.max_body_bytes)],
        ['Событий в пакете',fmt(project.max_batch_events),source.max_batch_events==null?'Нет отдельного':fmt(source.max_batch_events),fmt(effective.max_batch_events)],
        ['Событий в минуту',fmt(effective.project_events_per_minute),fmt(effective.source_events_per_minute),'Обе квоты применяются независимо']
    ]),h('p',{class:'muted'},'Ограничения размера и пакета применяются совместно с глобальными лимитами. Квота проекта общая для всех источников; остаток квоты здесь не вычисляется.'));
}
function projectDirectory(signal,epoch) {
    const box=h('div');const cfg=state.projectDirectory||{q:'',status:'all'};state.projectDirectory=cfg;
    function show(){box.replaceChildren(pagedList({path:'/projects',query:{limit:25,...cfg},signal,epoch,headers:['Проект','Slug / UUID','Статус','Доступ',''],rows:p=>[p.name,h('div',{},p.slug,h('div',{},h('code',{},p.id))),badge(p.status==='archived'?'Архивный':'Активный'),roles[p.effective_role]||p.effective_role||'—',button('Выбрать проект',async e=>{const b=e.currentTarget;b.disabled=true;try{const selected=await get('/projects/'+enc(p.id),{},signal);if(!active(epoch)||signal.aborted)return;state.projects=[...state.projects.filter(x=>x.id!==selected.id),selected];selectProject(selected.id);await render();}catch(err){if(active(epoch)&&err.name!=='AbortError')notify(err.message);}finally{b.disabled=false;}},'',{disabled:p.id===state.project})]}));}
    show();return card(h('h2',{},'Каталог проектов'),form('Найти проекты',[field('Поиск проектов',input('q',{value:cfg.q,maxlength:255,placeholder:'Имя, slug или UUID'})),field('Статус проекта',select('status',[['all','Все'],['active','Активные'],['archived','Архивные']],cfg.status))],async d=>{Object.assign(cfg,{q:String(d.get('q')),status:String(d.get('status'))});show();}),box);
}
function sourceMonitoringView(m) {
    if(!m) return h('p',{class:'muted'},'Мониторинг приёма недоступен.');
    const labels={receiving:'Недавно принимал',idle:'Нет недавних поступлений',never_received:'Нет поступлений',disabled:'Отключён',archived:'Архивный',unknown:'Неизвестно'};
    const health=m.health||{};
    const hourly=Array.isArray(m.hourly)?m.hourly:[];
    const max=Math.max(1,...hourly.map(x=>Number(x.accepted)+Number(x.rejected)));
    const values=[
        ['Наблюдаемая активность',labels[health.state]||health.state||'—'],
        ['Порог отсутствия поступлений',String(health.idle_threshold_minutes)+' мин'],
        ['Секунд с последнего приёма',health.seconds_since_last_admission??'Нет данных'],
        ['Принято / отклонено (60 минутных интервалов)',`${m.accepted_60m} / ${m.rejected_60m}`],
        ['Принято / отклонено (1440 минутных интервалов)',`${m.accepted_24h} / ${m.rejected_24h}`],
        ['Доля отклонений, 24 ч',`${m.rejection_percent_24h}%`],
        ['Средняя скорость за 60 мин',`${m.avg_accepted_per_minute_60m} событий/мин`],
        ['Пик за минуту, принятые',m.peak_accepted_per_minute],
        ['События в выборке сохранённых',m.recent_stored_sample?.size??'—'],
        ['Ошибки в выборке',m.recent_stored_sample?.errors??'—'],
        ['Задержка приёма p95, выборка',m.recent_stored_sample?.p95_delay_ms==null?'Нет данных':m.recent_stored_sample.p95_delay_ms+' мс'],
        ['Замер доступен с',date(m.tracked_since)],
    ];
    return h('section',{},h('h3',{},'Мониторинг приёма'),
        h('p',{class:'muted'},'Данные активности отражают успешные admission, а не проверку доступности приложения. Отклонения учитывают только аутентифицированные отказы по политикам. Почасовая история начинается с момента миграции; значения в минутных UTC-интервалах.'),
        table(['Показатель','Значение'],values),
        h('details',{},h('summary',{},'Почасовая нагрузка (UTC)'),
            h('div',{class:'chart-scroll'},hourly.map(x=>h('div',{class:'bar-row analytics-rank'},
                h('span',{},String(x.hour).slice(5,16).replace('T',' ')),
                h('progress',{max,value:Number(x.accepted)+Number(x.rejected),title:`Принято: ${x.accepted}, отклонено: ${x.rejected}`}),
                h('span',{},`${x.accepted} / ${x.rejected}`)))),
            table(['Час UTC','Принято','Отклонено'],hourly.map(x=>[x.hour,x.accepted,x.rejected]))));
}
async function sourceDetail(path,id,epoch,signal) {
    const url=path+'/sources/'+enc(id); const loading=h('div',{role:'status'},'Загрузка…'); modal('Источник',loading); const dialog=modalEpoch;
    try {
        const [r,s,project]=await Promise.all([get(url,{},signal),get(url+'/stats',{idle_minutes:state.sourceManagement?.threshold||60},signal),get(path,{},signal)]);
        if (!active(epoch)||dialog!==modalEpoch) return;
        const root=h('div',{},h('h2',{},r.name),badge(sourceStates[r.status]||r.status),h('p',{},r.description||''),h('code',{},r.id),h('p',{},'Slug: '+r.slug),h('pre',{},JSON.stringify(r.metadata||{},null,2)),
            table(['Показатель','Значение'],[['Сохранено',s.events],['За 24 часа (в хранилище)',s.events_24h],['За 60 секунд (в хранилище)',s.events_60s],['Принято всего',s.accepted_events_total],['Отклонено всего',s.rejected_events_total],['Последний приём',date(s.last_seen_at)],['Последняя запись',date(s.last_received_at)],['Активные токены источника',s.active_tokens]]),
            sourceMonitoringView(s.ingestion),
            policyTable(project,r),h('div',{class:'actions'},button('Обновить статистику источника',()=>void sourceDetail(path,id,epoch,signal)),hasPermission('logs:read')?button('Открыть логи источника',()=>openSourceLogs(r.project_id,r.id)):null,canConnect()&&project.status!=='archived'&&r.status==='active'?button('Подключить приложение',()=>onboarding(path,project,r,epoch,signal,sourceTypes),'primary'):null));
        if (admin()) root.append(redactionButton(url+'/redaction',r.slug));
        if (hasPermission('sources:update')) {
            root.append(form('Сохранить источник',sourceFields(r),async d=>{
                const metadata=JSON.parse(String(d.get('metadata')));if (!metadata||Array.isArray(metadata)||typeof metadata!=='object') throw new Error('Метаданные должны быть JSON-объектом.');
                const data={revision:String(r.revision),name:String(d.get('name')),source_type:String(d.get('source_type')),description:String(d.get('description')),metadata};
                for (const k of ['retention_days','max_body_bytes','max_batch_events','events_per_minute']) data[k]=d.get(k)===''?null:Number(d.get(k));
                await write(url,'PATCH',data);clearModal();await mutationSuccess(epoch,'Источник сохранён.');
            }));
        }
        if(hasPermission('sources:state')) {
            const actions=r.status==='archived'?[['restore','Восстановить источник']]:[[r.status==='active'?'disable':'enable',r.status==='active'?'Отключить источник':'Включить источник'],['archive','Архивировать источник']];
            root.append(h('div',{class:'actions'},actions.map(([action,label])=>button(label,()=>confirmAction(label,'История и ранее принятые задания сохраняются. Восстановление оставляет источник отключённым.',async()=>{await write(url+'/'+action,'POST',{revision:String(r.revision)});await mutationSuccess(epoch,'Состояние источника изменено.');}),'danger'))));
        }
        if(hasPermission('logs:write')&&hasPermission('logs:read')) {
            if(r.status==='active'&&project.status!=='archived') root.append(form('Отправить тестовый лог',[field('Токен logs:write',input('token',{type:'password',required:true,autocomplete:'off',maxlength:512})),field('HMAC v2 key ID (необязательно)',input('signing_key_id',{maxlength:36})),field('HMAC v2 signing secret',input('signing_secret',{type:'password',autocomplete:'off',maxlength:67}))],async(d,f)=>{
                const token=String(d.get('token'));const kid=String(d.get('signing_key_id')||'').trim();const secret=String(d.get('signing_secret')||'');f.elements.token.value='';f.elements.signing_secret.value='';
                const event=await verifyConnection(r,{token,kid,secret},testPayload(r.slug),signal);
                if(active(epoch)&&dialog===modalEpoch){notify('Тестовое событие сохранено: '+event.id);await sourceDetail(path,id,epoch,signal);}
            }));
        }
        loading.replaceWith(root);
    } catch(e) {if(active(epoch)&&dialog===modalEpoch&&e.name!=='AbortError') loading.replaceWith(message(e));}
}
async function projects(signal, epoch) {
    const root = h('div', {}, heading('Проекты и источники', 'Управление доступными проектами и подключёнными приложениями.'), projectSelector());
    root.append(projectDirectory(signal,epoch));
    if (admin())
        root.append(card(h('h2', {}, 'Создать проект'), form('Создать проект', nameFields(), async (d) => { const r = await write('/projects', 'POST', nameBody(d)); if (!active(epoch))
            return; const created = await get('/projects/' + enc(r.id)); if (!active(epoch))
            return; state.projectQuery = {}; state.projects.push(created); selectProject(created.id); await mutationSuccess(epoch, 'Проект создан.'); })));
    if (!state.project) {
        root.append(needProject());
        return root;
    }
    const path = projectPath();
    const project = state.projects.find(p => p.id === state.project);
    root.append(card(h('h2', {}, project.name), h('p', { class: 'muted' }, 'Slug: ' + project.slug), h('code', {}, project.id), h('div', { class: 'actions' }, hasPermission('logs:read')?button('Открыть логи проекта',()=>{state.filters=null;state.logPosition=null;location.hash='logs';}):null, (hasPermission('tokens:read')||hasPermission('services:read')) ? h('a', { href: '#tokens' }, 'Управление токенами') : null)));
    root.append(card(h('h2', {}, 'Состояние проекта'), badge(project.status === 'archived' ? 'Архивный' : 'Активный'), h('p', {}, project.description || 'Описание не задано.'), h('p', {class:'muted'}, 'Архивация запрещает новые события. История и ранее принятые задания сохраняются; retention продолжает действовать.'), h('pre', {}, JSON.stringify(project.metadata || {}, null, 2))));
    root.append(card(h('h2',{},'Политики проекта'),table(['Политика','Значение'],[['Хранение, дней',project.retention_days??'Без отдельного срока'],['Максимум байт запроса',project.max_body_bytes??'—'],['Событий в пакете',project.max_batch_events??'—'],['Событий в минуту',project.events_per_minute??'Без квоты проекта']])));
    if (admin()) {
        root.append(card(h('h2',{},'Маскирование проекта'),redactionButton(path+'/redaction')));
        root.append(card(h('h2',{},'HMAC-политика проекта'),button('Настроить обязательную подпись',async()=>{const policy=await get(path+'/signing'); if(!active(epoch))return; modal('HMAC проекта',form('Сохранить HMAC-политику',[field('Подпись ingest',select('required',[['false','Необязательная (глобальная политика действует)'],['true','Обязательная']],String(policy.require_ingest_hmac)))],async d=>{await write(path+'/signing','PUT',{required:d.get('required')==='true'});clearModal();await loadProjects(signal);await mutationSuccess(epoch,'HMAC-политика обновлена.');}));})));
        const numeric = (name, label, value, min, max, required = false) => field(label, input(name, {type:'number', min, max, step:1, value:value ?? '', required}));
        root.append(card(h('h2', {}, 'Редактирование и политики'), form('Сохранить проект', [
            field('Название проекта', input('name', {value:project.name, required:true, maxlength:255})),
            field('Описание проекта', h('textarea', {name:'description', maxlength:4096}, project.description || '')),
            field('Метаданные JSON', h('textarea', {name:'metadata', required:true, maxlength:8192}, JSON.stringify(project.metadata || {}, null, 2))),
            numeric('retention_days','Хранение, дней (пусто = без ограничения)',project.retention_days,1,3650),
            numeric('max_body_bytes','Максимум байт запроса',project.max_body_bytes ?? 1048576,1024,1048576,true),
            numeric('max_batch_events','Максимум событий в пакете',project.max_batch_events ?? 100,1,100,true),
            numeric('events_per_minute','Событий в минуту (пусто = без квоты)',project.events_per_minute,1,1000000)
        ], async d => {
            const metadata=JSON.parse(String(d.get('metadata'))); if (!metadata || Array.isArray(metadata) || typeof metadata !== 'object') throw new Error('Метаданные должны быть JSON-объектом.');
            const optional = k => d.get(k) === '' ? null : Number(d.get(k));
            await write(path, 'PATCH', {revision:String(project.revision), name:String(d.get('name')), description:String(d.get('description')), metadata, retention_days:optional('retention_days'), max_body_bytes:Number(d.get('max_body_bytes')), max_batch_events:Number(d.get('max_batch_events')), events_per_minute:optional('events_per_minute')});
            await loadProjects(signal); await mutationSuccess(epoch,'Проект обновлён.');
        }), button(project.status === 'archived' ? 'Восстановить проект' : 'Архивировать проект', () => confirmAction('Изменить состояние проекта', project.status === 'archived' ? 'Возобновить приём новых логов?' : 'Остановить новый ingest? История не удаляется.', async () => { await write(path + (project.status === 'archived' ? '/restore' : '/archive'), 'POST', {revision:String(project.revision)}); await loadProjects(signal); await mutationSuccess(epoch,'Состояние проекта изменено.'); }), 'danger')));
    }
    const statsBox=h('div');let statsEpoch=0;
    async function refreshStats(){const seq=++statsEpoch;statsBox.replaceChildren(h('p',{role:'status'},'Загрузка статистики…'));try{const summary=await get(path+'/stats',{},signal);if(!active(epoch)||signal.aborted||seq!==statsEpoch)return;statsBox.replaceChildren(table(['Показатель','Значение'], [['Источники',summary.sources],['Участники',summary.members],['Активные токены',summary.active_tokens],['События',summary.events],['За 24 часа',summary.events_24h],['Задания в очереди',summary.queued_jobs],['Dead-letter',summary.dead_jobs],['Последнее событие',date(summary.last_received_at)],['Активные источники',summary.active_sources??'—'],['Отключённые источники',summary.disabled_sources??'—'],['Архивные источники',summary.archived_sources??'—']]));}catch(e){if(active(epoch)&&seq===statsEpoch&&e.name!=='AbortError')statsBox.replaceChildren(message(e));}}
    root.append(card(h('h2',{},'Статистика проекта'),h('p',{class:'muted'},'События за 24 часа считаются по времени приёма; общее число — сохранённые записи.'),button('Обновить статистику проекта',()=>void refreshStats()),statsBox));void refreshStats();
    root.append(sourceSection(path, project, signal, epoch));
    if (admin()) {
        root.append(card(h('h2', {}, 'Участники проекта'), h('p', { class: 'muted' }, 'Найдите пользователя по email или UUID. Администраторы имеют доступ ко всем проектам независимо от членства.'), directoryPicker({path:'/users',label:'Поиск пользователя для проекта',query:{status:'active'},signal,epoch,display:u=>u.email,choose:u=>{const target=root.querySelector('input[name="user"]');if(target){target.value=u.id;target.focus();}}}), form('Добавить участника', [field('UUID пользователя', input('user', { required: true, pattern: '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}' })), field('Роль в проекте', select('project_role', [['','Глобальная роль'],['operator','Оператор'],['viewer','Наблюдатель']], ''))], async (d) => { await write(path + '/members/' + enc(String(d.get('user'))), 'PUT', {role:d.get('project_role') || null}); await mutationSuccess(epoch, 'Доступ предоставлен.'); }), pagedList({ path: path + '/members', query: { limit: 50 }, signal, epoch, headers: ['Email', 'Глобальная роль', 'Роль проекта', 'UUID', ''], rows: r => [r.email, roles[r.role] || r.role, h('div',{},r.project_role ? roles[r.project_role] : 'Наследуется',r.rbac_role_id?h('small',{class:'muted'},'Доп. роль: '+r.rbac_role_id):null), h('code', {}, r.id), h('div', {class:'actions'}, button('Изменить роль', () => modal('Роль участника', form('Сохранить роль', [field('Роль в проекте', select('role', [['','Глобальная роль'],['operator','Оператор'],['viewer','Наблюдатель']], r.project_role || ''))], async d => { await write(path + '/members/' + enc(r.id), 'PUT', {role:d.get('role') || null}); clearModal(); await mutationSuccess(epoch,'Роль участника изменена.'); }))), r.role!=='administrator'?button('Настроить разрешения',()=>assignmentDialog({scope:'project',user:r,project:{id:project.id,name:project.name,rbac_role_id:r.rbac_role_id},signal,epoch,refresh:()=>render()})):null, button('Убрать доступ', () => confirmAction('Удалить участника', `Убрать ${r.email} из проекта?`, async () => { await write(path + '/members/' + enc(r.id), 'DELETE'); await mutationSuccess(epoch, 'Доступ удалён.'); }), 'danger'))] })));
    }
    return root;
}

export { projects, redactionButton };
