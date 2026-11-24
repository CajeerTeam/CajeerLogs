import { state, render, selectProject } from './store.js?v=1.1.0';
import { h, button, select, notify } from '../components/ui.js?v=1.1.0';
import { get, request } from '../api/client.js?v=1.1.0';

async function loadProjects(signal, more = false, background=false) {
    const read=(path,query,signal)=>background?request(path,{query,signal,extraHeaders:{'X-Cajeer-Background':'1'}}):get(path,query,signal);
    const auth=state.authEpoch, selected=state.project;
    const valid=()=>!signal?.aborted&&state.authEpoch===auth&&state.project===selected;
    const result=await read('/projects',{...state.projectQuery,limit:100,cursor:more?state.projectCursor:null},signal);
    if(!valid())return;
    const items=more?[...state.projects,...result.items.filter(p=>!state.projects.some(x=>x.id===p.id))]:result.items;
    // A project selected from the directory may be outside the first selector page.
    // Preserve it only after a fresh authorization check; never retain revoked projects.
    if(selected&&!items.some(p=>p.id===selected)&&!more){
        try{const current=await read('/projects/'+encodeURIComponent(selected),{},signal);if(!valid())return;items.push(current);}
        catch(e){if(!valid())return;if(e.status!==403&&e.status!==404)throw e;}
    }
    if(!valid())return;
    state.projects=items;state.projectCursor=result.next_cursor;
    if(!selected||!items.some(p=>p.id===selected)){
        const next=items[0]?.id??null;
        selectProject(next);
    }
}
function projectSelector() { const picker = select('active_project', state.projects.map(p => [p.id, `${p.name} (${p.slug})${p.status === 'archived' ? ' — архив' : ''}`]), state.project, { 'aria-label': 'Активный проект' }); picker.disabled = !state.projects.length; picker.addEventListener('change', () => { selectProject(picker.value); void render(); }); return h('div', { class: 'project-strip' }, h('strong', {}, 'Проект:'), picker, state.projectCursor ? button('Ещё проекты', async (e) => { const b = e.currentTarget; const signal = state.controller.signal; b.disabled = true; try {
    await loadProjects(signal, true);
    if (!signal.aborted)
        void render();
}
catch (err) {
    if (err.name !== 'AbortError')
        notify(err.message);
}
finally {
    b.disabled = false;
} }) : null, h('a', { href: '#projects' }, 'Управление проектами'), button('Обновить проекты', async (e) => { const b = e.currentTarget; const signal = state.controller.signal; b.disabled = true; try {
    await loadProjects(signal);
    if (!signal.aborted)
        void render();
}
catch (err) {
    if (err.name !== 'AbortError')
        notify(err.message);
}
finally {
    b.disabled = false;
} })); }

// Refresh the current project's effective role before displaying its protected workspace.
// Only an actual 403/404 discards the selection; network failures remain retryable.
// One in-flight access check per navigation scope. Focus/poll and page load must not
// race each other to overwrite effective_role with an older response.
let refreshInFlight = null;
export function refreshProject(signal, background=false) {
    const key=state.project, auth=state.authEpoch;
    if(!key)return Promise.resolve(false);
    if(refreshInFlight && refreshInFlight.key===key && refreshInFlight.auth===auth && refreshInFlight.signal===signal && refreshInFlight.background===background) return refreshInFlight.promise;
    const slot={key,auth,signal,background};
    slot.promise=fetchProject(signal,background).finally(()=>{if(refreshInFlight===slot)refreshInFlight=null;});
    refreshInFlight=slot;return slot.promise;
}
async function fetchProject(signal,background) {
    const id=state.project, auth=state.authEpoch;
    if(!id) return false;
    const valid=()=>!signal?.aborted && state.authEpoch===auth && state.project===id;
    try {
        const row=await request('/projects/'+encodeURIComponent(id),{signal,extraHeaders:background?{'X-Cajeer-Background':'1'}:{}});
        if(!valid())return false;
        const old=state.projects.find(p=>p.id===id);
        state.projects=[...state.projects.map(p=>p.id===id?row:p)];
        if(!old)state.projects.push(row);
        return !old || old.effective_role!==row.effective_role || old.status!==row.status || JSON.stringify(old.effective_permissions??null)!==JSON.stringify(row.effective_permissions??null);
    } catch(e) {
        if(!valid())return false;
        if(e.status!==403&&e.status!==404)throw e;
        state.projects=[];state.projectCursor=null;state.projectQuery={};selectProject(null);
        return true;
    }
}
export { loadProjects, projectSelector };
