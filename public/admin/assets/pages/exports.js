import { state, projectPath, active } from '../app/store.js?v=1.0.4';
import { h, button, card, message, notify, date } from '../components/ui.js?v=1.0.4';
import { request, get, apiPrefix } from '../api/client.js?v=1.0.4';

/** Session-only bulk exports; download is a browser navigation to the authenticated endpoint. */
export function exportJobs(getFilters,signal,epoch) {
    const project=state.project, root=card(h('h2',{},'Массовый экспорт JSON/CSV'));
    const info=h('p',{class:'muted small'},'Диапазон до 31 дня · до 1 000 000 событий или 256 МиБ · файлы доступны 24 часа.');
    const list=h('div',{class:'export-jobs',role:'status'});
    const error=h('div',{role:'alert'});
    const createJson=button('Создать JSON',()=>void create('json'));
    const createCsv=button('Создать CSV',()=>void create('csv'));
    const refresh=button('Обновить задания',()=>void load());
    root.append(info,h('div',{class:'actions'},createJson,createCsv,refresh),error,list);
    const valid=()=>active(epoch)&&!signal.aborted&&state.project===project;
    let timer=null,busy=false;
    function schedule(pending=false) {
        clearTimeout(timer);
        if(pending&&valid())timer=setTimeout(()=>void load(),12000);
    }
    signal.addEventListener('abort',()=>clearTimeout(timer),{once:true});
    async function load() {
        if(!valid())return;
        try {
            const data=await get(projectPath()+'/exports',{limit:30},signal);
            if(!valid())return;
            list.replaceChildren();
            if(!data.items.length)list.append(h('p',{class:'muted'},'Экспортных заданий пока нет.'));
            for(const job of data.items) {
                const status=({queued:'В очереди',processing:'Выполняется',ready:'Готов',failed:'Ошибка',expired:'Истёк'})[job.status]||job.status;
                const label=h('span',{},`${job.format.toUpperCase()} · ${status} · ${Number(job.row_count).toLocaleString('ru-RU')} событий · ${date(job.created_at)}`);
                const link=job.status==='ready' ? h('a',{
                    href:apiPrefix()+projectPath()+'/exports/'+encodeURIComponent(job.id)+'/download',
                    download:'cajeerlogs-'+job.id+'.'+job.format,
                },'Скачать') : null;
                const detail=job.error_code?h('span',{class:'muted small'},job.error_code):null;
                list.append(h('div',{class:'actions'},label,link,detail));
            }
            schedule(data.items.some(x=>x.status==='queued'||x.status==='processing'));
        } catch(e){if(valid()&&e.name!=='AbortError')error.replaceChildren(message(e));}
    }
    async function create(format) {
        if(!valid()||busy)return;
        const filters=getFilters();
        if(!filters.from||!filters.to) {error.replaceChildren(h('p',{class:'error'},'Для массового экспорта укажите начало и конец периода (до 31 дня).'));return;}
        const {project_id,cursor,limit,consistency,format:unusedFormat,...scoped}=filters;
        busy=true;createJson.disabled=true;createCsv.disabled=true;error.replaceChildren();
        try {
            const result=await request(projectPath()+'/exports',{method:'POST',body:{format,filters:scoped},signal});
            if(!valid())return;
            notify('Задание '+result.job.id+' поставлено в очередь.');
            await load();
        } catch(e){if(valid()&&e.name!=='AbortError')error.replaceChildren(message(e));}
        finally {busy=false;createJson.disabled=false;createCsv.disabled=false;}
    }
    void load();
    return root;
}
