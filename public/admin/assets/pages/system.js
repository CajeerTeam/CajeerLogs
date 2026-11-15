import { admin, render } from '../app/store.js?v=0.3.7';
import { h, button, card, badge, heading, table, copy } from '../components/ui.js?v=0.3.7';
import { redactionButton } from './projects.js?v=0.3.7';
import { request, get, ready } from '../api/client.js?v=0.3.7';

async function system(signal) { const root = h('div', {}, heading('Система', 'Диагностика runtime, PostgreSQL и миграций.', button('Обновить', () => void render()))); const [health, readiness, report] = await Promise.all([get('/health', {}, signal), ready(signal), request('/system/report', { signal, raw: true, allowStatus: [503] }).then(r => r.json())]); root.append(card(h('h2', {}, 'Состояние'), table(['Проверка', 'Результат'], [['Runtime', badge(health.status)], ['PostgreSQL readiness', badge(readiness.status === 200 ? 'ok' : 'down')], ['Версия', health.version], ['Среда', report.environment || '—'], ['PHP', report.php || '—'], ['Общий статус', badge(report.status || 'down')]])), card(h('h2', {}, 'Системный отчёт'), h('pre', {}, JSON.stringify(report, null, 2)), button('Скопировать отчёт', () => void copy(JSON.stringify(report, null, 2))))); if(admin()) root.append(card(h('h2',{},'Глобальное маскирование'),redactionButton('/system/redaction'))); return root; }

export { system };
