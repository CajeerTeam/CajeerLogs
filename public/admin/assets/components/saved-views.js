import { state, active, render, projectPath } from '../app/store.js?v=0.8.7';
import { h, button, field, input, select, form, message, notify, modal, clearModal } from './ui.js?v=0.8.7';
import { get, request } from '../api/client.js?v=0.8.7';
import { queryFilters } from '../app/filters.js?v=0.8.7';
import { normalizeLayout } from '../app/explorer.js?v=0.8.7';

export function savedViews(signal,epoch) {
 const path=projectPath()+'/saved-views', project=state.project;const valid=()=>active(epoch)&&!signal.aborted&&state.project===project;
 const root=h('details',{class:'saved-views'},h('summary',{},'Сохранённые представления'));const body=h('div',{});root.append(body);let rows=[],selected='',busy=false;
 const picker=select('saved_view',[['','Выберите представление']],'',{'aria-label':'Сохранённое представление'});
 const status=h('div',{role:'status',class:'muted small'});const error=h('div',{});
 function chosen(){return rows.find(x=>x.id===picker.value);}
 function sync(){for(const b of [apply,update,rename,remove])b.disabled=busy||!chosen();create.disabled=busy;reload.disabled=busy;picker.disabled=busy;}
 async function load(){if(busy)return;busy=true;sync();error.replaceChildren();try{const d=await get(path,{},signal);if(!valid())return;rows=d.items;picker.replaceChildren(h('option',{value:''},'Выберите представление'),...rows.map(x=>h('option',{value:x.id},x.name)));picker.value=rows.some(x=>x.id===selected)?selected:'';status.textContent=`${rows.length} / 100 · личные представления проекта`;}catch(e){if(valid()&&e.name!=='AbortError')error.replaceChildren(message(e));}finally{busy=false;sync();}}
 async function mutate(method,suffix,data){if(!valid())return;const result=await request(path+suffix,{method,body:data,signal});if(!valid())return;selected=result?.id||'';clearModal();notify('Представления обновлены.');await load();}
 function capture(name){const filters=queryFilters(state.filters,project);delete filters.project_id;return {name,filters,layout:normalizeLayout(state.explorerLayout)};}
 function editor(mode){const row=chosen();if(mode!=='create'&&!row)return;const name=input('view_name',{value:mode==='create'?'':row.name,required:true,maxlength:120});modal(mode==='create'?'Сохранить представление':mode==='rename'?'Переименовать представление':'Обновить представление',form('Сохранить',[field('Название',name),h('p',{class:'muted small'},mode==='rename'?'Фильтры и колонки останутся прежними.':'Сохраняются применённые фильтры и текущие настройки колонок, без курсоров и результатов.')],async d=>{const n=String(d.get('view_name')).trim();if(!n)throw new Error('Введите название.');const data=mode==='rename'?{name:n,filters:row.filters,layout:row.layout}:capture(n);if(row&&mode!=='create')data.revision=row.revision;await mutate(mode==='create'?'POST':'PATCH',mode==='create'?'':'/'+row.id,data);}));}
 const reload=button('Обновить список',()=>void load());const create=button('Сохранить текущее',()=>editor('create'));const update=button('Обновить выбранное',()=>editor('update'));const rename=button('Переименовать',()=>editor('rename'));
 const apply=button('Применить',async()=>{const row=chosen();if(!row||busy)return;busy=true;sync();try{const fresh=await get(path+'/'+row.id,{},signal);if(!valid())return;state.filters=fresh.filters;state.explorerLayout=normalizeLayout(fresh.layout);state.logPosition=null;await render();}catch(e){if(valid()&&e.name!=='AbortError')error.replaceChildren(message(e));}finally{busy=false;sync();}});
 const remove=button('Удалить',()=>{const row=chosen();if(!row)return;modal('Удалить представление',form('Удалить',[h('p',{},`Удалить «${row.name}»? Логи не будут удалены.`)],()=>mutate('DELETE','/'+row.id)));});
 picker.addEventListener('change',()=>{selected=picker.value;sync();});body.append(h('div',{class:'filter-entry'},picker,apply),h('div',{class:'actions'},create,update,rename,remove,reload),status,error);sync();root.addEventListener('toggle',()=>{if(root.open)void load();});return root;
}
