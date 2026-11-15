import { state, render } from './store.js?v=0.3.7';
import { h, button, select, notify } from '../components/ui.js?v=0.3.7';
import { get } from '../api/client.js?v=0.3.7';

async function loadProjects(signal, more = false) { const result = await get('/projects', { ...state.projectQuery, limit: 100, cursor: more ? state.projectCursor : null }, signal); if (signal?.aborted)
    return; state.projects = more ? [...state.projects, ...result.items.filter(p => !state.projects.some(x => x.id === p.id))] : result.items; state.projectCursor = result.next_cursor; if (!state.project || !state.projects.some(p => p.id === state.project)) {
    const next = state.projects[0]?.id ?? null;
    if (next !== state.project) { state.project = next; state.filters = null; state.logPosition = null; state.dashboard = null; }
} }
function projectSelector() { const picker = select('active_project', state.projects.map(p => [p.id, `${p.name} (${p.slug})${p.status === 'archived' ? ' — архив' : ''}`]), state.project, { 'aria-label': 'Активный проект' }); picker.disabled = !state.projects.length; picker.addEventListener('change', () => { state.project = picker.value; state.filters = null; state.logPosition = null; state.dashboard = null; void render(); }); return h('div', { class: 'project-strip' }, h('strong', {}, 'Проект:'), picker, state.projectCursor ? button('Ещё проекты', async (e) => { const b = e.currentTarget; const signal = state.controller.signal; b.disabled = true; try {
    await loadProjects(signal, true);
    if (!signal.aborted)
        void render();
}
catch (err) {
    if (err.name !== 'AbortError')
        notify(err.message);
}
finally {
    b.disabled = false;
} }) : null, h('a', { href: '#projects' }, 'Управление проектами'), button('Обновить проекты', async (e) => { const b = e.currentTarget; const signal = state.controller.signal; b.disabled = true; try {
    await loadProjects(signal);
    if (!signal.aborted)
        void render();
}
catch (err) {
    if (err.name !== 'AbortError')
        notify(err.message);
}
finally {
    b.disabled = false;
} })); }

export { loadProjects, projectSelector };
