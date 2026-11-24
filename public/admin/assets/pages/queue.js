import { state, active, hasPermission, projectPath } from '../app/store.js?v=1.0.2';
import { projectSelector } from '../app/projects.js?v=1.0.2';
import { get, request } from '../api/client.js?v=1.0.2';
import { h, heading, card, field, input, select, button, table, date, text, message, modal, clearModal, notify, needProject } from '../components/ui.js?v=1.0.2';
const reasons=['transaction_conflict','lock_timeout','statement_timeout','database_error','processing_error','invalid_payload','lease_expired','attempts_exhausted'];
export async function queue(signal,epoch) {
    const root=h('div',{},heading('Проблемные задания','Проверка причин отказа, повтор и отказ от durable jobs.'),projectSelector());
    if(!state.project){root.append(needProject());return root;}
    if(!hasPermission('queue:read')){root.append(card('Нет доступа к очереди этого проекта.'));return root;}
    const path=projectPath()+'/queue', live=()=>active(epoch)&&!signal.aborted;
    const reason=select('reason',[['','Все причины'],...reasons.map(x=>[x,x])],'');
    const source=input('source_id',{placeholder:'UUID источника'}), attempts=input('min_attempts',{type:'number',min:1,max:100}), age=input('min_age_seconds',{type:'number',min:0,max:315360000});
    const content=h('div'), notice=h('div',{'aria-live':'polite'}), selected=new Map();
    let cursor=null, query={}, busy=false;
    const filters=h('form',{},h('div',{class:'form-grid'},field('Причина',reason),field('Источник (UUID)',source),field('Минимум попыток',attempts),field('Возраст dead (сек)',age)),h('button',{type:'submit',class:'primary'},'Найти'));
    const actions=h('div',{class:'actions'}), count=h('span',{'aria-live':'polite'});
    const controls=()=>{count.textContent=`Выбрано: ${selected.size} / 20`;for(const b of actions.querySelectorAll('button'))b.disabled=busy||selected.size===0;};
    async function change(action,items){
        if(!live()||busy)return;
        const label=action==='retry'?'Повторить':'Отказаться';
        const confirm=h('input',{type:'checkbox'}), submit=button(label,async()=>{
            if(!confirm.checked||!live()||busy)return;busy=true;controls();submit.disabled=true;
            try{const r=await request(path+'/dead/'+action,{method:'POST',body:{items,confirm:true},signal});if(!live())return;clearModal();notify(`Изменено заданий: ${r.changed}`);await load(null);}
            catch(e){if(live())err.replaceChildren(message(e));}
            finally{busy=false;controls();submit.disabled=!confirm.checked;}
        },'primary',{disabled:true});
        confirm.addEventListener('change',()=>{submit.disabled=!confirm.checked;});const err=h('div');
        modal(label+' задания',h('div',{class:'stack'},h('p',{},`Заданий: ${items.length}. ${action==='discard'?'Payload будет удалён без возможности повтора. Квитанция и история сохранятся.':'Будет повторён исходный payload с прежним job ID.'}`),h('p',{},items.map(i=>i.id).join(', ')),h('label',{class:'check'},confirm,'Подтверждаю операцию'),submit,err));
    }
    if(hasPermission('queue:retry'))actions.append(button('Повторить выбранные',()=>change('retry',[...selected.values()])));
    if(hasPermission('queue:discard'))actions.append(button('Отказаться от выбранных',()=>change('discard',[...selected.values()])));
    actions.append(count);controls();
    async function detail(id){
        try{const d=await get(path+'/jobs/'+encodeURIComponent(id),{},signal);if(!live())return;
            modal('Задание '+id,h('div',{class:'stack'},h('p',{},`Статус: ${d.job.status}; попытки: ${d.job.attempts}/${d.job.max_attempts}`),h('p',{},`Причина: ${text(d.job.last_error)}; событий: ${d.job.payload_events}; байт: ${d.job.payload_bytes}`),table(['Попытка','Начало','Завершение','Результат','Причина'],d.attempts.map(a=>[a.attempt,date(a.started_at),date(a.finished_at),a.outcome,text(a.error)]))));
        }catch(e){if(live())notice.replaceChildren(message(e));}
    }
    let generation=0;
    async function load(next){
        const g=++generation;selected.clear();controls();notice.replaceChildren();content.replaceChildren(h('p',{},'Загрузка…'));
        try{const d=await get(path+'/dead',{...query,cursor:next,limit:50},signal);if(!live()||g!==generation)return;cursor=next;
            content.replaceChildren(table(['Выбор','ID','Причина','Попытки','События / байты','Отказ UTC','История'],d.items.map(j=>{
                const cb=h('input',{type:'checkbox','aria-label':'Выбрать '+j.id});cb.addEventListener('change',()=>{if(cb.checked){if(selected.size>=20){cb.checked=false;notify('Не более 20 заданий.');return;}selected.set(j.id,{id:j.id,attempts:j.attempts});}else selected.delete(j.id);controls();});
                return [cb,h('code',{},j.id),text(j.last_error),`${j.attempts}/${j.max_attempts}`,`${j.payload_events} / ${j.payload_bytes}`,date(j.completed_at),button('История',()=>detail(j.id))];
            })),h('div',{class:'actions'},button('Первая страница',()=>load(null),'',{disabled:cursor===null}),button('Следующая страница',()=>load(d.next_cursor),'',{disabled:!d.next_cursor})));
        }catch(e){if(live()&&g===generation)content.replaceChildren(message(e));}
    }
    filters.addEventListener('submit',e=>{e.preventDefault();if(busy)return;query={reason:reason.value,source_id:source.value.trim(),min_attempts:attempts.value,min_age_seconds:age.value};void load(null);});
    root.append(card(filters,actions,notice,content));await load(null);return root;
}
