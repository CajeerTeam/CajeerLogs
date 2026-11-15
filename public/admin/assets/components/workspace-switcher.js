import { state, names, active, selectProject } from '../app/store.js?v=0.4.3';
import { $, h, button, field, input, select, form, modal, modalEpoch, clearModal, message, empty, text } from './ui.js?v=0.4.3';
import { get } from '../api/client.js?v=0.4.3';
import { permitted, navigate } from '../app/routes.js?v=0.4.3';

const projectRoutes = ['overview','logs','projects','tokens','security'];

// Search uses the actual project directory, not the first page of the sidebar cache.
export function openWorkspaceSwitcher() {
    if (!state.user || state.enrollmentRequired) return;
    const epoch=state.epoch, auth=state.authEpoch, routeSignal=state.controller?.signal;
    let dialog, generation=0, pending=null, cursor=null, query='', rows=[], choosing=false;
    const modalController=new AbortController(), modalElement=$('modal');
    const scopedSignal=AbortSignal.any([modalController.signal,routeSignal].filter(Boolean));
    const valid=()=>active(epoch)&&state.authEpoch===auth&&dialog===modalEpoch&&modalElement.open&&!scopedSignal.aborted;
    const search=input('workspace_query',{type:'search',maxlength:200,placeholder:'Имя, slug или UUID',autocomplete:'off'});
    const destination=select('workspace_destination',projectRoutes.filter(permitted).map(r=>[r,names[r]]),projectRoutes.includes(state.route)?state.route:'overview');
    const list=h('div',{class:'workspace-results','aria-live':'polite'});
    const status=h('p',{class:'muted small',role:'status'});
    const more=button('Ещё результаты',()=>void load(true)); more.hidden=true;
    const sections=h('div',{class:'workspace-links'},Object.entries(names).filter(([r])=>permitted(r)).map(([r,label])=>button(label,()=>{if(!valid())return;clearModal();navigate(r);},'',{'aria-current':r===state.route?'page':null})));
    function display(){
        list.replaceChildren(...(rows.length?rows.map(p=>button(h('span',{},h('strong',{},p.name),h('small',{},`${p.slug} · ${text(p.effective_role)}${p.status==='archived'?' · архив':''}${p.id===state.project?' · текущий проект':''}`)),()=>void choose(p.id),'workspace-result')):[empty('Проекты не найдены.')]));
        more.hidden=!cursor; status.textContent=`Найдено на загруженных страницах: ${rows.length}`;
    }
    async function load(append=false){
        if(!valid()||choosing)return;
        pending?.abort(); const controller=new AbortController();pending=controller;const seq=++generation;
        const signal=AbortSignal.any([controller.signal,scopedSignal]);
        const current=()=>valid()&&seq===generation&&!signal.aborted;
        if(!append){query=search.value.trim();cursor=null;rows=[];list.replaceChildren();}
        const pageCursor=append?cursor:null;
        status.textContent='Загрузка проектов…';more.disabled=true;more.hidden=true;
        try{
            const data=await get('/projects',{q:query,limit:25,cursor:pageCursor},signal);
            if(!current())return;
            const known=new Set(rows.map(p=>p.id));rows.push(...data.items.filter(p=>!known.has(p.id)));cursor=data.next_cursor??null;display();
        }catch(e){if(current()&&e.name!=='AbortError'){status.textContent='Не удалось загрузить проекты.';list.append(message(e));more.hidden=!pageCursor;}}
        finally{if(current())more.disabled=false;}
    }
    async function choose(id){
        if(!valid()||choosing)return;
        choosing=true;pending?.abort();++generation;status.textContent='Проверяем доступ к проекту…';
        list.querySelectorAll('button').forEach(b=>b.disabled=true);more.disabled=true;
        try{
            const p=await get('/projects/'+encodeURIComponent(id),{},scopedSignal);
            if(!valid())return;
            if(p.id!==id)throw new Error('API вернул другой проект.');
            const target=destination.value;
            state.projects=[...state.projects.filter(x=>x.id!==id),p];selectProject(id);
            clearModal();navigate(permitted(target)?target:'overview');
        }catch(e){if(valid()&&e.name!=='AbortError'){status.textContent='Не удалось открыть проект.';list.append(message(e));}}
        finally{if(valid()){choosing=false;list.querySelectorAll('button').forEach(b=>b.disabled=false);more.disabled=false;}}
    }
    modal('Быстрый переход',h('div',{class:'stack'},h('h3',{},'Разделы'),sections,h('h3',{},'Поиск проектов'),form('Найти проекты',[field('Поиск проекта для перехода',search),field('Открыть раздел',destination)],()=>load(),{cls:'stack'}),status,list,more));
    dialog=modalEpoch;
    // A close event from the previously replaced dialog may still be queued.
    // Only cancel this instance if it is no longer the active, open modal.
    modalElement.addEventListener('close',()=>{if(dialog!==modalEpoch||!modalElement.open)modalController.abort();},{signal:scopedSignal});
    search.focus();void load();
}
