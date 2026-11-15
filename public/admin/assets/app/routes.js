import { state, names, admin, render } from './store.js?v=0.3.5';

function currentRoute() { const route = location.hash.slice(1); return Object.hasOwn(names, route) ? route : 'overview'; }
function navigate(route) { if (!Object.hasOwn(names, route)) throw new Error('Неизвестный раздел.'); if (location.hash === '#' + route) void render({ focus: true }); else location.hash = route; }
function permitted(route) {
    if (!state.user || !Object.hasOwn(names, route)) return false;
    if (state.enrollmentRequired) return route === 'settings';
    if (['users', 'system'].includes(route)) return admin();
    // Operators may switch to another project from Tokens; project mutations remain guarded by manage().
    if (route === 'tokens') return admin() || state.user.role === 'operator';
    return true;
}

export { currentRoute, navigate, permitted };
