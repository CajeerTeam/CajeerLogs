import { roles, state, active } from '../app/store.js?v=1.0.3';
import { h, button, card, field, input, select, roleSelect, checkbox, badge, enc, clearModal, modal, form, heading, date, table, message, text, notify } from '../components/ui.js?v=1.0.3';
import { clearSession, sessionChanged } from '../app/session.js?v=1.0.3';
import { pagedList } from '../components/pagination.js?v=1.0.3';
import { mutationSuccess, confirmAction } from '../components/mutations.js?v=1.0.3';
import { userSessionDialog } from '../components/session-list.js?v=1.0.3';
import { assignmentDialog, permissionsDialog } from './rbac.js?v=1.0.3';
import { get, write } from '../api/client.js?v=1.0.3';

const projectRoles = [['','Наследовать глобальную роль'],['operator','Оператор (не повышает глобальную роль)'],['viewer','Наблюдатель']];
const boolLabel = v => v ? 'Включена' : 'Выключена';
function userFields(user = null) {
    return [field('Email', input('email', { type:'email', required:true, maxlength:255, value:user?.email ?? '' })), field('Роль',roleSelect(user?.role)), field(user ? 'Новый пароль (необязательно)' : 'Пароль',input('password',{type:'password',minlength:12,maxlength:1024,required:!user,autocomplete:'new-password'})), user ? checkbox('blocked','Заблокирован',!!user.blocked_at):null];
}
function editUser(user,epoch) {
    modal('Редактировать пользователя',h('div',{},h('p',{class:'muted'},'Изменение пользователя отзывает все его сессии. Глобальная роль ограничивает права в каждом проекте.'),form('Сохранить',userFields(user),async d=>{
        const body={email:String(d.get('email')),role:String(d.get('role')),blocked:d.has('blocked')};
        if(user.updated_at) body.expected_updated_at=user.updated_at;
        if(d.get('password')) body.password=String(d.get('password'));
        await write('/users/'+enc(user.id),'PATCH',body); sessionChanged();
        if(user.id===state.user?.id){clearSession('Учётная запись изменена. Войдите снова.');return;}
        if(active(epoch)){clearModal();await mutationSuccess(epoch,'Пользователь обновлён.');}
    })));
}
function membershipDialog(user,project,epoch,refresh) {
    modal('Доступ к проекту',h('div',{},h('p',{},project.name+' · '+user.email),h('p',{class:'muted'},'Роль проекта не может повысить глобальную роль. Администратор имеет неявный доступ ко всем проектам.'),form('Сохранить доступ',[field('Роль в проекте',select('role',projectRoles,project.project_role||''))],async d=>{
        await write('/projects/'+enc(project.id)+'/members/'+enc(user.id),'PUT',{role:d.get('role')||null});
        if(active(epoch)){clearModal();notify('Доступ сохранён.');await refresh();}
    })));
}
function resetTotp(user,epoch,refresh) {
    modal('Сброс двухфакторной аутентификации',h('div',{},h('p',{},'Сбросить 2FA для '+user.email+'? Все ключи, recovery-коды и активные сессии этого пользователя будут отозваны. При обязательной 2FA администратору потребуется повторная настройка.'),form('Сбросить 2FA',[field('Ваш текущий пароль',input('password',{type:'password',required:true,autocomplete:'current-password',maxlength:1024})),state.user?.two_factor_enabled?field('Ваш TOTP / recovery-код',input('otp',{required:true,autocomplete:'one-time-code',maxlength:32})):null,checkbox('confirm','Подтверждаю сброс 2FA другого пользователя')],async d=>{
        if(!d.has('confirm'))throw new Error('Подтвердите сброс.');
        const body={password:String(d.get('password'))}; if(d.get('otp'))body.otp=String(d.get('otp'));
        await write('/users/'+enc(user.id)+'/2fa/reset','POST',body);
        if(active(epoch)){clearModal();notify('2FA и сессии пользователя сброшены.');await refresh();}
    })));
}
// Server-side project/user picker. No complete directory is loaded into the browser.
export function directoryPicker({path,label,query={},signal,epoch,choose,display}) {
    const results=h('div',{});
    const load=q=>results.replaceChildren(pagedList({path,query:{...query,q,limit:10},signal,epoch,headers:[label,''],rows:r=>[display(r),button('Выбрать',()=>choose(r))]}));
    return h('div',{class:'directory-picker'},form('Найти',[field(label,input('q',{maxlength:255,placeholder:'Имя, email или идентификатор'}))],async d=>load(String(d.get('q')).trim())),results);
}
function accessCard(user,signal,epoch,refresh) {
    const path='/users/'+enc(user.id);
    return card(h('h2',{},'Доступ к проектам'),h('p',{class:'muted'},user.role==='administrator'?'Администратор имеет доступ ко всем проектам независимо от членства.':'Доступ предоставляется членством; проектная роль может только ограничить глобальную.'),
        pagedList({path:path+'/projects',query:{limit:25},signal,epoch,headers:['Проект','Членство','Роль проекта','Эффективная роль',''],rows:p=>[
            h('div',{},p.name,h('div',{class:'muted'},p.slug+(p.archived_at?' · Архивный':''))),p.member?'Явное':'Неявное',h('div',{},p.project_role?roles[p.project_role]:'Наследуется',p.rbac_role_id?h('small',{class:'muted'},'Доп. роль: '+p.rbac_role_id):null),p.effective_role?roles[p.effective_role]:'Нет доступа',
            h('div',{class:'actions'},button('Изменить доступ',()=>membershipDialog(user,p,epoch,refresh)),button('Эффективные разрешения',()=>permissionsDialog(user,p,signal,epoch)),p.member&&user.role!=='administrator'?button('Назначить пользовательскую роль',()=>assignmentDialog({scope:'project',user,project:p,signal,epoch,refresh})):null,p.member?button('Убрать членство',()=>confirmAction('Удалить членство',`Убрать ${user.email} из ${p.name}? ${user.role==='administrator'?'Глобальный доступ администратора сохранится.':''}`,async()=>{await write('/projects/'+enc(p.id)+'/members/'+enc(user.id),'DELETE');if(active(epoch)){notify('Членство удалено.');await refresh();}}),'danger'):null)]}),
        h('h3',{},'Добавить в проект'),directoryPicker({path:'/projects',label:'Поиск проектов для доступа',query:{status:'active'},signal,epoch,display:p=>p.name+' · '+p.slug,choose:p=>membershipDialog(user,p,epoch,refresh)}));
}
async function users(signal,epoch) {
    const sessions=h('div',{}), details=h('div',{}), list=h('div',{});
    let serial=0;
    const refreshDetail=async id=>{
        if(!id||!active(epoch))return;
        const ticket=++serial;
        details.replaceChildren(h('p',{role:'status'},'Загрузка карточки…'));
        try {
            const {user:u}=await get('/users/'+enc(id),{},signal);
            if(!active(epoch)||signal.aborted||ticket!==serial)return;
            const reload=()=>refreshDetail(id);
            details.replaceChildren(card(h('div',{class:'page-head'},h('h2',{},'Карточка пользователя'),button('Закрыть карточку',()=>{serial++;details.replaceChildren();sessions.replaceChildren();})),
                table(['Поле','Значение'],[['Email',u.email],['Имя',text(u.display_name)],['UUID',h('code',{},u.id)],['Глобальная роль',roles[u.role]],['Пользовательская роль',text(u.rbac_role_id)],['Статус',u.blocked_at?'Заблокирован':'Активен'],['2FA',boolLabel(u.two_factor_enabled)],['Создан',date(u.created_at)],['Последний вход',date(u.last_login_at)],['Обновлён',date(u.updated_at)]]),
                h('div',{class:'actions'},button('Редактировать аккаунт',()=>editUser(u,epoch)),u.role!=='administrator'?button('Назначить глобальную роль',()=>assignmentDialog({scope:'global',user:u,signal,epoch,refresh:reload})):null,button('Глобальные разрешения',()=>permissionsDialog(u,null,signal,epoch)),button('Активные сессии',()=>{sessions.replaceChildren(userSessionDialog(u,signal,epoch));sessions.scrollIntoView({block:'nearest'});}),u.id!==state.user?.id?button('Сбросить 2FA',()=>resetTotp(u,epoch,reload),'danger'):null)),
                accessCard(u,signal,epoch,reload),card(h('h2',{},'История доступа и изменений'),pagedList({path:'/users/'+enc(id)+'/audit',query:{limit:25},signal,epoch,headers:['Время UTC','Действие','Кто','Цель','Изменения','Request ID'],rows:a=>[date(a.created_at),a.action,text(a.actor_id),text(a.target),[a.role_before&&a.role_after?`Роль: ${roles[a.role_before]||a.role_before} → ${roles[a.role_after]||a.role_after}`:null,a.blocked_before!=null&&a.blocked_after!=null?`Блокировка: ${a.blocked_before} → ${a.blocked_after}`:null,a.password_reset==='true'?'Пароль заменён':null,a.action==='project.member_role_set'?`Роль проекта: ${roles[a.project_role]||'Наследуется'}`:null].filter(Boolean).join('; ')||'—',text(a.request_id)]})));
            details.scrollIntoView({block:'nearest'});
        } catch(e) {if(active(epoch)&&ticket===serial&&e.name!=='AbortError')details.replaceChildren(message(e),button('Повторить',()=>refreshDetail(id)));}
    };
    const load=query=>{list.replaceChildren(pagedList({path:'/users',query:{limit:50,...query},signal,epoch,headers:['Email','Имя','Роль','UUID','Статус','2FA','Последний вход',''],rows:r=>[r.email,text(r.display_name),roles[r.role]||r.role,h('code',{},r.id),badge(r.blocked_at?'Заблокирован':'Активен'),boolLabel(r.two_factor_enabled),date(r.last_login_at),h('div',{class:'actions'},button('Карточка',()=>refreshDetail(r.id)),button('Изменить',()=>editUser(r,epoch)),button('Сессии',()=>{sessions.replaceChildren(userSessionDialog(r,signal,epoch));sessions.scrollIntoView({block:'nearest'});}))]}));};
    load({});
    const filters=form('Найти пользователей',[field('Поиск пользователей',input('q',{maxlength:255,placeholder:'Email, имя, UUID'})),field('Глобальная роль',select('role',[['all','Все роли'],...Object.entries(roles)],'all')),field('Статус аккаунта',select('status',[['all','Все'],['active','Активные'],['blocked','Заблокированные']],'all')),field('Фильтр 2FA',select('two_factor',[['all','Все'],['enabled','Включена'],['disabled','Выключена']],'all')),field('Сортировка',select('sort',[['id','По UUID'],['email_asc','Email A–Z'],['email_desc','Email Z–A'],['newest','Сначала новые']],'id'))],async d=>{serial++;details.replaceChildren();sessions.replaceChildren();load(Object.fromEntries(d));});
    return h('div',{},heading('Пользователи','Аккаунты, глобальные роли, членство в проектах и аудит доступа.'),card(h('h2',{},'Создать пользователя'),form('Создать пользователя',userFields(),async d=>{await write('/users','POST',{email:String(d.get('email')),password:String(d.get('password')),role:String(d.get('role'))});await mutationSuccess(epoch,'Пользователь создан.');})),card(h('h2',{},'Список пользователей'),filters,list),details,sessions);
}
export { users };
