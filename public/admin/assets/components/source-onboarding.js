import { state, active, render } from '../app/store.js?v=0.3.8';
import { h, button, field, input, select, form, modal, modalEpoch, clearModal, message, copy, notify, enc } from './ui.js?v=0.3.8';
import { request, get, apiPrefix } from '../api/client.js?v=0.3.8';
import { testPayload, signedHeaders, receipt, pythonExample } from '../app/source-connection.js?v=0.3.8';
import { nameFields, nameBody } from './mutations.js?v=0.3.8';
import { navigate } from '../app/routes.js?v=0.3.8';
import { detail } from './event-inspector.js?v=0.3.8';

export function openSourceLogs(project,source,extra={}) {
    if(state.project!==project) return;
    clearModal(); state.filters={limit:'50',consistency:'live',source_id:source,...extra}; state.logPosition=null; navigate('logs');
}
export async function verifyConnection(source, credentials, payload, signal) {
    const {token,kid='',secret=''}=credentials;
    const identity=await request('/auth/token',{bearer:token,signal});
    if(identity.token?.project_id!==source.project_id || (identity.token.source_id!==null&&identity.token.source_id!==source.id) || !identity.token.scopes?.includes('logs:write')) throw new Error('Токен не разрешает запись в этот источник.');
    const headers=await signedHeaders({endpoint:apiPrefix()+'/ingest',...payload,token,kid,secret});
    const response=await request('/ingest',{method:'POST',body:payload.body,bearer:token,raw:true,extraHeaders:headers,signal});
    const eventId=receipt(response.status,await response.json());
    // Verify the stored event using the administrator's session, not a write-only token.
    const {data}=await get('/logs/'+enc(eventId),{project_id:source.project_id,source_id:source.id},signal);
    if(data?.id!==eventId || data.source_id!==source.id || data.project_id!==source.project_id) throw new Error('Событие принято, но проверка источника сохранённой записи не прошла.');
    return {id:eventId,requestId:data.request_id};
}
export function onboarding(path,project,existing,epoch,signal,types) {
    const root=h('div',{},h('p',{class:'muted'},`Проект: ${project.name}. Создание источника и токена — отдельные подтверждаемые операции. Закрытие мастера не удаляет созданные ресурсы.`));
    const stage=h('div');root.append(stage);modal('Подключение приложения',root);
    const dialog=modalEpoch, auth=state.authEpoch, controller=new AbortController();
    const scoped=AbortSignal.any([signal,controller.signal]);
    const current=()=>active(epoch)&&state.authEpoch===auth&&dialog===modalEpoch&&!scoped.aborted&&state.project===project.id;
    const node=document.getElementById('modal');
    // Closing or navigating destroys the credential-bearing UI and cancels pending requests.
    const observer=new MutationObserver(()=>{if(!current()){controller.abort();observer.disconnect();}});
    observer.observe(node,{attributes:true,attributeFilter:['open'],childList:true,subtree:true});
    signal.addEventListener('abort',()=>{controller.abort();observer.disconnect();},{once:true});
    const stop=()=>{controller.abort();observer.disconnect();clearModal();if(active(epoch))void render();};
    function issue(source) {
        if(!current())return;
        stage.replaceChildren(h('h3',{},'2. Выпустить токен источника'),h('p',{},`${source.name} (${source.slug}) · только logs:write. Секрет будет показан один раз. Если ответ потерян, проверьте список токенов и отзовите недоступный токен перед повтором.`),form('Выпустить токен подключения',[
            field('Название токена подключения',input('name',{value:'Ingest '+source.slug,required:true,maxlength:255})),
            field('Срок токена, дней',input('days',{type:'number',min:1,max:365,value:30,required:true})),
            field('Режим подписи подключения',select('mode',[['bearer','HMAC v1 (Bearer)'],['separate','HMAC v2 (отдельный ключ)']],'bearer'))
        ],async d=>{
            const result=await request(path+'/tokens',{method:'POST',body:{name:String(d.get('name')).trim(),scopes:['logs:write'],source_id:source.id,expires_in:Number(d.get('days'))*86400,signing_mode:String(d.get('mode'))},signal:scoped});
            if(!current())return;
            credentials(source,result);
        }),button('Завершить без токена',stop));
    }
    function credentials(source,result) {
        const token=result.token,kid=result.signing?.current_key_id||'',secret=result.signing?.signing_secret||'';
        const payload=testPayload(source.slug);const status=h('div',{role:'status','aria-live':'polite'});const actions=h('div',{class:'actions'});
        const snippet=pythonExample(location.origin+apiPrefix()+'/ingest',source.slug);
        const test=button('Проверить подключение',async()=>{
            test.disabled=true;status.replaceChildren('Проверяем сохранение тестового события…');
            try {const event=await verifyConnection(source,{token,kid,secret},payload,scoped);if(!current())return;
                status.replaceChildren(h('p',{},'Тестовое событие сохранено и найдено: '+event.id));
                actions.replaceChildren(button('Открыть тестовое событие в логах',()=>{if(current())openSourceLogs(project.id,source.id,event.requestId?{request_id:event.requestId}:{});}),button('Инспектор тестового события',()=>{if(current()){controller.abort();observer.disconnect();void detail(event.id,project.id);}}),button('Завершить',stop));
            }catch(e){if(current()&&e.name!=='AbortError')status.replaceChildren(message(e),h('p',{class:'muted'},'Повтор использует тот же Idempotency-Key и тело, но новую подпись. Если токен потерян, отзовите его в разделе API-токены.'));}
            finally{test.disabled=false;}
        },'primary');
        actions.append(test,button('Завершить без проверки',stop));
        stage.replaceChildren(h('h3',{},'3. Настроить приложение'),h('p',{},'Сохраните токен в хранилище секретов приложения. Не помещайте его в код, URL или Git.'),h('pre',{class:'secret'},token),button('Скопировать токен подключения',()=>void copy(token)),kid?h('div',{},h('p',{},'HMAC v2 key ID (CAJEER_SIGNING_KEY_ID)'),h('code',{},kid),button('Скопировать ID ключа',()=>void copy(kid)),h('p',{},'CAJEER_SIGNING_SECRET'),h('pre',{class:'secret'},secret),button('Скопировать секрет HMAC',()=>void copy(secret))):null,
            h('p',{},'Установите CAJEER_TOKEN'+(kid?', CAJEER_SIGNING_KEY_ID и CAJEER_SIGNING_SECRET':'')+' в окружении приложения. Пример ниже использует стандартную библиотеку Python 3 и обязательную подпись.'),h('details',{},h('summary',{},'Пример Python / HTTP'),h('pre',{},snippet),button('Копировать пример Python',()=>void copy(snippet))),h('h3',{},'4. Проверить доставку'),h('p',{class:'muted'},'Проверка отправляет реальный INFO-лог, расходует квоту и проверяет его чтение через сессию. Тест останется в истории согласно retention.'),actions,status);
    }
    if(existing)issue(existing);
    else stage.append(h('h3',{},'1. Создать источник'),form('Создать источник подключения',[...nameFields(),field('Тип подключения',select('source_type',types,'application'))],async d=>{
        const result=await request(path+'/sources',{method:'POST',body:{...nameBody(d),source_type:String(d.get('source_type'))},signal:scoped});
        if(!current())return;
        // Creation succeeded; the returned identifier is enough to proceed, without a second mutation.
        issue({id:result.id,project_id:project.id,...nameBody(d),status:'active'});
    }));
}
