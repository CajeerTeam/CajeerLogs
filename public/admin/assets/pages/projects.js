import { roles, state, projectPath, admin, manage, active, render } from '../app/store.js?v=0.3.4';
import { h, button, card, field, input, select, badge, date, enc, message, notify, modalEpoch, clearModal, modal, form, heading, needProject, table } from '../components/ui.js?v=0.3.4';
import { loadProjects, projectSelector } from '../app/projects.js?v=0.3.4';
import { pagedList } from '../components/pagination.js?v=0.3.4';
import { nameFields, nameBody, mutationSuccess, confirmAction } from '../components/mutations.js?v=0.3.4';
import { get, write, request, apiPrefix, ApiError } from '../api/client.js?v=0.3.4';

const sourceTypes = [['application','Приложение'],['server','Сервер'],['nginx','Nginx'],['aapanel','aaPanel'],['custom','Другой']];
const sourceStates = {active:'Активный',disabled:'Отключён',archived:'Архивный'};
// Rules are edited as JSON; preview uses synthetic sample events and never persists them.
async function redactionDialog(url, slug='app') {
    const authEpoch=state.authEpoch, route=state.route, previousModal=modalEpoch;
    const policy=await get(url); if(authEpoch!==state.authEpoch||!state.user||route!==state.route||previousModal!==modalEpoch)return;
    const rules=h('textarea',{name:'rules',rows:8,required:true,maxlength:16384},JSON.stringify(policy.rules,null,2));
    const sample=h('textarea',{name:'sample',rows:8,maxlength:65536},JSON.stringify({source:slug,level:'info',message:'Synthetic sample',timestamp:new Date().toISOString(),context:{customer_id:'example-id'}},null,2));
    const output=h('pre',{},'');
    const parseRules=()=>{const value=JSON.parse(rules.value);if(!Array.isArray(value))throw new Error('Правила должны быть JSON-массивом.');return value;};
    modal('Политика маскирования',h('div',{},h('p',{},'До 16 правил: {"type":"path","value":"/context/customer_id"}, key (имя поля), regex (маскирует всю строку). Политики складываются; базовая защита не отключается.'),
        form('Сохранить политику',[field('Правила JSON',rules)],async()=>{await write(url,'PUT',{revision:policy.revision,rules:parseRules()});if(authEpoch!==state.authEpoch)return;clearModal();}),
        form('Предпросмотр без записи',[field('Синтетическое событие JSON',sample)],async()=>{output.textContent='';const event=JSON.parse(sample.value);const result=await write(url+'/preview','POST',{rules:parseRules(),event});if(authEpoch===state.authEpoch)output.textContent=JSON.stringify(result.event,null,2);}),output));
}
function redactionButton(url,slug='app') { return button('Настроить маскирование',()=>redactionDialog(url,slug).catch(e=>modal('Политика маскирования',message(e)))); }
function sourceFields(r) {
    const num = (key,label,min,max) => field(label,input(key,{type:'number',min,max,step:1,value:r[key] ?? ''}));
    return [field('Название источника',input('name',{value:r.name,required:true,maxlength:255})),
        field('Тип источника',select('source_type',sourceTypes,r.source_type)),
        field('Описание источника',h('textarea',{name:'description',maxlength:4096},r.description||'')),
        field('Метаданные источника JSON',h('textarea',{name:'metadata',required:true,maxlength:8192},JSON.stringify(r.metadata||{},null,2))),
        num('retention_days','Хранение, дней (пусто = политика проекта)',1,3650),
        num('max_body_bytes','Максимум байт запроса (пусто = политика проекта)',1024,1048576),
        num('max_batch_events','Событий этого источника в пакете',1,100),
        num('events_per_minute','Событий в минуту (пусто = без отдельной квоты)',1,1000000)];
}
function sourceSection(path,signal,epoch) {
    const list=h('div');
    function display(query={}) {
        list.replaceChildren(pagedList({path:path+'/sources',query:{limit:50,...query},signal,epoch,headers:['Название','Slug','Тип','Статус','Последний приём','UUID',''],rows:r=>[r.name,r.slug,r.source_type||'application',badge(sourceStates[r.status]||'Активный'),date(r.last_seen_at),h('code',{},r.id),button('Открыть источник',()=>void sourceDetail(path,r.id,epoch,signal))]}));
    }
    display();
    return card(h('h2',{},'Источники'),manage()?form('Добавить источник',[...nameFields(),field('Тип источника',select('source_type',sourceTypes,'application'))],async d=>{await write(path+'/sources','POST',{...nameBody(d),source_type:String(d.get('source_type'))});await mutationSuccess(epoch,'Источник создан.');}):null,
        form('Найти источники',[field('Поиск источников',input('q',{maxlength:255})),field('Статус источника',select('status',[['all','Все'],...Object.entries(sourceStates)],'all'))],async d=>display({q:String(d.get('q')),status:String(d.get('status'))})),list);
}
async function sourceDetail(path,id,epoch,signal) {
    const url=path+'/sources/'+enc(id); const loading=h('div',{role:'status'},'Загрузка…'); modal('Источник',loading); const dialog=modalEpoch;
    try {
        const [r,s]=await Promise.all([get(url,{},signal),get(url+'/stats',{},signal)]);
        if (!active(epoch)||dialog!==modalEpoch) return;
        const root=h('div',{},h('h2',{},r.name),badge(sourceStates[r.status]||r.status),h('p',{},r.description||''),h('code',{},r.id),h('p',{},'Slug: '+r.slug),h('pre',{},JSON.stringify(r.metadata||{},null,2)),
            table(['Показатель','Значение'],[['Сохранено',s.events],['За 24 часа',s.events_24h],['За 60 секунд',s.events_60s],['Принято с 0.1.7',s.accepted_events_total],['Отклонено политиками',s.rejected_events_total],['Последний приём',date(s.last_seen_at)],['Последняя запись',date(s.last_received_at)],['Активные токены источника',s.active_tokens]]),
            h('h3',{},'Подключение'),h('p',{},'Создайте токен logs:write для этого источника в разделе API-токены. Передайте его через CAJEER_TOKEN.'),h('pre',{},JSON.stringify({source:r.slug,level:'info',message:'Hello CajeerLogs',timestamp:new Date().toISOString()},null,2)),h('code',{},location.origin+apiPrefix()+'/ingest'));
        if (admin()) root.append(redactionButton(url+'/redaction',r.slug));
        if (manage()) {
            root.append(form('Сохранить источник',sourceFields(r),async d=>{
                const metadata=JSON.parse(String(d.get('metadata')));if (!metadata||Array.isArray(metadata)||typeof metadata!=='object') throw new Error('Метаданные должны быть JSON-объектом.');
                const data={revision:String(r.revision),name:String(d.get('name')),source_type:String(d.get('source_type')),description:String(d.get('description')),metadata};
                for (const k of ['retention_days','max_body_bytes','max_batch_events','events_per_minute']) data[k]=d.get(k)===''?null:Number(d.get(k));
                await write(url,'PATCH',data);clearModal();await mutationSuccess(epoch,'Источник сохранён.');
            }));
            const actions=r.status==='archived'?[['restore','Восстановить источник']]:[[r.status==='active'?'disable':'enable',r.status==='active'?'Отключить источник':'Включить источник'],['archive','Архивировать источник']];
            root.append(h('div',{class:'actions'},actions.map(([action,label])=>button(label,()=>confirmAction(label,'История и ранее принятые задания сохраняются. Восстановление оставляет источник отключённым.',async()=>{await write(url+'/'+action,'POST',{revision:String(r.revision)});await mutationSuccess(epoch,'Состояние источника изменено.');}),'danger'))));
            root.append(form('Отправить тестовый лог',[field('Токен logs:write',input('token',{type:'password',required:true,autocomplete:'off',maxlength:512})),field('HMAC v2 key ID (необязательно)',input('signing_key_id',{maxlength:36})),field('HMAC v2 signing secret',input('signing_secret',{type:'password',autocomplete:'off',maxlength:67}))],async(d,f)=>{
                const token=String(d.get('token')); const kid=String(d.get('signing_key_id')||'').trim(); const secret=String(d.get('signing_secret')||''); f.elements.token.value='';f.elements.signing_secret.value='';
                if((kid||secret)&&(!/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/.test(kid)||!/^sk_[a-f0-9]{64}$/.test(secret))) throw new Error('Укажите корректные ID и секрет ключа HMAC v2.');
                if (!globalThis.crypto?.subtle) throw new Error('Для подписи запроса требуется HTTPS или localhost.');
                const identity=await request('/auth/token',{bearer:token,signal});
                if(identity.token?.project_id!==r.project_id||(identity.token.source_id!==null&&identity.token.source_id!==r.id)||!identity.token.scopes?.includes('logs:write')) throw new Error('Токен не разрешает запись в этот источник.');
                const body=JSON.stringify({source:r.slug,level:'info',message:'CajeerLogs source connection test',timestamp:new Date().toISOString()});
                const bytes=new TextEncoder(); const hex=v=>Array.from(new Uint8Array(v),b=>b.toString(16).padStart(2,'0')).join('');
                const timestamp=String(Math.floor(Date.now()/1000));const nonce=hex(crypto.getRandomValues(new Uint8Array(16))); const key='source-test-'+nonce;
                const endpoint=apiPrefix()+'/ingest';const digest=hex(await crypto.subtle.digest('SHA-256',bytes.encode(body)));
                const signingKey=await crypto.subtle.importKey('raw',bytes.encode(secret||token),{name:'HMAC',hash:'SHA-256'},false,['sign']);
                const signature=hex(await crypto.subtle.sign('HMAC',signingKey,bytes.encode([...(kid?['cajeerlogs-ingest-v2',kid]:['cajeerlogs-ingest-v1']),'POST',endpoint,timestamp,nonce,key,digest].join('\n'))));
                const resp=await request('/ingest',{method:'POST',body,bearer:token,raw:true,extraHeaders:{'Idempotency-Key':key,'X-Log-Timestamp':timestamp,'X-Log-Nonce':nonce,'X-Log-Signature':signature,...(kid?{'X-Log-Key-Id':kid}:{})},signal});
                const result=await resp.json();if(resp.status!==201||result.stored!==1||!result.event_ids?.[0]) throw new ApiError(resp.status,result);
                if(active(epoch)&&dialog===modalEpoch) {notify('Тестовое событие сохранено: '+result.event_ids[0]); await sourceDetail(path,id,epoch,signal);}
            }));
        }
        loading.replaceWith(root);
    } catch(e) {if(active(epoch)&&dialog===modalEpoch&&e.name!=='AbortError') loading.replaceWith(message(e));}
}
async function projects(signal, epoch) {
    const root = h('div', {}, heading('Проекты и источники', 'Управление доступными проектами и подключёнными приложениями.'), projectSelector());
    root.append(card(form('Найти проекты', [field('Поиск проектов', input('q', {value: state.projectQuery.q || '', maxlength: 255})), field('Статус проекта', select('status', [['all','Все'],['active','Активные'],['archived','Архивные']], state.projectQuery.status || 'all'))], async d => { state.projectQuery = {q: String(d.get('q')), status: String(d.get('status'))}; await loadProjects(signal); state.filters = null; state.logPosition = null; state.dashboard = null; if (active(epoch)) await render(); })));
    if (admin())
        root.append(card(h('h2', {}, 'Создать проект'), form('Создать проект', nameFields(), async (d) => { const r = await write('/projects', 'POST', nameBody(d)); if (!active(epoch))
            return; const created = await get('/projects/' + enc(r.id)); if (!active(epoch))
            return; state.projectQuery = {}; state.projects.push(created); state.project = created.id; state.filters = null; state.logPosition = null; state.dashboard = null; await mutationSuccess(epoch, 'Проект создан.'); })));
    if (!state.project) {
        root.append(needProject());
        return root;
    }
    const path = projectPath();
    const project = state.projects.find(p => p.id === state.project);
    root.append(card(h('h2', {}, project.name), h('p', { class: 'muted' }, 'Slug: ' + project.slug), h('code', {}, project.id), h('div', { class: 'actions' }, h('a', { href: '#logs' }, 'Открыть логи'), manage() ? h('a', { href: '#tokens' }, 'Управление токенами') : null)));
    root.append(card(h('h2', {}, 'Состояние проекта'), badge(project.status === 'archived' ? 'Архивный' : 'Активный'), h('p', {}, project.description || 'Описание не задано.'), h('p', {class:'muted'}, 'Архивация запрещает новые события. История и ранее принятые задания сохраняются; retention продолжает действовать.'), h('pre', {}, JSON.stringify(project.metadata || {}, null, 2))));
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
    const summary = await get(path + '/stats', {}, signal);
    root.append(card(h('h2', {}, 'Статистика проекта'), table(['Показатель','Значение'], [['Источники',summary.sources],['Участники',summary.members],['Активные токены',summary.active_tokens],['События',summary.events],['За 24 часа',summary.events_24h],['Задания в очереди',summary.queued_jobs],['Dead-letter',summary.dead_jobs],['Последнее событие',date(summary.last_received_at)]])));
    root.append(sourceSection(path, signal, epoch));
    if (admin()) {
        root.append(card(h('h2', {}, 'Участники проекта'), h('p', { class: 'muted' }, 'Укажите UUID пользователя из раздела «Пользователи». Администраторы имеют доступ ко всем проектам независимо от членства.'), form('Добавить участника', [field('UUID пользователя', input('user', { required: true, pattern: '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}' })), field('Роль в проекте', select('project_role', [['','Глобальная роль'],['operator','Оператор'],['viewer','Наблюдатель']], ''))], async (d) => { await write(path + '/members/' + enc(String(d.get('user'))), 'PUT', {role:d.get('project_role') || null}); await mutationSuccess(epoch, 'Доступ предоставлен.'); }), pagedList({ path: path + '/members', query: { limit: 50 }, signal, epoch, headers: ['Email', 'Глобальная роль', 'Роль проекта', 'UUID', ''], rows: r => [r.email, roles[r.role] || r.role, r.project_role ? roles[r.project_role] : 'Наследуется', h('code', {}, r.id), h('div', {class:'actions'}, button('Изменить роль', () => modal('Роль участника', form('Сохранить роль', [field('Роль в проекте', select('role', [['','Глобальная роль'],['operator','Оператор'],['viewer','Наблюдатель']], r.project_role || ''))], async d => { await write(path + '/members/' + enc(r.id), 'PUT', {role:d.get('role') || null}); clearModal(); await mutationSuccess(epoch,'Роль участника изменена.'); }))), button('Убрать доступ', () => confirmAction('Удалить участника', `Убрать ${r.email} из проекта?`, async () => { await write(path + '/members/' + enc(r.id), 'DELETE'); await mutationSuccess(epoch, 'Доступ удалён.'); }), 'danger'))] })));
    }
    return root;
}

export { projects, redactionButton };
