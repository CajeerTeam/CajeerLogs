
const roles = { administrator: 'Администратор', operator: 'Оператор', viewer: 'Наблюдатель' };
const levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
const state = { user: null, csrf: null, projects: [], project: null, projectCursor: null, projectQuery: {}, projectDirectory:null, sourceManagement:null, route: 'overview', epoch: 0, authEpoch: 0, controller: null, filters: null, logPosition: null, dashboard: null, explorerLayout: null };
const names = { overview: 'Обзор', logs: 'Логи', projects: 'Проекты и источники', tokens: 'API-токены', users: 'Пользователи', system: 'Система', settings: 'Настройки' };
state.enrollmentRequired = false;
state.sessionController = new AbortController();
export function advanceSession() { state.sessionController.abort(); state.sessionController = new AbortController(); state.authEpoch++; }
export function resetWorkspace() { Object.assign(state, {projects: [], project: null, projectCursor: null, projectQuery: {}, projectDirectory:null, sourceManagement:null, filters: null, logPosition: null, dashboard: null, explorerLayout: null}); }
let renderer;
export function installRenderer(fn) { renderer = fn; }
export function render(options) { return renderer(options); }
const projectPath = () => `/projects/${encodeURIComponent(state.project)}`;
const admin = () => state.user?.role === 'administrator';
const manage = () => admin() || (state.user?.role === 'operator' && !!state.project && state.projects.find(p => p.id === state.project)?.effective_role !== 'viewer');
function active(epoch) { return epoch === state.epoch && !!state.user; }

export { roles, levels, state, names, projectPath, admin, manage, active };
