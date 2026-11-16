
const roles = { administrator: 'Администратор', operator: 'Оператор', viewer: 'Наблюдатель' };
const levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
const state = { user: null, csrf: null, projects: [], project: null, projectCursor: null, projectQuery: {}, projectDirectory:null, sourceManagement:null, route: 'overview', epoch: 0, authEpoch: 0, controller: null, filters: null, logPosition: null, dashboard: null, explorerLayout: null };
const names = { overview: 'Обзор', logs: 'Логи', projects: 'Проекты и источники', tokens: 'API-токены', security: 'Безопасность и аудит', queue: 'Проблемные задания', users: 'Пользователи', rbac: 'Роли и разрешения', system: 'Система', settings: 'Настройки' };
state.enrollmentRequired = false;
state.sessionController = new AbortController();
export function advanceSession() { state.sessionController.abort(); state.sessionController = new AbortController(); state.authEpoch++; }
export function resetWorkspace() { Object.assign(state, {projects: [], project: null, projectCursor: null, projectQuery: {}, projectDirectory:null, sourceManagement:null, filters: null, logPosition: null, dashboard: null, explorerLayout: null}); }
let renderer;
export function installRenderer(fn) { renderer = fn; }
export function render(options) { return renderer(options); }
const projectPath = () => `/projects/${encodeURIComponent(state.project)}`;
const readGrants=['projects:read','logs:read','logs:export'];
const operatorGrants=[...readGrants,'logs:write','sources:create','sources:update','sources:state','tokens:read','tokens:create','tokens:update','tokens:rotate','tokens:revoke','tokens:signing','services:read','services:manage','audit:read','queue:read','queue:retry','queue:discard','sources:manage','tokens:manage'];
const builtinGrants={viewer:readGrants,operator:operatorGrants,administrator:[...operatorGrants,'users:manage','projects:manage','system:read','roles:manage']};
// Older API clients/fixtures may only provide the built-in role. A present empty list is authoritative.
export function grants(user,project=null) {
    const global=Array.isArray(user?.permissions)?user.permissions:(builtinGrants[user?.role]||[]);
    if(!project)return global;
    const effective=Array.isArray(project.effective_permissions)?project.effective_permissions:(builtinGrants[project.effective_role]||[]);
    return global.filter(p=>effective.includes(p));
}
export const hasPermission=(permission, scoped=true)=>{
    if(!state.user)return false;
    if(!scoped)return grants(state.user).includes(permission);
    const project=state.projects.find(p=>p.id===state.project);
    return !!project&&grants(state.user,project).includes(permission);
};
const admin = () => hasPermission('users:manage',false);
const manage = () => admin() || hasPermission('tokens:read');
export const canIssueScope=scope=>scope==='logs:read'?hasPermission('logs:read')&&hasPermission('logs:export'):hasPermission(scope);
// All project switches pass through one boundary. Never reuse a cursor or source filter in another project.
export function selectProject(id) {
    if (id === state.project) return false;
    state.project = id;
    Object.assign(state, {filters:null, logPosition:null, dashboard:null, sourceManagement:null});
    return true;
}
function active(epoch) { return epoch === state.epoch && !!state.user; }

export { roles, levels, state, names, projectPath, admin, manage, active };
