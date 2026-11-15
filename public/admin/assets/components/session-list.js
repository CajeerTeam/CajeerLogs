import { state, active, render } from '../app/store.js?v=0.4.4';
import { h, button, date, enc, modal } from './ui.js?v=0.4.4';
import { pagedList } from './pagination.js?v=0.4.4';
import { confirmAction, mutationSuccess } from './mutations.js?v=0.4.4';
import { write } from '../api/client.js?v=0.4.4';
import { clearSession, sessionChanged } from '../app/session.js?v=0.4.4';

export function sessionList(path, signal, epoch) {
    return pagedList({path, query:{limit:25}, signal, epoch, headers:['Устройство / браузер','IP','Создана','Активность','Истекает',''], rows:s=>[
        h('div',{class:'session-agent'},s.user_agent||'Неизвестное устройство',s.current?h('strong',{class:'current-session'},'Текущая сессия'):null),
        s.remote_address||'—',date(s.created_at),date(s.last_seen_at),date(s.effective_expires_at||s.expires_at),
        button('Завершить',()=>confirmAction('Завершить сессию','Доступ с этого устройства будет прекращён.',async()=>{
            await write(path+'/'+enc(s.id),'DELETE');
            if(s.current) { clearSession('Сессия завершена.'); sessionChanged(); }
            else { sessionChanged(); await mutationSuccess(epoch,'Сессия завершена.'); }
        }),'danger')
    ]});
}
export function userSessionDialog(user, signal, epoch) {
    // Render within the user list instead of nesting a second modal in a confirmation modal.
    const box=document.createElement('section'); box.className='card';
    box.append(h('h2',{},'Сессии: '+user.email),sessionList('/users/'+enc(user.id)+'/sessions',signal,epoch),
        button('Завершить все сессии пользователя',()=>confirmAction('Отозвать сессии пользователя','Будут завершены все активные сессии этой учётной записи.',async()=>{
            await write('/users/'+enc(user.id)+'/sessions/revoke-all','POST');
            if(user.id===state.user?.id) {clearSession('Все сессии завершены.');sessionChanged();}
            else {sessionChanged(); await mutationSuccess(epoch,'Сессии пользователя завершены.');}
        }),'danger'),button('Закрыть список',()=>box.remove()));
    return box;
}
