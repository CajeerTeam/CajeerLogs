import { state, names, hasPermission, render } from './store.js?v=0.4.2';

function currentRoute() { const route = location.hash.slice(1); return Object.hasOwn(names, route) ? route : 'overview'; }
function navigate(route) { if (!Object.hasOwn(names, route)) throw new Error('Неизвестный раздел.'); if (location.hash !== '#' + route) location.hash = route; void render({ focus: true }); }
function permitted(route) {
    if (!state.user || !Object.hasOwn(names, route)) return false;
    if (state.enrollmentRequired) return route === 'settings';
    if(route==='users')return hasPermission('users:manage',false);
    if(route==='rbac')return hasPermission('roles:manage',false);
    if(route==='system')return hasPermission('system:read',false);
    if(route==='tokens')return hasPermission('tokens:read',false)||hasPermission('services:read',false)||hasPermission('users:manage',false);
    if(route==='security')return hasPermission('audit:read',false)||hasPermission('users:manage',false);
    if(route==='overview'||route==='logs')return hasPermission('logs:read',false);
    return true;
}

export { currentRoute, navigate, permitted };
