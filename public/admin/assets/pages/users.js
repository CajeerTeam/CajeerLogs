import { roles, state } from '../app/store.js?v=0.3.3';
import { h, button, card, field, input, roleSelect, checkbox, badge, enc, clearModal, modal, form, heading } from '../components/ui.js?v=0.3.3';
import { clearSession } from '../app/session.js?v=0.3.3';
import { pagedList } from '../components/pagination.js?v=0.3.3';
import { mutationSuccess } from '../components/mutations.js?v=0.3.3';
import { userSessionDialog } from '../components/session-list.js?v=0.3.3';
import { write } from '../api/client.js?v=0.3.3';

function userFields(user = null) { return [field('Email', input('email', { type: 'email', required: true, maxlength: 255, value: user?.email ?? '' })), field('Роль', roleSelect(user?.role)), field(user ? 'Новый пароль (необязательно)' : 'Пароль', input('password', { type: 'password', minlength: 12, maxlength: 1024, required: !user, autocomplete: 'new-password' })), user ? checkbox('blocked', 'Заблокирован', !!user.blocked_at) : null]; }
function editUser(user, epoch) { modal('Редактировать пользователя', h('div', {}, h('p', { class: 'muted' }, 'Изменение пользователя отзывает все его сессии.'), form('Сохранить', userFields(user), async (d) => { const body = { email: String(d.get('email')), role: String(d.get('role')), blocked: d.has('blocked') }; if (d.get('password'))
    body.password = String(d.get('password')); await write('/users/' + enc(user.id), 'PATCH', body); if (user.id === state.user?.id) {
    clearSession('Учётная запись изменена. Войдите снова.');
    return;
} clearModal(); await mutationSuccess(epoch, 'Пользователь обновлён.'); }))); }
async function users(signal, epoch) { const sessions=h('div',{}); return h('div', {}, heading('Пользователи', 'Управление ролями, паролями и блокировкой.'), card(h('h2', {}, 'Создать пользователя'), form('Создать пользователя', userFields(), async (d) => { await write('/users', 'POST', { email: String(d.get('email')), password: String(d.get('password')), role: String(d.get('role')) }); await mutationSuccess(epoch, 'Пользователь создан.'); })), card(h('h2', {}, 'Список пользователей'), pagedList({ path: '/users', query: { limit: 50 }, signal, epoch, headers: ['Email', 'Роль', 'UUID', 'Статус', ''], rows: r => [r.email, roles[r.role] || r.role, h('code', {}, r.id), badge(r.blocked_at ? 'Заблокирован' : 'Активен'), h('div',{class:'actions'},button('Изменить', () => editUser(r, epoch)),button('Сессии',()=>{sessions.replaceChildren(userSessionDialog(r,signal,epoch));sessions.scrollIntoView({block:'nearest'});}))] })),sessions); }

export { users };
