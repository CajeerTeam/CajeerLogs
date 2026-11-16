import { roles, state, render, active } from '../app/store.js?v=0.4.5';
import { h, button, card, field, input, clearModal, modal, form, heading, table, date, copy, notify } from '../components/ui.js?v=0.4.5';
import { clearSession, startSession, sessionChanged } from '../app/session.js?v=0.4.5';
import { confirmAction } from '../components/mutations.js?v=0.4.5';
import { themeSelector } from '../app/theme.js?v=0.4.5';
import { sessionList } from '../components/session-list.js?v=0.4.5';
import { qrMatrix } from '../components/qr-encoder.js?v=0.4.5';
import { write } from '../api/client.js?v=0.4.5';

function recoveryDialog(codes) {
    modal('Сохраните recovery-коды',h('div',{},h('p',{},'Каждый код действует один раз. Сохраните их в защищённом месте. Они больше не будут показаны.'),h('pre',{},codes.join('\n')),button('Копировать коды',()=>void copy(codes.join('\n'))),button('Сохранено',clearModal)));
}
function qrImage(uri) {
    const matrix=qrMatrix(uri), canvas=h('canvas',{class:'totp-qr',role:'img','aria-label':'QR-код настройки TOTP'}), scale=5, size=(matrix.length+8)*scale;
    canvas.width=canvas.height=size; const ctx=canvas.getContext('2d');
    ctx.fillStyle='#fff';ctx.fillRect(0,0,size,size);ctx.fillStyle='#000';
    matrix.forEach((row,y)=>row.forEach((dark,x)=>{if(dark)ctx.fillRect((x+4)*scale,(y+4)*scale,scale,scale);}));
    return canvas;
}
function twoFactorCard() {
    const pw=()=>field('Текущий пароль',input('password',{type:'password',required:true,autocomplete:'current-password',maxlength:1024}));
    const otp=()=>field('Код приложения или recovery-код',input('otp',{required:true,autocomplete:'one-time-code',maxlength:32}));
    const section=card(h('h2',{},'Двухфакторная аутентификация'),h('p',{},state.user.two_factor_enabled?'TOTP включён.':'TOTP не включён.'));
    if (state.user.two_factor_enabled) {
        if(!state.security?.totp_required) section.append(form('Отключить 2FA',[pw(),otp()],async d=>{await write('/auth/2fa/disable','POST',{password:String(d.get('password')),otp:String(d.get('otp')).trim().toLowerCase()});clearSession('2FA отключена. Войдите снова.');sessionChanged();}));
        if(state.security?.totp_required)section.append(h('p',{class:'muted'},'Второй фактор обязателен по политике безопасности.'));
        section.append(form('Обновить recovery-коды',[pw(),otp()],async(d,f)=>{const epoch=state.epoch; const result=await write('/auth/2fa/recovery-codes','POST',{password:String(d.get('password')),otp:String(d.get('otp')).trim().toLowerCase()});f.reset();if(active(epoch)&&section.isConnected)recoveryDialog(result.recovery_codes);else if(state.user)notify('Recovery-коды заменены. Если вы не сохранили их, выпустите новый набор.');}),h('p',{class:'muted'},'При обновлении все прежние recovery-коды становятся недействительными.'));
    } else {
        section.append(form('Настроить 2FA',[pw()],async (d,f)=>{
            const epoch=state.epoch; const data=await write('/auth/2fa/setup','POST',{password:String(d.get('password'))}); f.reset(); if(!active(epoch)||!section.isConnected)return;
            modal('Настройка TOTP',h('div',{},h('p',{},'Отсканируйте QR-код или добавьте ключ вручную. SHA-1, 6 цифр, 30 секунд. Ключ действителен 10 минут.'),qrImage(data.otpauth_uri),h('pre',{},data.secret),button('Копировать ключ',()=>void copy(data.secret)),form('Подтвердить',[field('6-значный код',input('otp',{required:true,inputmode:'numeric',autocomplete:'one-time-code',maxlength:6,pattern:'[0-9]{6}'}))],async d=>{
                const epoch=state.epoch;
                const result=await write('/auth/2fa/enable','POST',{otp:String(d.get('otp'))});
                const sameView=active(epoch)&&section.isConnected;
                await startSession(result); sessionChanged();
                if(sameView&&state.user&&state.route==='settings'&&state.epoch===epoch+1)recoveryDialog(result.recovery_codes);
                else if(state.user)notify('2FA включена. Если recovery-коды не сохранены, выпустите новый набор в настройках.');
            })));
        }));
    }
    return section;
}
function reauthCard() {
    const status=h('p',{class:'muted'},state.security.reauthenticated_until?'Последнее подтверждение действительно до '+date(state.security.reauthenticated_until):'Требуется подтверждение личности.');
    return card(h('h2',{},'Подтверждение личности'),status,h('p',{},'Для критических операций требуется недавний вход или подтверждение. После подтверждения повторите операцию.'),form('Подтвердить личность',[
        field('Текущий пароль',input('password',{type:'password',required:true,autocomplete:'current-password',maxlength:1024})),...(state.user.two_factor_enabled?[field('Код 2FA или recovery',input('otp',{required:true,autocomplete:'one-time-code',maxlength:32}))]:[])],async(d,f)=>{
        const epoch=state.authEpoch;
        const result=await write('/auth/reauthenticate','POST',{password:String(d.get('password')),...(state.user.two_factor_enabled?{otp:String(d.get('otp')).trim().toLowerCase()}: {})}); f.reset();
        if(epoch!==state.authEpoch)return; state.security=result.security; status.textContent='Подтверждение действительно до '+date(result.security.reauthenticated_until); notify('Личность подтверждена. Повторите исходную операцию.');
    }));
}
async function settings(signal, epoch) {
    if(state.enrollmentRequired) return h('div',{},heading('Настройка 2FA','Политика безопасности требует подключения второго фактора.'),twoFactorCard());
    return h('div',{},heading('Настройки','Настройки учётной записи и интерфейса.'),
        card(h('h2',{},'Профиль'),table(['Поле','Значение'],[['Email',state.user.email],['Роль',roles[state.user.role]],['UUID',state.user.id],['Создан',date(state.user.created_at)],['Последний вход',date(state.user.last_login_at)]]),
            form('Сохранить профиль',[field('Отображаемое имя',input('display_name',{value:state.user.display_name||'',maxlength:120,autocomplete:'name'}))],async(d)=>{
                const result=await write('/auth/profile','PATCH',{display_name:String(d.get('display_name'))});state.user={...state.user,...result.user};notify('Профиль обновлён.');if(active(epoch))await render();
            }),h('div',{class:'actions'},field('Цветовая тема',themeSelector()))),twoFactorCard(),
        state.security?.reauth_seconds>0?reauthCard():null,
        card(h('h2',{},'Сменить пароль'),h('p',{class:'muted'},'После смены пароля все сессии будут отозваны.'),form('Сменить пароль',[
            field('Текущий пароль',input('current_password',{type:'password',required:true,autocomplete:'current-password',maxlength:1024})),
            field('Новый пароль',input('password',{type:'password',required:true,minlength:12,maxlength:1024,autocomplete:'new-password'})),
            field('Повторите новый пароль',input('repeat',{type:'password',required:true,minlength:12,maxlength:1024,autocomplete:'new-password'})),...(state.user.two_factor_enabled?[field('Код 2FA или recovery',input('otp',{required:true,autocomplete:'one-time-code',maxlength:32}))]:[])],async d=>{
                if(d.get('password')!==d.get('repeat'))throw new Error('Пароли не совпадают.');await write('/auth/password','POST',{current_password:String(d.get('current_password')),password:String(d.get('password')),...(state.user.two_factor_enabled?{otp:String(d.get('otp')).trim().toLowerCase()}: {})});clearSession('Пароль изменён. Войдите снова.');sessionChanged();
            })),
        card(h('h2',{},'Сессии'),h('p',{class:'muted'},'Активные входы. Время указано в UTC. Данные устройства сообщаются браузером, IP — адрес соединения с сервером. Срок действия учитывает абсолютный TTL и настроенный idle timeout.'),
            sessionList('/auth/sessions',signal,epoch),h('div',{class:'actions'},
                button('Завершить остальные сессии',()=>confirmAction('Завершить остальные сессии','Текущая сессия останется активной.',async()=>{await write('/auth/logout-others','POST');sessionChanged();if(active(epoch))await render();}),'danger'),
                button('Выйти на всех устройствах',()=>confirmAction('Отозвать все сессии','Все активные сессии будут завершены.',async()=>{await write('/auth/logout-all','POST');clearSession('Все сессии завершены.');sessionChanged();}),'danger'))));
}
export { settings };
