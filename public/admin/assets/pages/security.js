import { state, admin, hasPermission, projectPath } from '../app/store.js?v=0.8.9';
import { h, heading, card, empty } from '../components/ui.js?v=0.8.9';
import { projectSelector } from '../app/projects.js?v=0.8.9';
import { auditSection } from './tokens.js?v=0.8.9';

export async function security(signal,epoch) {
    const root=h('div',{},heading('Безопасность и аудит','История административных операций и доступ к настройкам безопасности.'),projectSelector());
    root.append(card(h('h2',{},'Управление безопасностью'),h('div',{class:'actions'},h('a',{href:'#settings'},'Мой профиль, 2FA и сессии'),hasPermission('tokens:read',false)?h('a',{href:'#tokens'},'API-токены и HMAC'):null,admin()?h('a',{href:'#users'},'Пользователи и доступ'):null)));
    if(state.project && hasPermission('audit:read'))root.append(auditSection(projectPath()+'/security/audit',signal,epoch,false,true));
    else root.append(card(empty('Для просмотра проектного аудита выберите проект с разрешением audit:read.')));
    if(admin())root.append(auditSection('/system/security/audit',signal,epoch,true));
    return root;
}
