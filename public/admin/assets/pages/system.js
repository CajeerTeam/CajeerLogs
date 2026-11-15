import { admin, render, active } from '../app/store.js?v=0.4.2';
import { h, button, card, badge, heading, table, copy, message, empty } from '../components/ui.js?v=0.4.2';
import { redactionButton } from './projects.js?v=0.4.2';
import { request, ready } from '../api/client.js?v=0.4.2';

// Each endpoint is independent: a failed report must not hide successful liveness/readiness.
async function system(signal, epoch) {
    const root=h('div',{},heading('Система','Диагностика runtime, PostgreSQL и миграций.',button('Обновить',()=>void render())));
    const probes=[['Runtime',()=>request('/health',{signal,withStatus:true,allowStatus:[503]})],['PostgreSQL readiness',()=>ready(signal)],['Системный отчёт',()=>request('/system/report',{signal,withStatus:true,allowStatus:[503]})]];
    const results=await Promise.allSettled(probes.map(([,run])=>run()));
    if(signal.aborted||!active(epoch))return root;
    const report=results[2].status==='fulfilled'?results[2].value.data:null;
    root.append(card(h('h2',{},'Состояние'),table(['Проверка','Результат'],probes.map(([label],i)=>{
        const result=results[i];
        return [label,result.status==='fulfilled'?h('div',{},badge(result.value.status===200?'ok':'down'),h('span',{class:'muted small'},` HTTP ${result.value.status} · ${String(result.value.data?.status??'—')}`)):message(result.reason)];
    })),h('p',{class:'muted small'},'Проверено: '+new Date().toISOString()),table(['Параметр','Значение'],[['Версия',results[0].status==='fulfilled'?results[0].value.data?.version??'—':'—'],['Среда',report?.environment??'—'],['PHP',report?.php??'—']])));
    root.append(card(h('h2',{},'Системный отчёт'),report?h('pre',{},JSON.stringify(report,null,2)):empty('Подробный отчёт недоступен. Остальные проверки показаны выше.'),report?button('Скопировать отчёт',()=>void copy(JSON.stringify(report,null,2))):null));
    if(admin())root.append(card(h('h2',{},'Глобальное маскирование'),redactionButton('/system/redaction')));
    return root;
}
export { system };
