import { roles, state, names } from './store.js?v=0.7.8';
import { $, h, button, notify } from '../components/ui.js?v=0.7.8';
import { clearSession, sessionChanged } from './session.js?v=0.7.8';
import { permitted } from './routes.js?v=0.7.8';
import { themeButton } from './theme.js?v=0.7.8';
import { openWorkspaceSwitcher } from '../components/workspace-switcher.js?v=0.7.8';
import { write } from '../api/client.js?v=0.7.8';

function header() { const a = $('account'); a.replaceChildren(themeButton()); if (state.user) {
    if (!state.enrollmentRequired) a.append(button('Быстрый переход', openWorkspaceSwitcher, '', {'aria-keyshortcuts':'Control+K Meta+K', title:'Ctrl/⌘ + K'}));
    a.prepend(h('span', { class: 'who', title: state.user.email }, (state.user.display_name || state.user.email) + ' · ' + roles[state.user.role]));
    a.append(button('Выйти', async (e) => { const b = e.currentTarget; b.disabled = true; try {
        await write('/auth/logout', 'POST');
        clearSession('Вы вышли из системы.'); sessionChanged();
    }
    catch (err) {
        if (state.user)
            notify(err.message);
    }
    finally {
        b.disabled = false;
    } }));
} }
function navigation() { header(); $('sidebar').hidden = !state.user; $('menu-toggle').hidden = !state.user; closeMenu(); const nav = $('nav'); nav.replaceChildren(); if (!state.user) { $('breadcrumbs').hidden = true; return; } for (const [route, label] of Object.entries(names))
    if (permitted(route))
        nav.append(h('a', { href: '#' + route, 'aria-current': state.route === route ? 'page' : null }, label));
    $('breadcrumbs').replaceChildren(h('span', {}, 'Панель управления'), h('span', {'aria-hidden':'true'}, ' / '), h('span', {'aria-current':'page'}, names[state.route]));
    $('breadcrumbs').hidden = false; }

function closeMenu() { $('sidebar').classList.remove('menu-open'); $('menu-toggle').setAttribute('aria-expanded', 'false'); }
function initShell() {
    document.addEventListener('keydown', e => { if ((e.ctrlKey || e.metaKey) && !e.altKey && e.key.toLowerCase()==='k' && state.user && !state.enrollmentRequired && !$('modal').open) { e.preventDefault(); openWorkspaceSwitcher(); } });
    $('menu-toggle').addEventListener('click', () => {
        const open = $('sidebar').classList.toggle('menu-open');
        $('menu-toggle').setAttribute('aria-expanded', String(open));
        if (open) $('nav').querySelector('[aria-current=page], a')?.focus();
    });
    $('nav').addEventListener('click', e => { if (e.target.closest('a')) closeMenu(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && $('sidebar').classList.contains('menu-open')) { closeMenu(); $('menu-toggle').focus(); } });
    matchMedia('(min-width: 761px)').addEventListener('change', closeMenu);
}

export { header, navigation, initShell };
