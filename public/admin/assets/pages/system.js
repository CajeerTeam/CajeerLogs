import { admin, render, active } from '../app/store.js?v=0.7.6';
import { h, button, card, badge, heading, table, copy, message, empty, modal, clearModal, notify } from '../components/ui.js?v=0.7.6';
import { redactionButton } from './projects.js?v=0.7.6';
import { request, ready } from '../api/client.js?v=0.7.6';

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
    if(report?.rate_limits) root.append(card(h('h2',{},'Ограничения запросов'),table(['Бюджет','Запросов/мин','Всплеск'],Object.entries(report.rate_limits).map(([key,value])=>[key,String(value.per_minute),String(value.burst)])),h('p',{class:'muted small'},'Общие лимиты PostgreSQL. Дополнительно действуют квоты токенов, проектов и источников.')));
    if(report?.deployment_security) { const d=report.deployment_security; root.append(card(h('h2',{},'Безопасность развёртывания'),table(['Проверка','Значение'],[['HTTPS APP_URL',d.https_configured?'Да':'Нет'],['Проверка Host',d.production_host_validation?'Включена':'Отключена'],['Доверенные proxy (записи)',String(d.trusted_proxy_count)],['PostgreSQL TLS',d.database_sslmode],['Обязательная проверка сертификата БД',d.database_tls_required?'Да':'Нет']]),h('p',{class:'muted small'},'Проверить внешний TLS-сертификат: php bin/cajeer tls:check'))); }
    if(report?.workers) root.append(card(h('h2',{},'Фоновые процессы'),table(['Процесс','Последняя успешная активность','Состояние'],Object.entries(report.workers).map(([role,w])=>[role,w.last_success_timestamp?new Date(w.last_success_timestamp*1000).toISOString():'Нет данных',badge(w.status)])),h('p',{class:'muted small'},'Heartbeat подтверждает последний успешный проход, а не наличие процесса. Backup worker опционален.')));
    if(report?.backup) { const b=report.backup; root.append(card(h('h2',{},'Резервное копирование'),table(['Проверка','Значение'],[['Последний плановый backup',b.last_success_at??'Нет данных'],['Длительность backup',b.last_success_seconds==null?'—':`${b.last_success_seconds} с`],['Внешняя копия',b.last_offsite_verified?'Проверена':'Не подтверждена'],['Репликация ожидает',b.replication_pending?'Да':'Нет'],['Последняя проверка восстановления',b.last_restore_at??'Нет данных'],['Длительность восстановления',b.last_restore_seconds==null?'—':`${b.last_restore_seconds} с`]]),h('p',{class:'muted small'},'Команды оператора: backup:run, backup:history, backup:rehearse. Восстановление выполняется только в отдельную пустую БД.'))); }
    const capacity=report?.operations?.database;
    // Cluster-wide pause is restricted to administrators with a fresh session.
    // GET status and both writes are protected independently by the server.
    if(report?.queue_dispatch){
        const d=report.queue_dispatch, paused=!!d.paused;
        const center=h('div',{class:'stack'},
            table(['Параметр','Значение'],[
                ['Выдача новых jobs',paused?'Приостановлена':'Разрешена'],
                ['Готовы к обработке',String(d.ready_jobs)],
                ['Активные leases',String(d.live_running)],
                ['Истекшие leases',String(d.expired_leases)],
                ['Поколение управления',String(d.generation)],
                ['Причина',d.reason||'—'],
                ['Последнее изменение',d.changed_at||'—']
            ]),
            h('p',{class:'muted small'},'Пауза блокирует только новые claims. Admission, активные транзакции и сохранённые задания остаются независимыми. CLI: queue:dispatch, queue:drain [SECONDS] --confirm, queue:resume --confirm.')
        );
        if(admin()){
            const buttons=h('div',{class:'actions'});
            const action=paused?'resume':'pause';
            const open=()=>{
                const check=h('input',{type:'checkbox'}),reason=h('input',{maxlength:200,placeholder:'Причина (без персональных данных)'});
                const error=h('div'),commit=button(paused?'Возобновить dispatch':'Приостановить dispatch',async()=>{
                    if(!check.checked||!active(epoch)||signal.aborted)return;
                    commit.disabled=true;
                    try{
                        await request('/system/queue/dispatch/'+action,{method:'POST',body:paused?{confirm:true}:{confirm:true,reason:reason.value},signal});
                        if(!active(epoch)||signal.aborted)return;
                        clearModal();notify(paused?'Выдача заданий возобновлена.':'Новые claims приостановлены.');void render();
                    }catch(e){if(active(epoch)&&!signal.aborted)error.replaceChildren(message(e));commit.disabled=false;}
                },'primary',{disabled:true});
                check.addEventListener('change',()=>{commit.disabled=!check.checked;});
                modal('Управление dispatch',h('div',{class:'stack'},
                    h('p',{},paused?'Возобновить обработку уже принятых jobs?':'Остановить выдачу новых leases во всех worker-процессах? Уже выполняемые jobs завершаются.'),
                    paused?null:reason,
                    h('label',{class:'check'},check,'Подтверждаю изменение dispatch'),commit,error));
            };
            buttons.append(button(paused?'Возобновить выдачу':'Приостановить выдачу',open));center.append(buttons);
        }
        root.append(card(h('h2',{},'Queue Center · управление dispatch'),center));
    }
    if(capacity?.queue_accepted_jobs_total!=null){
        const c=capacity;
        root.append(card(h('h2',{},'Queue Center · подтверждённая обработка'),
            table(['Показатель','Значение'],[
                ['Принято jobs (с 0.5.9)',String(c.queue_accepted_jobs_total)],
                ['Принято событий',String(c.queue_accepted_events_total)],
                ['Завершено jobs',String(c.queue_completed_jobs_total)],
                ['Записано событий',String(c.queue_completed_events_total)],
                ['Повторные попытки',String(c.queue_retries_total)],
                ['Переходы в dead-letter',String(c.queue_dead_letter_total)],
                ['Истекшие leases',String(c.queue_lease_expirations_total)],
                ['Средняя доставка, с',c.queue_completed_jobs_total>0?String(Math.round(c.queue_latency_ms_total/c.queue_completed_jobs_total)/1000):'—']
            ]),h('p',{class:'muted small'},'Только подтверждённые PostgreSQL-транзакции. Счётчики начинаются с миграции 0025 и сохраняются при перезапуске workers. Prometheus предоставляет histogram для p95/p99.')));
    }
    if(report?.queue_retry_policy) { const p=report.queue_retry_policy; root.append(card(h('h2',{},'Повторные попытки очереди'),table(['Параметр','Значение'],[['Попытки новых jobs',String(p.max_attempts)],['Базовая задержка, с',String(p.base_seconds)],['Максимальная задержка, с',String(p.max_seconds)],['Jitter, %',String(p.jitter_percent)],['Предел ошибок восстановления',String(p.outage_max_failures)]]))); }
    if(report?.queue_workers) { const q=report.queue_workers; root.append(card(h('h2',{},'Queue workers'),h('p',{},`Активны: ${q.active} · ${q.available&&!q.truncated?'registry доступен':'registry недоступен или неполон'}`),table(['Worker','PID','Обработано','Последняя активность','Состояние'],(q.workers||[]).map(w=>[w.id,String(w.pid),String(w.processed),w.last_success?new Date(w.last_success*1000).toISOString():'Нет данных',badge(w.status)])),h('p',{class:'muted small'},'Индивидуальные heartbeat; число активных процессов оценивается по свежести.'))); }
    if(report?.queue_project_summary) { const p=report.queue_project_summary; root.append(card(h('h2',{},'Справедливость очереди по проектам'),table(['Показатель','Значение'],[['Проектов',String(p.queue_project_projects??0)],['В ожидании',String(p.queue_project_pending??0)],['В обработке',String(p.queue_project_running??0)],['Выдано claims с начала учёта',String(p.queue_project_dispatched??0)],['Отклонено проектными лимитами',String(p.queue_project_rejected??0)]]),h('p',{class:'muted small'},'Проектные веса и лимиты: queue:projects, queue:project UUID, queue:project-set UUID WEIGHT MAX_JOBS MAX_EVENTS MAX_BYTES MAX_RUNNING --confirm. Сверка: queue:project-verify.'))); }
    if(capacity?.queue_dead_jobs!=null) root.append(card(h('h2',{},'Dead-letter'),table(['Параметр','Значение'],[['Проблемные задания',String(capacity.queue_dead_jobs)],['События',String(capacity.queue_dead_events)],['Размер payload',`${capacity.queue_dead_bytes} байт`],['Возраст старейшего, с',String(Math.floor(capacity.queue_oldest_dead_seconds))]]),h('a',{href:'#queue'},'Управление проблемными заданиями')));
    if(capacity?.queue_active_jobs!=null) root.append(card(h('h2',{},'Ёмкость очереди'),table(['Параметр','Значение'],[['Активные задания',`${capacity.queue_active_jobs} / ${capacity.queue_max_jobs}`],['События',`${capacity.queue_active_events} / ${capacity.queue_max_events||'∞'}`],['Размер JSONB payload',`${capacity.queue_active_bytes} / ${capacity.queue_max_bytes||'∞'} байт`],['Приём новых jobs',capacity.queue_admission_paused?'Приостановлен':'Разрешён'],['Порог остановки',`${capacity.queue_high_watermark_percent}%`],['Порог возобновления',`${capacity.queue_low_watermark_percent}%`],['Отказы: jobs / events / bytes / pause',`${capacity.queue_rejected_jobs} / ${capacity.queue_rejected_events} / ${capacity.queue_rejected_bytes} / ${capacity.queue_rejected_paused}`]]),h('p',{class:'muted small'},'Pending, running и dead jobs. Сверка: queue:verify.')));
    if(report?.retention) { const r=report.retention, latest=r.history?.[0], storage=report.operations?.log_storage; root.append(card(h('h2',{},'Хранение и retention'),table(['Параметр','Значение'],[['Плановое удаление',r.scheduler_enabled===false?'Приостановлено':'Включено'],['Окно UTC',r.window_utc||'Круглосуточно'],['Окно сейчас',r.window_open===false?'Закрыто':'Открыто'],['Пакет удаления',String(r.batch_size)],['Timeout запроса',`${r.timeout_ms} мс`],['Всего удалено (с 0.4.9)',String(latest?.total_removed??0)],['Последний проход',latest?.finished_at??'Нет данных'],['Результат',latest?.status??'—'],['Осталось не менее',latest?.backlog_lower_bound==null?'—':String(latest.backlog_lower_bound)],['Примерно живых строк',String(storage?.estimated_live_rows??'—')],['Примерно мёртвых строк',String(storage?.estimated_dead_rows??'—')],['Последний autovacuum',storage?.last_autovacuum??'—'],['Свободно на файловой системе приложения',report.storage_free_bytes==null?'—':`${report.storage_free_bytes} байт`]]),report.storage_warning===true?h('p',{class:'error'},'Мало свободного места на файловой системе приложения. Удаление сверх политики хранения не выполняется.'):null,h('p',{class:'muted small'},'Backlog — нижняя оценка на момент прохода, не точный текущий размер. Счётчики строк PostgreSQL приблизительные. Оператор: retention:preview, retention:history, retention:cycle --confirm.'),r.history?.length?table(['Время','Результат','Удалено','Длительность, мс'],r.history.map(x=>[x.finished_at,x.status,String(x.removed??'—'),String(x.duration_ms??'—')])):empty('История проходов пока пуста.'))); }
    root.append(card(h('h2',{},'Системный отчёт'),report?h('pre',{},JSON.stringify(report,null,2)):empty('Подробный отчёт недоступен. Остальные проверки показаны выше.'),report?button('Скопировать отчёт',()=>void copy(JSON.stringify(report,null,2))):null));
    if(admin())root.append(card(h('h2',{},'Глобальное маскирование'),redactionButton('/system/redaction')));
    return root;
}
export { system };
