import { state, active, render } from '../app/store.js?v=0.8.1';
import { h, button, card, field, input, select, checkbox, empty, table, heading, form, modal, modalEpoch, clearModal, message, notify, enc } from '../components/ui.js?v=0.8.1';
import { get, write } from '../api/client.js?v=0.8.1';
import { confirmAction } from '../components/mutations.js?v=0.8.1';

const descriptions = {
    'projects:read':'Просмотр проектов, источников и их агрегированной статистики',
    'logs:read':'Чтение, поиск и просмотр событий', 'logs:export':'Экспорт страницы логов через API',
    'logs:write':'Разрешение делегировать ingest через API-токен',
    'sources:create':'Создание источников', 'sources:update':'Изменение источников и их политик', 'sources:state':'Включение, отключение, архивирование и восстановление источников',
    'tokens:read':'Просмотр метаданных токенов', 'tokens:create':'Выпуск токенов', 'tokens:update':'Изменение метаданных токенов', 'tokens:rotate':'Ротация токенов', 'tokens:revoke':'Отзыв токенов', 'tokens:signing':'Настройка и ротация HMAC',
    'services:read':'Просмотр сервисных аккаунтов', 'services:manage':'Создание и отключение сервисных аккаунтов', 'audit:read':'Чтение проектного журнала безопасности',
    'queue:read':'Просмотр заданий очереди', 'queue:retry':'Повтор проблемных заданий', 'queue:discard':'Отказ от проблемных заданий',
    'users:manage':'Управление пользователями', 'projects:manage':'Управление проектами и членством', 'system:read':'Системная диагностика', 'roles:manage':'Управление ролями',
};
const scopeLabel=scope=>scope==='global'?'Глобальная':'Проектная';
const permissionTable=permissions=>table(['Разрешение','Назначение'],permissions.filter(p=>descriptions[p]).map(p=>[h('code',{},p),descriptions[p]]));
function validDialog(epoch,signal,ticket){return active(epoch)&&!signal?.aborted&&modalEpoch===ticket;}
function roleEditor(catalog,row,epoch,refresh) {
    const keys=catalog.permissions;
    const initial=row?.permissions||['projects:read','logs:read'];
    const fields=[field('Название роли',input('name',{required:true,maxlength:120,value:row?.name||''})),row?h('p',{},'Область: '+scopeLabel(row.scope)):field('Область роли',select('scope',[['global','Глобальная'],['project','Проектная']],'global')),
        h('p',{class:'muted'},'Роль ограничивает базовые полномочия Operator / Viewer. Administrator и глобальные операции администрирования остаются защищёнными.'),
        h('div',{class:'stack'},keys.map(key=>h('div',{},checkbox(key,key+' — '+(descriptions[key]||key),initial.includes(key)),key==='projects:read'?h('small',{class:'muted'},'Обязательное базовое разрешение.'):null)))];
    const editor=form(row?'Сохранить роль':'Создать роль',fields,async d=>{
        const permissions=keys.filter(key=>d.has(key));
        const body={name:String(d.get('name')).trim(),permissions};
        if(!permissions.includes('projects:read'))throw new Error('Разрешение projects:read обязательно.');
        if(permissions.includes('logs:export')&&!permissions.includes('logs:read'))throw new Error('Для экспорта требуется logs:read.');
        if(permissions.some(p=>p.startsWith('tokens:')&&p!=='tokens:read')&&!permissions.includes('tokens:read'))throw new Error('Для изменения токенов требуется tokens:read.');
        if(permissions.includes('services:manage')&&!permissions.includes('services:read'))throw new Error('Для управления сервисами требуется services:read.');
        if(permissions.some(p=>p.startsWith('queue:')&&p!=='queue:read')&&!permissions.includes('queue:read'))throw new Error('Для изменения очереди требуется queue:read.');
        if(row)await write('/roles/'+enc(row.id),'PATCH',{...body,revision:row.revision});
        else await write('/roles','POST',{...body,scope:String(d.get('scope'))});
        if(active(epoch)){clearModal();notify(row?'Роль сохранена.':'Роль создана.');await refresh();}
    },{cls:'stack'});
    modal(row?'Редактировать роль':'Новая пользовательская роль',editor);
}
export async function permissionsDialog(user,project,signal,epoch){
    const ticket=modalEpoch;
    try {
        const result=await get('/users/'+enc(user.id)+'/permissions',project?{project_id:project.id}:{},signal);
        if(!validDialog(epoch,signal,ticket))return;
        modal('Эффективные разрешения',h('div',{},h('p',{},user.email+(project?' · '+project.name:' · глобальные')),h('p',{class:'muted'},'Глобальный набор — верхняя граница. Проектный набор учитывает членство, базовую и пользовательские роли.'),permissionTable(result.permissions),!result.permissions.length?empty('Нет разрешений.'):null,button('Закрыть',clearModal)));
    }catch(e){if(validDialog(epoch,signal,ticket)&&e.name!=='AbortError')modal('Разрешения',message(e));}
}
export async function assignmentDialog({scope,user,project=null,signal,epoch,refresh}){
    const ticket=modalEpoch;
    try {
        const catalog=await get('/roles',{},signal);
        if(!validDialog(epoch,signal,ticket))return;
        const items=catalog.items.filter(r=>r.scope===scope);
        const existing=(project?project.rbac_role_id:user.rbac_role_id)??null;
        const chooser=select('role_id',[['','Без дополнительной роли (базовые права)'],...items.map(r=>[r.id,r.name])],existing||'');
        const preview=h('div',{});
        const update=()=>{const r=items.find(x=>x.id===chooser.value);preview.replaceChildren(r?permissionTable(r.permissions):h('p',{class:'muted'},'Дополнительное ограничение отсутствует. Действуют базовые и другие назначенные роли.'));};
        chooser.addEventListener('change',update); update();
        modal('Назначить '+(scope==='global'?'глобальную':'проектную')+' роль',h('div',{},h('p',{},user.email+(project?' · '+project.name:'')),h('p',{class:'muted'},'Снятие ограничения может расширить доступ до базовых полномочий. Изменения применяются на следующих серверных запросах.'),form('Сохранить назначение',[field('Пользовательская роль',chooser),preview],async d=>{
            const roleId=d.get('role_id')||null;
            const path=project?'/projects/'+enc(project.id)+'/members/'+enc(user.id)+'/role-assignment':'/users/'+enc(user.id)+'/role-assignment';
            await write(path,'PUT',{role_id:roleId,expected_role_id:existing});
            if(active(epoch)){clearModal();notify('Назначение сохранено.');await refresh();}
        },{cls:'stack'})));
    }catch(e){if(validDialog(epoch,signal,ticket)&&e.name!=='AbortError')modal('Назначение роли',message(e));}
}
export async function rbac(signal,epoch){
    const catalog=await get('/roles',{},signal);
    const root=h('div',{},heading('Роли и разрешения','Управление пользовательскими глобальными и проектными ролями.',button('Новая роль',()=>roleEditor(catalog,null,epoch,render),'primary')));
    const builtin=Object.entries(catalog.builtins).map(([name,permissions])=>[name,permissions.filter(p=>descriptions[p]).join(', ')]);
    root.append(card(h('h2',{},'Системные роли'),h('p',{class:'muted'},'Неизменяемые базовые роли. Пользовательские роли не повышают их полномочия.'),table(['Роль','Разрешения'],builtin)),
        card(h('h2',{},'Пользовательские роли'),h('p',{class:'muted'},'Назначайте глобальные роли в карточке пользователя, проектные — в карточке пользователя или участниках проекта. Удаление назначенной роли запрещено.'),table(['Название / UUID','Область','Разрешения','Ревизия','Действия'],catalog.items.map(r=>[h('div',{},r.name,h('div',{},h('code',{},r.id))),scopeLabel(r.scope),r.permissions.join(', '),String(r.revision),h('div',{class:'actions'},button('Изменить роль',()=>roleEditor(catalog,r,epoch,render)),button('Удалить роль',()=>confirmAction('Удалить роль','Удалить «'+r.name+'»? Назначенную роль удалить нельзя.',async()=>{await write('/roles/'+enc(r.id),'DELETE',{revision:r.revision});if(active(epoch)){notify('Роль удалена.');await render();}}),'danger'))]))));
    return root;
}
