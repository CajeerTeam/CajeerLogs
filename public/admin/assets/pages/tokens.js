import { state, projectPath, admin, hasPermission, canIssueScope, active, render } from '../app/store.js?v=0.8.2';
import { h, button, card, field, input, select, checkbox, empty, badge, date, enc, message, clearModal, modalEpoch, modal, form, heading, needProject, copy, table, notify } from '../components/ui.js?v=0.8.2';
import { projectSelector } from '../app/projects.js?v=0.8.2';
import { pagedList } from '../components/pagination.js?v=0.8.2';
import { mutationSuccess, confirmAction } from '../components/mutations.js?v=0.8.2';
import { get, write, request } from '../api/client.js?v=0.8.2';

let tokenIssuing = false;
function tokenMetadataFields(r = {}) {
    return [field('Название', input('name', { required:true, maxlength:255, value:r.name || '' })), field('Описание', h('textarea',{name:'description',maxlength:2000},r.description || '')), field('Запросов в минуту (необязательно)',input('rate',{type:'number',min:1,max:1000000,value:r.requests_per_minute ?? ''}))];
}
function tokenMetadata(d) { return {name:String(d.get('name')).trim(),description:String(d.get('description') || ''),requests_per_minute:d.get('rate') ? Number(d.get('rate')) : null}; }
function showCredential(result) {
    modal('Новый API-токен', h('div', {}, h('p', {}, 'Сохраните секрет сейчас. Сервер не покажет его повторно.'), result.previous ? h('p',{},'Старый токен действует до '+date(result.previous.expires_at)+'. После переключения отзовите его. Повторяйте незавершённые запросы старым токеном, пока он действителен.') : null, h('pre', { class: 'secret' }, result.token), result.signing?.signing_secret ? signingSecretView(result.signing) : null, h('div', { class: 'actions' }, button('Скопировать токен', () => void copy(result.token), 'primary'), button('Закрыть', clearModal))));
}
function signingSecretView(result) {
    return h('div',{},h('p',{},'Отдельный ключ HMAC: сохраните ID и секрет сейчас.'),h('code',{},result.current_key_id),h('pre',{class:'secret'},result.signing_secret),button('Скопировать signing secret',()=>void copy(result.signing_secret)));
}
function detailsTable(values) { return table(['Поле','Значение'], values.map(([k,v])=>[k,h('span',{class:'credential-value'},v ?? '—')])); }
function showError(epoch,title,error) { if(active(epoch)&&error.name!=='AbortError') modal(title,message(error)); }
async function tokenDetails(url,epoch,signal) {
    const previousModal=modalEpoch;
    const r=await get(url,{},signal); if(!active(epoch)||signal.aborted||previousModal!==modalEpoch)return;
    const s=r.signing;
    modal('Карточка токена',h('div',{},detailsTable([
        ['Название',r.name],['UUID',r.id],['Описание',r.description],['Scopes',r.scopes.join(', ')],['Проект',r.project_id],['Источник',r.source_id||'Весь проект'],['Сервисный аккаунт',r.service_account_id],['Создал',r.created_by],['Создан',date(r.created_at)],['Истекает',date(r.expires_at)],['Последнее использование',date(r.last_used_at)],['Отозван',date(r.revoked_at)],['Квота запросов/мин',r.requests_per_minute],['Предыдущий токен',r.predecessor_id],['Токен-замена',r.rotated_to_id],['Ротация',date(r.rotated_at)],['HMAC',s? (s.mode==='separate'?'v2 / отдельный ключ':s.mode==='bearer'?'v1 / обязательный':'Необязательный'):'Не применяется'],['Текущий ключ HMAC',s?.current_key_id],['Предыдущий ключ HMAC',s?.previous_key_id],['Перекрытие ключей до',date(s?.previous_until)]
    ]),h('p',{class:'muted'},'Секреты недоступны после выпуска. Последнее использование обновляется сервером с интервалом до пяти минут.'),button('Копировать UUID',()=>void copy(r.id)),button('Закрыть',clearModal)));
}
async function accountDetails(path,r,epoch,signal,onTokens) {
    const previousModal=modalEpoch;
    const a=await get(path+'/'+enc(r.id),{},signal); if(!active(epoch)||signal.aborted||previousModal!==modalEpoch)return;
    modal('Карточка сервисной учётной записи',h('div',{},detailsTable([['Название',a.name],['UUID',a.id],['Создал',a.created_by],['Создана',date(a.created_at)],['Отключена',date(a.disabled_at)],['Всего токенов',a.total_tokens],['Активных токенов',a.active_tokens],['Последнее использование',date(a.last_used_at)]]),hasPermission('tokens:read')?button('Показать токены сервиса',()=>{clearModal();onTokens(a.id);},'primary'):null,button('Закрыть',clearModal)));
}
export function auditSection(path,signal,epoch,system=false,open=false) {
    const list=h('div'); let currentQuery={};
    const filters=form('Фильтровать аудит',[
        field('Действие аудита',select('action',[['','Все действия'],['logs.exported','Экспорт логов'],['token.created','Выпуск токена'],['token.updated','Изменение токена'],['token.rotated','Ротация токена'],['token.revoked','Отзыв токена'],['token.bulk_revoked','Пакетный отзыв'],['token.signing_changed','Изменение HMAC'],['token.signing_previous_retired','Завершение перекрытия HMAC'],['service_account.created','Создание сервиса'],['service_account.disabled','Отключение сервиса'],['project.signing_policy','Политика HMAC'],['project.member_role_set','Изменение роли проекта'],['role.project_assigned','Назначение пользовательской роли проекта'],...(system?[['security.access_denied','Отказ в доступе'],['auth.reauthenticated','Подтверждение личности'],['auth.reauthentication_failed','Ошибка подтверждения'],['auth.totp_key_rotated','Ротация ключа TOTP'],['role.created','Создание роли'],['role.updated','Изменение роли'],['role.deleted','Удаление роли'],['role.global_assigned','Назначение глобальной роли'],['auth.login','Вход'],['auth.login_failed','Неудачный вход'],['user.updated','Изменение пользователя'],['user.totp_reset','Сброс 2FA']]:[])],'')),
        field('UUID инициатора',input('actor_id',{maxlength:36})),field('UUID объекта / ID задания',input('target',{maxlength:36})),
        field('Результат',select('result',[['','Все'],['success','Успех'],['failure','Ошибка'],['denied','Отказ']],'')),
        field('Тип инициатора',select('actor_type',[['','Все'],['user','Пользователь'],['token','Токен'],['system','Система'],['anonymous','Анонимный']],'')),
        field('Начало UTC',input('from',{type:'datetime-local',step:1})),field('Конец UTC (не включительно)',input('to',{type:'datetime-local',step:1}))],async d=>{
        const query=Object.fromEntries([...d].map(([k,v])=>[k,String(v).trim()]));
        for(const k of ['from','to']) if(query[k]) query[k]+=(query[k].length===16?':00':'')+'Z';
        load(query);
    });
    function load(query={}) {currentQuery=query;list.replaceChildren(pagedList({path,query:{limit:50,...query},signal,epoch,headers:['Время UTC','Действие','Результат','Инициатор / объект','Детали'],rows:r=>[date(r.created_at),r.action,r.result||'—',h('div',{},h('code',{},r.actor_id||'Система'),h('div',{},h('code',{},r.target||'—'))),button('Запись аудита',()=>modal('Запись аудита',detailsTable(Object.entries(r).filter(([,v])=>v!==null).map(([k,v])=>[k,k==='created_at'?date(v):v]))))]}));}
    const download=button('Экспорт NDJSON (до 100 записей)',async()=>{
        try {
            const authEpoch=state.authEpoch;
            const response=await request(path,{query:{...currentQuery,limit:100,format:'ndjson'},signal,raw:true});
            const blob=await response.blob(); if(!active(epoch)||signal.aborted||authEpoch!==state.authEpoch)return;
            const url=URL.createObjectURL(blob); const link=h('a',{href:url,download:'cajeerlogs-audit.ndjson'});document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);
            notify(response.headers.get('X-Next-Cursor')?'Экспортированы первые 100 записей. Уточните фильтры для следующего диапазона.':'Аудит экспортирован.');
        } catch(error) {showError(epoch,'Экспорт аудита',error);}
    });
    // Lazy initial load: do not query both audit scopes on every visit.
    const details=h('details',{open},h('summary',{},system?'Глобальный журнал безопасности':'Журнал безопасности проекта'),h('p',{class:'muted'},'Только чтение. Секреты и произвольный audit context не возвращаются.'),filters,download,list);
    let loaded=open;if(open)load();details.addEventListener('toggle',()=>{if(details.open&&!loaded){loaded=true;load();}});return card(details);
}
async function signingDialog(url,epoch,signal) {
    const authEpoch=state.authEpoch, previousModal=modalEpoch; const meta=await get(url+'/signing',{},signal); if(!active(epoch)||signal.aborted||authEpoch!==state.authEpoch||previousModal!==modalEpoch)return;
    const previous=meta.previous_key_id&&meta.previous_until;
    modal('Подпись ingest',h('div',{},h('p',{},meta.current_key_id?'Текущий ключ: '+meta.current_key_id:meta.required?'HMAC v1 обязателен':'Подпись определяется политикой проекта и сервера.'),previous?h('p',{},'Предыдущий ключ '+meta.previous_key_id+' действует до '+date(meta.previous_until)):null,
        previous?button('Завершить перекрытие HMAC',()=>confirmAction('Завершить перекрытие HMAC','Предыдущий ключ перестанет работать. Текущий ключ не изменится.',async()=>{await write(url+'/signing/previous','DELETE',{expected_key_id:meta.current_key_id,expected_previous_key_id:meta.previous_key_id});if(active(epoch)){clearModal();await mutationSuccess(epoch,'Предыдущий ключ отозван.');}}),'danger'):null,
        h('p',{class:'muted'},'Ротация сохраняет не более одного предыдущего ключа. Первая выдача отдельного ключа немедленно отключает подпись Bearer.'),
        form('Сохранить / ротировать ключ',[field('Режим',select('mode',meta.current_key_id?[['separate','Ротировать отдельный ключ']]:[['bearer','Обязательный HMAC v1'],['separate','Отдельный ключ HMAC v2']],meta.current_key_id?'separate':'bearer')),field('Перекрытие, секунд (только при ротации v2)',input('overlap',{type:'number',min:0,max:604800,value:0,required:true}))],async d=>{const result=await write(url+'/signing','POST',{mode:String(d.get('mode')),overlap_seconds:Number(d.get('overlap')),expected_key_id:meta.current_key_id});if(authEpoch!==state.authEpoch||!state.user)return;if(!active(epoch)){notify('Ключ изменён. Проверьте credentials перед использованием.');return;}clearModal();if(result.signing_secret)modal('Новый ключ HMAC',h('div',{},signingSecretView(result),button('Закрыть',clearModal)));else await mutationSuccess(epoch,'Обязательная подпись включена.');}))); 
}
async function issueCredential(path,body,epoch) {
    if(tokenIssuing) throw new Error('Дождитесь завершения выпуска предыдущего токена.');
    tokenIssuing=true; const authEpoch=state.authEpoch;
    try { const result=await write(path,'POST',body); if(authEpoch!==state.authEpoch||!state.user) return; if(!active(epoch)){notify('Токен выпущен после смены раздела. Отзовите его, если секрет не сохранён.');return;} const project=state.project; const route=state.route; await render(); if(authEpoch===state.authEpoch&&state.user&&state.route===route&&state.project===project&&state.epoch===epoch+1) showCredential(result); }
    finally {tokenIssuing=false;}
}
function serviceAccountSection(path,signal,epoch,onTokens) {
    const list=h('div');
    function load(query={}) {list.replaceChildren(pagedList({path,query:{limit:50,...query},signal,epoch,headers:['Название','UUID','Статус',''],rows:r=>[r.name,h('code',{},r.id),badge(r.disabled_at?'Отключена':'Активна'),h('div',{class:'actions'},button('Карточка сервиса',()=>accountDetails(path,r,epoch,signal,onTokens).catch(e=>showError(epoch,'Сервис',e))),r.disabled_at||!hasPermission('services:manage')||!hasPermission('tokens:revoke')?null:button('Отключить сервис',()=>confirmAction('Отключить сервисную учётную запись','Все связанные токены будут отозваны. Отмена невозможна.',async()=>{await write(path+'/'+enc(r.id)+'/disable','POST',{confirm:true});await mutationSuccess(epoch,'Сервис отключён.');}),'danger'))]}));}
    load();
    return card(h('h2',{},'Сервисные учётные записи'),h('p',{class:'muted'},'Именованный владелец credentials проекта, без браузерного входа. Отключение отзывает все связанные токены.'),hasPermission('services:manage')?form('Создать сервисную учётную запись',[field('Название сервиса',input('name',{required:true,maxlength:255}))],async d=>{await write(path,'POST',{name:String(d.get('name')).trim()});await mutationSuccess(epoch,'Сервисная учётная запись создана.');}):null,form('Найти сервис',[field('Поиск сервиса',input('q',{maxlength:255})),field('Статус сервиса',select('status',[['all','Все'],['active','Активные'],['disabled','Отключённые']],'all'))],async d=>load({q:String(d.get('q')).trim(),status:d.get('status')})),list);
}
function tokenSection(path, sources, signal, epoch, system = false, accounts = [], registerFilter = () => {}) {
    const fields=[...tokenMetadataFields(),field('Срок, дней',input('days',{type:'number',min:1,max:365,step:1,value:30,required:true}))];
    if(!system) fields.push(field('Источник (UUID, необязательно)',input('source_id',{list:'token-sources',maxlength:36,placeholder:'Весь проект'})),field('Сервисная учётная запись (UUID, необязательно)',input('service_account_id',{list:'token-accounts',maxlength:36})),h('div',{},h('strong',{class:'small'},'Разрешения'),canIssueScope('logs:read')?checkbox('read','logs:read',true):null,canIssueScope('logs:write')?checkbox('write','logs:write',!canIssueScope('logs:read')):null),hasPermission('tokens:signing')&&canIssueScope('logs:write')?field('Подпись ingest',select('signing_mode',[['','Политика проекта'],['bearer','Обязательный HMAC v1'],['separate','Отдельный HMAC v2']],'')):null);
    const formEl=form('Выпустить токен',fields,async d=>{const scopes=system?['system:read']:[...(d.has('read')?['logs:read']:[]),...(d.has('write')?['logs:write']:[])];if(!scopes.length) throw new Error('Выберите хотя бы одно разрешение.'); const body={...tokenMetadata(d),scopes,expires_in:Number(d.get('days'))*86400};if(!system&&d.get('source_id')) body.source_id=String(d.get('source_id')).trim();if(!system&&d.get('service_account_id')) body.service_account_id=String(d.get('service_account_id')).trim();if(!system&&d.get('signing_mode'))body.signing_mode=String(d.get('signing_mode'));await issueCredential(path,body,epoch);});
    if(!system) formEl.append(h('datalist',{id:'token-sources'},sources.map(s=>h('option',{value:s.id},s.name+' ('+s.slug+')'))),h('datalist',{id:'token-accounts'},accounts.filter(a=>!a.disabled_at).map(a=>h('option',{value:a.id},a.name))));
    const bulkFields=[field('Область отзыва',select('kind',system?[['all','Все системные токены']]:[['all','Все токены проекта'],['source','Токены источника'],['account','Токены сервиса']],'all'))];
    if(!system) bulkFields.push(field('UUID источника или сервиса',input('id',{maxlength:36})));
    const bulk=form('Пакетный отзыв',bulkFields,async d=>{const kind=d.get('kind'),id=String(d.get('id')||'').trim();if(kind!=='all'&&!id) throw new Error('Укажите UUID.'); const body={confirm:true};if(kind==='source')body.source_id=id;if(kind==='account')body.service_account_id=id;confirmAction('Пакетный отзыв','Отозвать все credentials выбранной области? Это необратимо.',async()=>{const result=await write(path+'/revoke-all','POST',body);await mutationSuccess(epoch,'Отозвано: '+result.revoked);});});
    const list=h('div'); const filters=select('status',[['all','Все'],['active','Активные'],['expiring','Истекают за 7 дней'],['expired','Истёкшие'],['revoked','Отозванные']],'all');
    let query={}; const searchFields=[field('Поиск токена',input('q',{maxlength:255,placeholder:'Название или UUID'})),field('Scope',select('scope',[['','Все'],...(system?[['system:read','system:read']]:[['logs:read','logs:read'],['logs:write','logs:write']])],'')),field('Статус токена',filters)];
    if(!system)searchFields.push(field('Фильтр UUID источника',input('source_id',{maxlength:36,list:'token-sources'})),field('Фильтр UUID сервиса',input('service_account_id',{maxlength:36,list:'token-accounts'})));
    const searchForm=form('Найти токены',searchFields,async d=>{query=Object.fromEntries([...d].map(([k,v])=>[k,String(v).trim()]));renderList();});
    registerFilter(id=>{searchForm.elements.service_account_id.value=id;query={service_account_id:id,status:filters.value};searchForm.elements.q.value='';searchForm.elements.scope.value='';searchForm.elements.source_id.value='';renderList();searchForm.scrollIntoView({block:'start'});});
    function renderList(){list.replaceChildren(pagedList({path,query:{limit:50,...query,status:filters.value},signal,epoch,headers:['Название / UUID','Scopes','Источник / сервис','Истекает','Последнее использование','Статус',''],rows:r=>{
        const expired=r.expires_at&&Date.parse(r.expires_at)<=Date.now();const valid=!r.revoked_at&&!expired;const url=path+'/'+enc(r.id);
        const actions=h('div',{class:'actions'},button('Карточка токена',()=>tokenDetails(url,epoch,signal).catch(e=>showError(epoch,'Токен',e))),!system&&hasPermission('tokens:signing')&&valid&&r.scopes.includes('logs:write')?button('HMAC',()=>signingDialog(url,epoch,signal).catch(e=>{if(active(epoch))modal('Подпись ingest',message(e));})):null,!r.revoked_at&&(system||hasPermission('tokens:update'))?button('Изменить токен',()=>modal('Редактировать токен',form('Сохранить токен',tokenMetadataFields(r),async d=>{await write(url,'PATCH',{revision:r.revision,...tokenMetadata(d)});clearModal();await mutationSuccess(epoch,'Токен изменён.');}))):null,
            valid&&!r.rotated_to_id&&(system||hasPermission('tokens:rotate')&&r.scopes.some(canIssueScope))?button('Ротация',()=>{const fields=[field('Срок нового токена, дней',input('days',{type:'number',min:1,max:365,value:30,required:true})),field('Перекрытие, секунд (0 = немедленно)',input('overlap',{type:'number',min:0,max:604800,value:3600,required:true})),h('div',{},...r.scopes.map((s,i)=>system||canIssueScope(s)?checkbox('scope_'+i,s,true):null))];modal('Ротация токена',form('Выпустить замену',fields,async d=>{const scopes=r.scopes.filter((s,i)=>d.has('scope_'+i));if(!scopes.length)throw new Error('Выберите разрешение.');await issueCredential(url+'/rotate',{revision:r.revision,expires_in:Number(d.get('days'))*86400,overlap_seconds:Number(d.get('overlap')),scopes},epoch);}));}):null,
            !r.revoked_at&&(system||hasPermission('tokens:revoke'))?button('Отозвать',()=>confirmAction('Отозвать токен',`Токен «${r.name}» перестанет работать немедленно.`,async()=>{await write(url,'DELETE');await mutationSuccess(epoch,'Токен отозван.');}),'danger'):null);
        return [h('div',{},r.name,h('div',{class:'muted'},r.description||''),h('code',{},r.id),r.rotated_to_id?h('div',{class:'muted'},'Замена: '+r.rotated_to_id):null),(r.scopes||[]).join(', '),h('div',{},r.source_id?h('code',{},r.source_id):'Все',r.service_account_id?h('div',{},h('code',{},r.service_account_id)):null),date(r.expires_at),date(r.last_used_at),badge(r.revoked_at?'Отозван':expired?'Истёк':r.rotated_to_id?'Перекрытие':r.expires_at&&Date.parse(r.expires_at)-Date.now()<=604800000?'Истекает':'Активен'),actions];
    }}));} filters.addEventListener('change',renderList);renderList();
    return card(h('h2',{},system?'Системные токены':'Токены проекта'),h('p',{class:'muted'},system?'Только scope system:read.':'Токен может читать и/или записывать логи всего проекта либо одного источника.'),(system||hasPermission('tokens:create')&&(canIssueScope('logs:read')||canIssueScope('logs:write')))?formEl:null,(system||hasPermission('tokens:revoke'))?h('details',{},h('summary',{},'Пакетный отзыв credentials'),bulk):null,searchForm,list);
}
async function tokens(signal, epoch) { const root = h('div', {}, heading('API-токены', 'Выпуск и отзыв сервисных credentials.'), projectSelector()); if (state.project) {
    const path = projectPath();
    const sources = await get(path + '/sources', { limit: 100 }, signal);
    let filterAccount=()=>{};
    const accounts=hasPermission('services:read')?await get(path+'/service-accounts',{limit:100},signal):{items:[]};
    const section=hasPermission('tokens:read')?tokenSection(path+'/tokens',sources.items,signal,epoch,false,accounts.items,fn=>{filterAccount=fn;}):null;
    if(hasPermission('services:read'))root.append(serviceAccountSection(path+'/service-accounts',signal,epoch,id=>filterAccount(id)));
    if(section)root.append(section);
    if(hasPermission('audit:read'))root.append(auditSection(path+'/security/audit',signal,epoch));
    if(!section&&!hasPermission('services:read'))root.append(card(empty('Нет разрешений на токены или сервисные аккаунты в этом проекте.')));
}
else
    root.append(needProject()); if (admin())
    root.append(tokenSection('/system/tokens', [], signal, epoch, true),auditSection('/system/security/audit',signal,epoch,true)); return root; }

export { tokens };
