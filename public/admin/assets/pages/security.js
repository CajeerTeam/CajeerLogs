import { state, admin, manage, projectPath } from '../app/store.js?v=0.4.0';
import { h, heading, card, empty } from '../components/ui.js?v=0.4.0';
import { projectSelector } from '../app/projects.js?v=0.4.0';
import { auditSection } from './tokens.js?v=0.4.0';

export async function security(signal,epoch) {
    const root=h('div',{},heading('Безопасность и аудит','История административных операций и доступ к настройкам безопасности.'),projectSelector());
    root.append(card(h('h2',{},'Управление безопасностью'),h('div',{class:'actions'},h('a',{href:'#settings'},'Мой профиль, 2FA и сессии'),h('a',{href:'#tokens'},'API-токены и HMAC'),admin()?h('a',{href:'#users'},'Пользователи и доступ'):null)));
    if(state.project && manage())root.append(auditSection(projectPath()+'/security/audit',signal,epoch,false,true));
    else root.append(card(empty('Для просмотра проектного аудита выберите проект с правами управления токенами.')));
    if(admin())root.append(auditSection('/system/security/audit',signal,epoch,true));
    return root;
}
