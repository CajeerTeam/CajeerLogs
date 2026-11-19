import { state, active, hasPermission, projectPath } from '../app/store.js?v=0.9.2';
import { projectSelector } from '../app/projects.js?v=0.9.2';
import { get, write } from '../api/client.js?v=0.9.2';
import { h, heading, card, field, input, button, table, date, text, message, modal, clearModal, notify, form, needProject } from '../components/ui.js?v=0.9.2';

const eventNames = {
    'logs.ingested': 'Логи приняты', 'source.created': 'Источник создан',
    'source.disabled': 'Источник отключён', 'source.enabled': 'Источник включён',
    'source.archived': 'Источник архивирован', 'source.restored': 'Источник восстановлен'
};

export async function webhooks(signal, epoch) {
    const root = h('div', {}, heading('Webhooks', 'Подписки проекта, события и история попыток доставки.'), projectSelector());
    if (!state.project) { root.append(needProject()); return root; }
    if (!hasPermission('services:read')) { root.append(card('Нет доступа к интеграциям этого проекта.')); return root; }
    const path = projectPath() + '/webhooks', canManage = hasPermission('services:manage');
    const live = () => active(epoch) && !signal.aborted;
    const content = h('div'), status = h('div', { 'aria-live': 'polite' });
    const actions = h('div', { class: 'actions' });
    const eventFields = (selected = ['logs.ingested']) => Object.entries(eventNames).map(([key, label]) => h('label', { class: 'check' }, h('input', { type: 'checkbox', name: 'events', value: key, checked: selected.includes(key) }), label));
    function selectedEvents(formData) { return formData.getAll('events').map(String); }
    function showCreate() {
        modal('Создать подписку', form('Создать', [
            field('HTTPS URL получателя', input('url', { type: 'url', required: true, maxlength: 2048, placeholder: 'https://receiver.example.com/events' })),
            field('Имя переменной секрета (не значение)', input('secret_env', { required: true, pattern: 'CAJEER_WEBHOOK_[A-Z0-9_]+', maxlength: 115, placeholder: 'CAJEER_WEBHOOK_RECEIVER' })),
            h('fieldset', {}, h('legend', {}, 'События'), eventFields())
        ], async data => {
            await write(path, 'POST', { url: String(data.get('url')).trim(), secret_env: String(data.get('secret_env')).trim(), events: selectedEvents(data) });
            if (!live()) return; clearModal(); notify('Подписка создана.'); await load();
        }));
    }
    if (canManage) actions.append(button('Добавить подписку', showCreate, 'primary'));
    actions.append(button('Обновить', () => void load()));
    root.append(card(h('p', { class: 'muted' }, 'Подписки получают события после COMMIT. Доставка через systemd/cron webhook:work с семантикой at-least-once. Секреты задаются только на сервере.'), actions, status, content));
    async function invoke(url, label) {
        if (!live()) return;
        try { const result = await write(url, 'POST'); if (!live()) return;
            notify(label + (result?.delivery_id ? ': ' + result.delivery_id : '')); await load();
        } catch (e) { if (live()) status.replaceChildren(message(e)); }
    }
    async function details(webhook) {
        status.replaceChildren();
        try {
            const [deliveryData, attemptData] = await Promise.all([
                get(path + '/' + encodeURIComponent(webhook.id) + '/deliveries', { limit: 50 }, signal),
                get(path + '/' + encodeURIComponent(webhook.id) + '/attempts', {}, signal)
            ]);
            if (!live()) return;
            const detailRoot = h('div', { class: 'stack' },
                h('p', {}, 'Webhook: ', h('code', {}, webhook.id)),
                h('h3', {}, 'Последние доставки'),
                table(['Событие', 'Состояние', 'Попытки', 'HTTP', 'Создано', 'Действие'], deliveryData.items.map(d => [
                    text(d.event), text(d.status), d.attempts + '/' + d.retry_limit, text(d.last_http_status), date(d.created_at),
                    canManage && d.status === 'failed' ? button('Повторить', async () => {
                        await invoke(path + '/' + encodeURIComponent(webhook.id) + '/deliveries/' + encodeURIComponent(d.id) + '/retry', 'Повтор поставлен в очередь');
                        if (live()) clearModal();
                    }) : text(d.id)
                ])), h('h3', {}, 'Попытки'),
                table(['Доставка', 'Попытка', 'Исход', 'HTTP', 'Начало'], attemptData.items.map(a => [
                    h('code', {}, a.delivery_id), text(a.attempt), text(a.outcome), text(a.http_status), date(a.started_at)
                ]))
            );
            modal('Доставки и аудит попыток', detailRoot);
        } catch (e) { if (live()) status.replaceChildren(message(e)); }
    }
    function edit(webhook) {
        modal('Изменить подписку', form('Сохранить', [
            field('URL', input('url', { type: 'url', required: true, maxlength: 2048, value: webhook.url })),
            field('Имя переменной секрета', input('secret_env', { required: true, maxlength: 115, value: webhook.secret_env })),
            h('fieldset', {}, h('legend', {}, 'События'), eventFields(webhook.events)),
            h('label', { class: 'check' }, h('input', { type: 'checkbox', name: 'enabled', checked: webhook.enabled }), 'Подписка включена')
        ], async data => {
            await write(path + '/' + encodeURIComponent(webhook.id), 'PATCH', {
                revision: webhook.revision, url: String(data.get('url')).trim(), secret_env: String(data.get('secret_env')).trim(),
                events: selectedEvents(data), enabled: data.has('enabled')
            });
            if (!live()) return; clearModal(); notify('Подписка обновлена.'); await load();
        }));
    }
    let generation = 0;
    async function load() {
        const current = ++generation;
        status.replaceChildren(); content.replaceChildren(h('p', {}, 'Загрузка подписок…'));
        try {
            const [hooks, stats] = await Promise.all([get(path, {}, signal), get(path + '/stats', {}, signal)]);
            if (!live() || current !== generation) return;
            const byId = new Map(stats.items.map(s => [s.id, s]));
            content.replaceChildren(table(['Адрес', 'События', 'Статус', 'Доставки', 'Действия'], hooks.items.map(w => {
                const st = byId.get(w.id) || {};
                const buttons = h('div', { class: 'actions' }, button('История', () => void details(w)));
                if (canManage) {
                    buttons.append(button('Редактировать', () => edit(w)));
                    if (w.enabled) buttons.append(button('Тест', () => void invoke(path + '/' + encodeURIComponent(w.id) + '/test', 'Тест поставлен в очередь')));
                }
                return [h('div', {}, h('div', {}, w.url), h('code', {}, w.id), h('div', { class: 'muted' }, w.secret_env)),
                    w.events.map(x => eventNames[x] || x).join(', '), w.enabled ? 'Активна' : 'Отключена',
                    `Ожидают: ${st.pending || 0}; отправлены: ${st.delivered || 0}; ошибки: ${st.failed || 0}`, buttons];
            })));
        } catch (e) { if (live() && current === generation) content.replaceChildren(message(e)); }
    }
    await load(); return root;
}
