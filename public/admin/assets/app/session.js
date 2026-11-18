import { state, resetWorkspace, render, advanceSession } from './store.js?v=0.8.1';
import { $, h, field, input, clearModal, form } from '../components/ui.js?v=0.8.1';
import { refreshProject } from './projects.js?v=0.8.1';
import { navigation } from './shell.js?v=0.8.1';
import { request, get } from '../api/client.js?v=0.8.1';

const version = document.querySelector('meta[name=cajeer-version]').content;
let sessionChecking = false;
let sessionCheckAgain = false;
let loginAttempt = 0;
let channel = null;
try { if (typeof BroadcastChannel === 'function') channel = new BroadcastChannel('cajeerlogs-auth'); } catch { /* Opaque origin or unavailable browser API. Focus/polling still revalidate. */ }
export function sessionChanged() { channel?.postMessage('changed'); }
channel?.addEventListener('message', () => { if (state.user) void revalidateSession(true); });
function clearSession(reason) {
    loginAttempt++; advanceSession(); state.epoch++; state.controller?.abort(); state.controller = null;
    state.user = null; state.security = null; state.csrf = null; state.enrollmentRequired = false; resetWorkspace();
    clearModal(); $('notice').hidden = true; $('notice').textContent = ''; $('main').removeAttribute('aria-busy');
    renderLogin(reason);
}
function renderLogin(reason = '') {
    const attempt=++loginAttempt;
    document.title = 'Вход — CajeerLogs'; navigation();
    const panel=h('section',{class:'card login'},h('h1',{},'Вход в CajeerLogs'),h('p',{class:'muted'},'Войдите с учётной записью, созданной установщиком.'),reason?h('p',{role:'status'},reason):null);
    const pw=input('password',{type:'password',required:true,autocomplete:'current-password',maxlength:1024});
    const show=h('input',{type:'checkbox',on:{change:e=>{pw.type=e.target.checked?'text':'password';}}});
    const credentialsForm=form('Войти',[field('Email',input('email',{type:'email',required:true,autocomplete:'username',maxlength:255})),field('Пароль',pw),h('label',{class:'check'},show,'Показать пароль')],async (d,f)=>{
        const credentials={email:String(d.get('email')),password:String(d.get('password'))};
        try {
            const res=await request('/auth/login',{method:'POST',body:credentials,login:true});
            if(attempt!==loginAttempt) return;
            f.reset(); await startSession(res); sessionChanged();
        } catch(e) {
            if(e.data?.error!=='second_factor_required'||attempt!==loginAttempt) throw e;
            f.reset(); secondFactor(credentials);
        } finally { if(panel.contains(f)) credentials.password=''; }
    },{cls:'stack'});
    function secondFactor(credentials) {
        const expires=Date.now()+300000; let submitting=false;
        const forget=()=>{ credentials.password=''; };
        const timer=setTimeout(()=>{forget(); if(attempt===loginAttempt && !state.user && !submitting) renderLogin('Время подтверждения истекло. Введите пароль снова.');},300000);
        const otp=input('otp',{required:true,autocomplete:'one-time-code',maxlength:32,pattern:'([0-9]{6}|[a-fA-F0-9]{32})'});
        const back=h('button',{type:'button',on:{click:()=>{forget();clearTimeout(timer);renderLogin();}}},'Назад');
        const step=form('Подтвердить вход',[h('p',{},'Введите шестизначный код приложения или одноразовый recovery-код.'),field('Код 2FA / recovery (если включено)',otp)],async(d,f)=>{
            if(Date.now()>=expires||!credentials.password) { forget(); clearTimeout(timer); renderLogin('Время подтверждения истекло.'); return; }
            submitting=true; back.disabled=true;
            try {
                const res=await request('/auth/login',{method:'POST',body:{...credentials,otp:String(d.get('otp')).trim().toLowerCase()},login:true});
                if(attempt!==loginAttempt) return;
                forget(); clearTimeout(timer); f.reset(); await startSession(res); sessionChanged();
            } finally { submitting=false; back.disabled=false; if(Date.now()>=expires && !state.user && attempt===loginAttempt) renderLogin('Время подтверждения истекло.'); }
        },{cls:'stack',after:[back]});
        credentialsForm.replaceWith(h('div',{},h('h2',{},'Подтверждение 2FA'),h('p',{class:'muted'},credentials.email),step)); otp.focus();
    }
    panel.append(credentialsForm,h('p',{class:'muted small'},'CajeerLogs '+version)); $('main').replaceChildren(panel);
}
async function startSession(res) { loginAttempt++; advanceSession(); state.user = res.user; state.security = res.security??null; state.enrollmentRequired = Boolean(res.enrollment_required); state.csrf = res.csrf_token; resetWorkspace(); await render({ focus: true }); }
async function bootstrap() {
    const epoch = state.authEpoch;
    try {
        const result = await get('/auth/me');
        if (epoch === state.authEpoch) await startSession(result);
    } catch (e) {
        if (epoch === state.authEpoch && e.status !== 401 && e.name !== 'AbortError') renderLogin('Не удалось проверить сессию: ' + e.message);
    }
}
async function revalidateSession(force = false) {
    if (!state.user || (!force && document.hidden)) return;
    if (sessionChecking) { if(force)sessionCheckAgain=true; return; }
    const authEpoch = state.authEpoch; sessionChecking = true; try {
    const res = await get('/auth/me');
    if (authEpoch !== state.authEpoch)
        return;
    if (res.user.id !== state.user.id || res.user.role !== state.user.role) {
        clearSession('Учётная запись изменилась. Войдите снова.');
        return;
    }
    const permissionsChanged=JSON.stringify(state.user.permissions??null)!==JSON.stringify(res.user.permissions??null);
    const policyChanged=JSON.stringify([state.security?.totp_required,state.security?.idle_seconds,state.security?.reauth_seconds])!==JSON.stringify([res.security?.totp_required,res.security?.idle_seconds,res.security?.reauth_seconds]);
    state.user = res.user; state.security = res.security??null; state.csrf = res.csrf_token;
    if (policyChanged && !permissionsChanged && state.route==='settings') { state.enrollmentRequired=Boolean(res.enrollment_required); void render(); return; }
    if (permissionsChanged) { state.enrollmentRequired=Boolean(res.enrollment_required); resetWorkspace(); void render(); return; }
    if (state.enrollmentRequired !== Boolean(res.enrollment_required)) { state.enrollmentRequired=Boolean(res.enrollment_required); void render(); return; }
    const signal=state.controller?.signal, epoch=state.epoch;
    if(state.project && signal && await refreshProject(signal, true) && authEpoch===state.authEpoch && epoch===state.epoch && !signal.aborted) {
        // Revoke stale DOM, dialogs, and pending requests immediately after discovering access changes.
        void render();
    }
}
catch (e) { /* 401 is handled centrally; transient connectivity errors must not imply logout. */ }
finally {
    sessionChecking = false;
    if(sessionCheckAgain){sessionCheckAgain=false;if(state.user)void revalidateSession(true);}
} }
window.addEventListener('focus', () => void revalidateSession());
document.addEventListener('visibilitychange', () => { if (!document.hidden) void revalidateSession(); });
setInterval(() => void revalidateSession(), 60000);

export { version, clearSession, renderLogin, startSession, bootstrap, revalidateSession };
