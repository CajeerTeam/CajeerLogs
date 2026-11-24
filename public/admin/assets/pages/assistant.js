import {state,active,hasPermission,levels} from '../app/store.js?v=1.0.9';
import {h,card,field,input,select,button,heading,needProject,message} from '../components/ui.js?v=1.0.9';
import {projectSelector} from '../app/projects.js?v=1.0.9';
import {get,request} from '../api/client.js?v=1.0.9';

import {detail} from '../components/event-inspector.js?v=1.0.9';

const encoder=new TextEncoder();
function clipped(text,limit){let result='';let bytes=0;for(const ch of text){const n=encoder.encode(ch).length;if(bytes+n>limit)break;result+=ch;bytes+=n;}return result;}
export async function assistant(signal,epoch){
    const project=state.project;
    const head=heading('AI Assistant','ChatGPT: исследование ограниченной выборки логов текущего проекта.');
    if(!project)return h('div',{},head,projectSelector(),needProject());
    if(!hasPermission('logs:read'))return h('div',{},head,projectSelector(),card('Нет права чтения логов проекта.'));
    const status=await get('/assistant/status',{project_id:project},signal);
    const available=status.enabled&&status.configured;
    const conversation=[];
    const chat=h('div',{class:'assistant-chat',role:'log','aria-live':'polite'});
    const question=h('textarea',{rows:4,required:true,maxlength:2000,placeholder:'Например: что известно об ошибках за последние сутки?'});
    const keyword=input('keyword',{maxlength:512,placeholder:'Например: timeout'});
    const hours=select('hours',[[1,'1 час'],[6,'6 часов'],[24,'24 часа'],[72,'3 дня'],[168,'7 дней']],24);
    const level=select('level',[['','Все уровни'],...levels.map(v=>[v,v])],'');
    const mode=select('investigation_mode',[['chat','Обычный чат'],['incident','Группировка инцидентов'],['auto','Расследование ошибок'],['event_id','По ID события'],['trace_id','По Trace ID'],['request_id','По Request ID']],'chat');
    const target=input('investigation_target',{maxlength:128,placeholder:'Точный идентификатор'});
    const targetField=field('Идентификатор',target);
    const saveHistory=input('save_history',{type:'checkbox'}); const saveField=h('label',{class:'check'},saveHistory,'Сохранить результат в личной истории (30 дней).');
    function syncMode(){const explicit=!['chat','auto','incident'].includes(mode.value);targetField.hidden=!explicit;target.required=explicit;keyword.disabled=explicit;level.disabled=explicit||mode.value==='incident';saveField.hidden=mode.value==='chat';saveHistory.disabled=mode.value==='chat';if(saveHistory.disabled)saveHistory.checked=false;}
    mode.addEventListener('change',syncMode);syncMode();
    const consent=input('consent',{type:'checkbox',required:true});
    const notice=h('div',{role:'status',class:'muted'});
    const send=h('button',{type:'submit',class:'primary'},'Отправить');
    const clear=button('Новый чат',()=>{conversation.length=0;chat.replaceChildren();notice.replaceChildren();question.value='';consent.checked=false;});
    const valid=()=>active(epoch)&&!signal.aborted&&state.project===project&&hasPermission('logs:read');
    const historyList=h('div',{});const historyDetail=h('div',{});const historyNotice=h('div',{role:'status'});
    function renderResult(result){
        const summary=result.investigation;const incidents=result.incidents;
        const groups=incidents?h('section',{},h('h3',{},'Группы инцидентов'),h('p',{class:'muted'},`Ошибок в выборке: ${incidents.sampled_errors} · групп: ${incidents.group_count}. ${incidents.sample_truncated?'Выборка ограничена. ':''}${incidents.groups_truncated?'Показаны крупнейшие группы. ':''}Группировка эвристическая; количество не является общим числом ошибок проекта.`),h('ol',{},incidents.groups.map(g=>h('li',{},`${g.incident} · ${g.sample_count} ошибок · ${g.basis} · ${g.first_seen} — ${g.last_seen} `,button(g.representative_id,()=>{if(valid())void detail(g.representative_id,project);},'explorer-copy'))))):null;
        return h('div',{},groups,h('p',{class:'assistant-text'},result.answer),h('details',{open:!!summary},h('summary',{},`Исходные события: ${result.evidence.length}${result.truncated?' (выборка ограничена)':''}`),summary?h('p',{class:'muted small'},`Исходные: ${summary.seed_count} · связанные: ${summary.related_count} · ошибки: ${summary.error_count} · стектрейсы: ${summary.stack_count}. Корреляция не доказывает причинность.`):null,h('ol',{},result.evidence.map(v=>h('li',{},`${v.timestamp} · ${v.level} ${v.trace||''} ${v.request||''} `,button(v.id,()=>{if(valid())void detail(v.id,project);},'explorer-copy'))))),h('p',{class:'muted small'},`Модель: ${result.model} · токены: ${result.usage.total_tokens??'нет данных'}`));
    }
    async function refreshHistory(){
        try{const result=await get('/assistant/history',{project_id:project},signal);if(!valid())return;historyList.replaceChildren(...(result.items.length?result.items.map(item=>h('div',{class:'actions'},button(`${item.created_at} — ${item.question}`,async()=>{try{const saved=await get('/assistant/history',{project_id:project,id:item.id},signal);if(valid())historyDetail.replaceChildren(h('h3',{},saved.question),renderResult(saved.result));}catch(err){if(valid())historyNotice.replaceChildren(message(err));}}),button('Удалить',async()=>{if(!valid()||!window.confirm('Удалить сохранённое расследование?'))return;try{await request('/assistant/history',{method:'DELETE',query:{project_id:project,id:item.id},signal});if(valid()){historyDetail.replaceChildren();await refreshHistory();}}catch(err){if(valid())historyNotice.replaceChildren(message(err));}}))):[h('p',{class:'muted'},'Нет сохранённых расследований.')]));historyNotice.replaceChildren();}catch(err){if(valid())historyNotice.replaceChildren(message(err));}
    }
    const historyCard=card(h('h2',{},'История расследований'),h('p',{class:'muted'},'Личные сохранённые результаты проекта: до 30 записей, 30 дней. История доступна даже при отключённом OpenAI.'),button('Обновить историю',refreshHistory),historyList,historyNotice,historyDetail);
    void refreshHistory();
    const form=h('form',{class:'assistant-form'},h('div',{class:'form-grid'},field('Режим',mode),targetField,field('Интервал',hours),field('Уровень',level),field('Ключевые слова поиска (необязательно)',keyword)),field('Вопрос',question),h('label',{class:'check'},consent,'Разрешаю отправку вопроса, истории и ограниченного контекста проекта в OpenAI.'),saveField,h('div',{class:'actions'},send,clear));
    form.addEventListener('submit',async e=>{
        e.preventDefault();if(send.disabled||!valid()||!consent.checked||!question.value.trim())return;
        const prompt=question.value.trim();
        if(encoder.encode(prompt).length>2000||encoder.encode(keyword.value).length>512){notice.textContent='Вопрос или поисковая строка превышают допустимый размер UTF-8.';return;}
        send.disabled=true;clear.disabled=true;notice.textContent='Получение контекста и ответа OpenAI…';
        try{
            const body={project_id:project,question:prompt,history:conversation.slice(-6),hours:Number(hours.value),keyword:keyword.disabled?'':keyword.value.trim(),level:level.disabled?'':level.value,consent:true};
            if(mode.value==='incident')body.incident=true;else if(mode.value!=='chat')body.investigation=mode.value==='auto'?{}:{[mode.value]:target.value.trim()};if(!saveHistory.disabled&&saveHistory.checked)body.save_history=true;
            const result=await request('/assistant/chat',{method:'POST',signal,body});
            if(!valid())return;
            conversation.push({role:'user',content:prompt},{role:'assistant',content:clipped(result.answer,2400)});
            if(conversation.length>6)conversation.splice(0,conversation.length-6);
            chat.append(h('section',{class:'assistant-turn'},h('strong',{},'Вы'),h('p',{class:'assistant-text'},prompt),h('strong',{},'ChatGPT'),renderResult(result)));
            if(result.history_id)void refreshHistory();
            while(chat.children.length>20)chat.firstElementChild.remove();
            question.value='';notice.textContent='Ответ получен. Проверьте выводы по исходным событиям.';
        }catch(err){if(valid()&&err.name!=='AbortError')notice.replaceChildren(message(err));}
        finally{if(valid()){send.disabled=false;clear.disabled=false;consent.checked=false;saveHistory.checked=false;question.focus();}}
    });
    signal.addEventListener('abort',()=>{conversation.length=0;chat.replaceChildren();},{once:true});
    return h('div',{},head,projectSelector(),available?card(h('p',{class:'muted'},`Модель: ${status.model}. Отправляется до 20 событий. Расследование включает до 8 исходных событий и связанные по Trace/Request ID записи; идентификаторы корреляции передаются как псевдонимы. При поиске по точному ID ключевые слова и уровень не применяются. ${status.messages_included?'Включены сообщения и ограниченные стектрейсы с маскированием известных секретов; произвольные чувствительные данные могут остаться.':'Передаются ID, время, уровни и псевдонимы связей (текст и стектрейсы отключены).'} Группировка инцидентов анализирует до 200 ошибок и выбирает до 8 представителей групп. История сохраняется только по согласию. Не вводите секреты.`),chat,form,notice):card(status.enabled?'OpenAI API не настроен. Обратитесь к администратору.':'AI Assistant отключён администратором.'),historyCard);
}
