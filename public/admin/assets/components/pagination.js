import { state, active } from '../app/store.js?v=0.9.1';
import { h, button, message, table } from './ui.js?v=0.9.1';
import { get } from '../api/client.js?v=0.9.1';

function pagedList({ path, query = {}, headers, rows, signal, epoch, renderItems }) {
    const box = h('div', {}, h('p', { role: 'status' }, 'Загрузка…'));
    let cursors = [null], index = 0, next = null, busy = false, pageData = null;
    const project = state.project;
    async function load(target = 0) {
        if (busy)
            return;
        busy = true;
        const previous = box.querySelectorAll('button');
        previous.forEach(x => x.disabled = true);
        try {
            const data = await get(path, { ...query, cursor: cursors[target] }, signal);
            if (!active(epoch) || signal.aborted)
                return;
            index = target;
            next = data.next_cursor;
            pageData = data;
            const items = data.items;
            const controls = h('div', { class: 'pager' }, h('span', { class: 'muted', role: 'status' }, `Страница ${index + 1} · ${items.length} записей`), h('div', { class: 'actions' }, button('Назад', () => void load(index - 1), '', { disabled: index === 0 }), button('Далее', () => { cursors[index + 1] = next; cursors = cursors.slice(0, index + 2); void load(index + 1); }, '', { disabled: !next })));
            box.replaceChildren(renderItems ? renderItems(items, project) : table(headers, items.map(rows)), controls);

        }
        catch (e) {
            if (e.name !== 'AbortError' && active(epoch)) {
                if (pageData && e.status !== 403) {
                    box.querySelector('.error')?.remove();
                    box.prepend(message(e));
                }
                else {
                    pageData = null;
                    box.replaceChildren(message(e), button('Повторить', () => void load(target)));
                }
            }
        }
        finally {
            busy = false;
            previous.forEach(x => { if (x.isConnected)
                x.disabled = false; });
            if (pageData) {
                const p = box.querySelector('.pager .actions:last-child'); /* newly rendered controls have their own disabled states */
                if (p && p.children.length === 2) {
                    p.children[0].disabled = index === 0;
                    p.children[1].disabled = !next;
                }
            }
        }
    }
    void load();
    return box;
}
// Server cursor navigation, with only request-local in-memory restoration. Never
// persist log filters or cursors to URLs, localStorage or sessionStorage.

export { pagedList };
