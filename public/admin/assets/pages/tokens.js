import { state, projectPath, admin, manage, active, render } from '../app/store.js?v=0.3.7';
import { h, button, card, field, input, select, checkbox, empty, badge, date, enc, message, clearModal, modal, form, heading, needProject, copy } from '../components/ui.js?v=0.3.7';
import { projectSelector } from '../app/projects.js?v=0.3.7';
import { pagedList } from '../components/pagination.js?v=0.3.7';
import { mutationSuccess, confirmAction } from '../components/mutations.js?v=0.3.7';
import { get, write } from '../api/client.js?v=0.3.7';

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
async function signingDialog(url,epoch) {
    const authEpoch=state.authEpoch; const meta=await get(url+'/signing'); if(!active(epoch)||authEpoch!==state.authEpoch)return;
    modal('Подпись ingest',h('div',{},h('p',{},meta.current_key_id?'Текущий ключ: '+meta.current_key_id:meta.required?'HMAC v1 обязателен':'Подпись определяется политикой проекта и сервера.'),meta.previous_until?h('p',{},'Предыдущий ключ действует до '+date(meta.previous_until)):null,
        form('Сохранить / ротировать ключ',[field('Режим',select('mode',meta.current_key_id?[['separate','Ротировать отдельный ключ']]:[['bearer','Обязательный HMAC v1'],['separate','Отдельный ключ HMAC v2']],meta.current_key_id?'separate':'bearer')),field('Перекрытие, секунд (только при ротации v2)',input('overlap',{type:'number',min:0,max:604800,value:0,required:true}))],async d=>{const result=await write(url+'/signing','POST',{mode:String(d.get('mode')),overlap_seconds:Number(d.get('overlap')),expected_key_id:meta.current_key_id});if(authEpoch!==state.authEpoch||!state.user)return;clearModal();if(result.signing_secret)modal('Новый ключ HMAC',h('div',{},signingSecretView(result),button('Закрыть',clearModal)));else await mutationSuccess(epoch,'Обязательная подпись включена.');})));
}
async function issueCredential(path,body,epoch) {
    if(tokenIssuing) throw new Error('Дождитесь завершения выпуска предыдущего токена.');
    tokenIssuing=true; const authEpoch=state.authEpoch;
    try { const result=await write(path,'POST',body); if(authEpoch!==state.authEpoch||!state.user) return; if(active(epoch)) await render(); if(authEpoch===state.authEpoch&&state.user) showCredential(result); }
    finally {tokenIssuing=false;}
}
function serviceAccountSection(path,signal,epoch) {
    return card(h('h2',{},'Сервисные учётные записи'),h('p',{class:'muted'},'Именованный владелец credentials проекта, без браузерного входа. Отключение отзывает все связанные токены.'),form('Создать сервисную учётную запись',[field('Название сервиса',input('name',{required:true,maxlength:255}))],async d=>{await write(path,'POST',{name:String(d.get('name')).trim()});await mutationSuccess(epoch,'Сервисная учётная запись создана.');}),pagedList({path,query:{limit:50},signal,epoch,headers:['Название','UUID','Статус',''],rows:r=>[r.name,h('code',{},r.id),badge(r.disabled_at?'Отключена':'Активна'),r.disabled_at?'—':button('Отключить сервис',()=>confirmAction('Отключить сервисную учётную запись','Все связанные токены будут отозваны. Отмена невозможна.',async()=>{await write(path+'/'+enc(r.id)+'/disable','POST',{confirm:true});await mutationSuccess(epoch,'Сервис отключён.');}),'danger')]}));
}
function tokenSection(path, sources, signal, epoch, system = false, accounts = []) {
    const fields=[...tokenMetadataFields(),field('Срок, дней',input('days',{type:'number',min:1,max:365,step:1,value:30,required:true}))];
    if(!system) fields.push(field('Источник (UUID, необязательно)',input('source_id',{list:'token-sources',maxlength:36,placeholder:'Весь проект'})),field('Сервисная учётная запись (UUID, необязательно)',input('service_account_id',{list:'token-accounts',maxlength:36})),h('div',{},h('strong',{class:'small'},'Разрешения'),checkbox('read','logs:read',true),checkbox('write','logs:write')),field('Подпись ingest',select('signing_mode',[['','Политика проекта'],['bearer','Обязательный HMAC v1'],['separate','Отдельный HMAC v2']],'')));
    const formEl=form('Выпустить токен',fields,async d=>{const scopes=system?['system:read']:[...(d.has('read')?['logs:read']:[]),...(d.has('write')?['logs:write']:[])];if(!scopes.length) throw new Error('Выберите хотя бы одно разрешение.'); const body={...tokenMetadata(d),scopes,expires_in:Number(d.get('days'))*86400};if(!system&&d.get('source_id')) body.source_id=String(d.get('source_id')).trim();if(!system&&d.get('service_account_id')) body.service_account_id=String(d.get('service_account_id')).trim();if(!system&&d.get('signing_mode'))body.signing_mode=String(d.get('signing_mode'));await issueCredential(path,body,epoch);});
    if(!system) formEl.append(h('datalist',{id:'token-sources'},sources.map(s=>h('option',{value:s.id},s.name+' ('+s.slug+')'))),h('datalist',{id:'token-accounts'},accounts.filter(a=>!a.disabled_at).map(a=>h('option',{value:a.id},a.name))));
    const bulkFields=[field('Область отзыва',select('kind',system?[['all','Все системные токены']]:[['all','Все токены проекта'],['source','Токены источника'],['account','Токены сервиса']],'all'))];
    if(!system) bulkFields.push(field('UUID источника или сервиса',input('id',{maxlength:36})));
    const bulk=form('Пакетный отзыв',bulkFields,async d=>{const kind=d.get('kind'),id=String(d.get('id')||'').trim();if(kind!=='all'&&!id) throw new Error('Укажите UUID.'); const body={confirm:true};if(kind==='source')body.source_id=id;if(kind==='account')body.service_account_id=id;confirmAction('Пакетный отзыв','Отозвать все credentials выбранной области? Это необратимо.',async()=>{const result=await write(path+'/revoke-all','POST',body);await mutationSuccess(epoch,'Отозвано: '+result.revoked);});});
    const list=h('div'); const filters=select('status',[['all','Все'],['active','Активные'],['expired','Истёкшие'],['revoked','Отозванные']],'all');
    function renderList(){list.replaceChildren(pagedList({path,query:{limit:50,status:filters.value},signal,epoch,headers:['Название / UUID','Scopes','Источник / сервис','Истекает','Последнее использование','Статус',''],rows:r=>{
        const expired=r.expires_at&&Date.parse(r.expires_at)<=Date.now();const valid=!r.revoked_at&&!expired;const url=path+'/'+enc(r.id);
        const actions=h('div',{class:'actions'},!system&&valid&&r.scopes.includes('logs:write')?button('HMAC',()=>signingDialog(url,epoch).catch(e=>{if(active(epoch))modal('Подпись ingest',message(e));})):null,!r.revoked_at?button('Изменить токен',()=>modal('Редактировать токен',form('Сохранить токен',tokenMetadataFields(r),async d=>{await write(url,'PATCH',{revision:r.revision,...tokenMetadata(d)});clearModal();await mutationSuccess(epoch,'Токен изменён.');}))):null,
            valid&&!r.rotated_to_id?button('Ротация',()=>{const fields=[field('Срок нового токена, дней',input('days',{type:'number',min:1,max:365,value:30,required:true})),field('Перекрытие, секунд (0 = немедленно)',input('overlap',{type:'number',min:0,max:604800,value:3600,required:true})),h('div',{},...r.scopes.map((s,i)=>checkbox('scope_'+i,s,true)))];modal('Ротация токена',form('Выпустить замену',fields,async d=>{const scopes=r.scopes.filter((s,i)=>d.has('scope_'+i));if(!scopes.length)throw new Error('Выберите разрешение.');await issueCredential(url+'/rotate',{revision:r.revision,expires_in:Number(d.get('days'))*86400,overlap_seconds:Number(d.get('overlap')),scopes},epoch);}));}):null,
            !r.revoked_at?button('Отозвать',()=>confirmAction('Отозвать токен',`Токен «${r.name}» перестанет работать немедленно.`,async()=>{await write(url,'DELETE');await mutationSuccess(epoch,'Токен отозван.');}),'danger'):null);
        return [h('div',{},r.name,h('div',{class:'muted'},r.description||''),h('code',{},r.id),r.rotated_to_id?h('div',{class:'muted'},'Замена: '+r.rotated_to_id):null),(r.scopes||[]).join(', '),h('div',{},r.source_id?h('code',{},r.source_id):'Все',r.service_account_id?h('div',{},h('code',{},r.service_account_id)):null),date(r.expires_at),date(r.last_used_at),badge(r.revoked_at?'Отозван':expired?'Истёк':r.rotated_to_id?'Перекрытие':'Активен'),actions];
    }}));} filters.addEventListener('change',renderList);renderList();
    return card(h('h2',{},system?'Системные токены':'Токены проекта'),h('p',{class:'muted'},system?'Только scope system:read.':'Токен может читать и/или записывать логи всего проекта либо одного источника.'),formEl,h('details',{},h('summary',{},'Пакетный отзыв credentials'),bulk),field('Статус токена',filters),list);
}
async function tokens(signal, epoch) { const root = h('div', {}, heading('API-токены', 'Выпуск и отзыв сервисных credentials.'), projectSelector()); if (state.project) {
    const path = projectPath();
    const sources = await get(path + '/sources', { limit: 100 }, signal);
    if (manage()) { const accounts=await get(path+'/service-accounts',{limit:100},signal); root.append(serviceAccountSection(path+'/service-accounts',signal,epoch),tokenSection(path+'/tokens',sources.items,signal,epoch,false,accounts.items)); }
    else root.append(card(empty('У вас нет прав управления токенами в этом проекте.')));
}
else
    root.append(needProject()); if (admin())
    root.append(tokenSection('/system/tokens', [], signal, epoch, true)); return root; }

export { tokens };
