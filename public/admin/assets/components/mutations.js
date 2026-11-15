import { state, active, render } from '../app/store.js?v=0.3.5';
import { h, button, field, input, message, notify, modalEpoch, clearModal, modal } from './ui.js?v=0.3.5';

const nameFields = () => [field('Название', input('name', { required: true, maxlength: 255 })), field('Slug', input('slug', { required: true, maxlength: 100, pattern: '[a-z0-9]+(-[a-z0-9]+)*', placeholder: 'my-project' }))];
const nameBody = d => ({ name: String(d.get('name')).trim(), slug: String(d.get('slug')).trim() });
function mutationSuccess(epoch, label) { notify(label); if (active(epoch))
    return render(); }
function confirmAction(title, description, action) { const epoch = state.epoch; let dialogEpoch; const err = h('div', { 'aria-live': 'polite' }); const ok = button('Подтвердить', async () => { ok.disabled = true; err.replaceChildren(); try {
    await action();
    if (active(epoch) && dialogEpoch === modalEpoch)
        clearModal();
}
catch (e) {
    if (e.name !== 'AbortError')
        err.replaceChildren(message(e));
}
finally {
    ok.disabled = false;
} }, 'danger'); modal(title, h('div', {}, h('p', {}, description), h('div', { class: 'actions' }, ok, button('Отмена', clearModal)), err)); dialogEpoch = modalEpoch; }

export { nameFields, nameBody, mutationSuccess, confirmAction };
