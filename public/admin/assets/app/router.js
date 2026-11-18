import { state, names, active, render, installRenderer } from './store.js?v=0.7.0';
import { $, h, button, message, clearModal, heading } from '../components/ui.js?v=0.7.0';
import { clearSession, bootstrap } from './session.js?v=0.7.0';
import { loadProjects, refreshProject } from './projects.js?v=0.7.0';
import { navigation } from './shell.js?v=0.7.0';
import { currentRoute, permitted } from './routes.js?v=0.7.0';
import { overview } from '../pages/overview.js?v=0.7.0';
import { logs } from '../pages/logs.js?v=0.7.0';
import { projects } from '../pages/projects.js?v=0.7.0';
import { tokens } from '../pages/tokens.js?v=0.7.0';
import { security } from '../pages/security.js?v=0.7.0';
import { users } from '../pages/users.js?v=0.7.0';
import { system } from '../pages/system.js?v=0.7.0';
import { rbac } from '../pages/rbac.js?v=0.7.0';
import { queue } from '../pages/queue.js?v=0.7.0';
import { webhooks } from '../pages/webhooks.js?v=0.7.0';
import { settings } from '../pages/settings.js?v=0.7.0';

async function renderRoute({ focus = false, background = false } = {}) {
    if (!state.user)
        return;
    state.controller?.abort();
    clearModal();
    const controller = new AbortController();
    state.controller = controller;
    const signal = controller.signal;
    const epoch = ++state.epoch;
    state.route = currentRoute();
    if (location.hash !== '#' + state.route && location.hash) history.replaceState(null, '', '#' + state.route);
    if (!permitted(state.route)) {
        state.route = state.enrollmentRequired ? 'settings' : permitted('overview')?'overview':'projects';
        history.replaceState(null, '', '#'+state.route);
    }
    navigation();
    const main = $('main');
    main.setAttribute('aria-busy', 'true');
    main.replaceChildren(h('div', { class: 'loading', role: 'status' }, h('span', { class: 'spinner', 'aria-hidden': 'true' }), 'Загрузка…'));
    document.title = names[state.route] + ' — CajeerLogs';
    if (focus) main.focus();
    try {
        if (['overview', 'logs', 'projects', 'tokens', 'security', 'queue', 'webhooks'].includes(state.route)) {
            if(state.project) await refreshProject(signal, background);
            if(!active(epoch)||signal.aborted)return;
            if(!state.projects.length) { await loadProjects(signal,false,background); if(active(epoch)&&!signal.aborted&&state.project) await refreshProject(signal, background); }
        }
        if (!active(epoch) || signal.aborted)
            return;
        const view = await pages[state.route](signal, epoch, background);
        if (!active(epoch) || signal.aborted)
            return;
        main.replaceChildren(view);
    }
    catch (e) {
        if (e.name === 'AbortError' || !active(epoch))
            return;
        main.replaceChildren(heading(names[state.route], 'Не удалось загрузить раздел.'), message(e), button('Повторить', () => void render()));
    } finally {
        if (active(epoch)) main.removeAttribute('aria-busy');
    }
}
document.querySelector('.skip').addEventListener('click', e => { e.preventDefault(); $('main').focus(); });
window.addEventListener('hashchange', () => { if (state.user && (location.hash !== '#'+state.route || !state.controller || state.controller.signal.aborted))
    void render({ focus: true }); });
// Revalidate on browser back/forward cache restoration; do not keep an old authenticated view.
window.addEventListener('pageshow', e => { if (e.persisted) {
    clearSession();
    void bootstrap();
} });
const pages = { overview, logs, projects, tokens, security, users, rbac, system, settings, queue, webhooks };

installRenderer(renderRoute);

export { renderRoute };
