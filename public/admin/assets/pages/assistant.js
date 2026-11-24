import {state,active,hasPermission,levels} from '../app/store.js?v=1.0.6';
import {h,card,field,input,select,button,heading,needProject,message} from '../components/ui.js?v=1.0.6';
import {projectSelector} from '../app/projects.js?v=1.0.6';
import {get,request} from '../api/client.js?v=1.0.6';

const encoder=new TextEncoder();
function clipped(text,limit){let result='';let bytes=0;for(const ch of text){const n=encoder.encode(ch).length;if(bytes+n>limit)break;result+=ch;bytes+=n;}return result;}
export async function assistant(signal,epoch){
    const project=state.project;
    const head=heading('AI Assistant','ChatGPT: исследование ограниченной выборки логов текущего проекта.');
    if(!project)return h('div',{},head,projectSelector(),needProject());
    if(!hasPermission('logs:read'))return h('div',{},head,projectSelector(),card('Нет права чтения логов проекта.'));
    const status=await get('/assistant/status',{project_id:project},signal);
    if(!status.enabled||!status.configured)return h('div',{},head,projectSelector(),card(status.enabled?'OpenAI API не настроен. Обратитесь к администратору.':'AI Assistant отключён администратором.'));
    const conversation=[];
    const chat=h('div',{class:'assistant-chat',role:'log','aria-live':'polite'});
    const question=h('textarea',{rows:4,required:true,maxlength:2000,placeholder:'Например: что известно об ошибках за последние сутки?'});
    const keyword=input('keyword',{maxlength:512,placeholder:'Например: timeout'});
    const hours=select('hours',[[1,'1 час'],[6,'6 часов'],[24,'24 часа'],[72,'3 дня'],[168,'7 дней']],24);
    const level=select('level',[['','Все уровни'],...levels.map(v=>[v,v])],'');
    const consent=input('consent',{type:'checkbox',required:true});
    const notice=h('div',{role:'status',class:'muted'});
    const send=h('button',{type:'submit',class:'primary'},'Отправить');
    const clear=button('Новый чат',()=>{conversation.length=0;chat.replaceChildren();notice.replaceChildren();question.value='';consent.checked=false;});
    const valid=()=>active(epoch)&&!signal.aborted&&state.project===project&&hasPermission('logs:read');
    const form=h('form',{class:'assistant-form'},h('div',{class:'form-grid'},field('Интервал',hours),field('Уровень',level),field('Ключевые слова поиска (необязательно)',keyword)),field('Вопрос',question),h('label',{class:'check'},consent,'Разрешаю отправку вопроса, истории и ограниченного контекста проекта в OpenAI.'),h('div',{class:'actions'},send,clear));
    form.addEventListener('submit',async e=>{
        e.preventDefault();if(send.disabled||!valid()||!consent.checked||!question.value.trim())return;
        const prompt=question.value.trim();
        if(encoder.encode(prompt).length>2000||encoder.encode(keyword.value).length>512){notice.textContent='Вопрос или поисковая строка превышают допустимый размер UTF-8.';return;}
        send.disabled=true;clear.disabled=true;notice.textContent='Получение контекста и ответа OpenAI…';
        try{
            const result=await request('/assistant/chat',{method:'POST',signal,body:{project_id:project,question:prompt,history:conversation.slice(-6),hours:Number(hours.value),keyword:keyword.value.trim(),level:level.value,consent:true}});
            if(!valid())return;
            conversation.push({role:'user',content:prompt},{role:'assistant',content:clipped(result.answer,2400)});
            if(conversation.length>6)conversation.splice(0,conversation.length-6);
            const evidence=h('details',{},h('summary',{},`События в выборке: ${result.evidence.length}${result.truncated?' (есть другие совпадения)':''}`),h('ul',{},result.evidence.map(v=>h('li',{},`${v.timestamp} · ${v.level} · ${v.id}`))));
            chat.append(h('section',{class:'assistant-turn'},h('strong',{},'Вы'),h('p',{class:'assistant-text'},prompt),h('strong',{},'ChatGPT'),h('p',{class:'assistant-text'},result.answer),evidence,h('p',{class:'muted small'},`Модель: ${result.model} · токены: ${result.usage.total_tokens??'нет данных'}`)));
            while(chat.children.length>20)chat.firstElementChild.remove();
            question.value='';notice.textContent='Ответ получен. Проверьте выводы по исходным событиям.';
        }catch(err){if(valid()&&err.name!=='AbortError')notice.replaceChildren(message(err));}
        finally{if(valid()){send.disabled=false;clear.disabled=false;consent.checked=false;question.focus();}}
    });
    signal.addEventListener('abort',()=>{conversation.length=0;chat.replaceChildren();},{once:true});
    return h('div',{},head,projectSelector(),card(h('p',{class:'muted'},`Модель: ${status.model}. Отправляется до 20 событий. ${status.messages_included?'Включены сообщения с маскированием известных секретов; произвольные чувствительные данные могут остаться.':'Передаются только ID, время и уровни событий (текст логов отключён).'} История хранится только в открытом разделе. Не вводите секреты.`),chat,form,notice));
}
