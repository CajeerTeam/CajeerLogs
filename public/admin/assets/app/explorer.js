// Display preferences only. Search data, cursors and event values remain in memory.
export const columns = Object.freeze([
    ['timestamp','Время события',220], ['level','Уровень',110], ['source','Источник',150],
    ['service','Сервис',150], ['message','Сообщение',440], ['received_at','Время приёма',220],
    ['host','Host',160], ['trace_id','Trace ID',230], ['request_id','Request ID',230],
    ['tags','Теги',180], ['id','UUID события',290], ['source_id','UUID источника',290]
].map(([key,label,width])=>Object.freeze({key,label,width})));
export function defaultLayout() { return { order:columns.map(c=>c.key), visible:columns.slice(0,5).map(c=>c.key), widths:{}, density:'normal', wrap:true, zone:'utc' }; }
export function normalizeLayout(value) {
    const d=defaultLayout(), keys=d.order;
    if (!value || typeof value!=='object') return d;
    const order=Array.isArray(value.order)?[...new Set(value.order.filter(k=>keys.includes(k)))]:[];
    const visible=Array.isArray(value.visible)?[...new Set(value.visible.filter(k=>keys.includes(k)))]:d.visible;
    const widths={}; for (const k of keys) { const w=value.widths?.[k]; if (Number.isFinite(w)) widths[k]=Math.min(800,Math.max(90,Math.round(w))); }
    return {order:[...order,...keys.filter(k=>!order.includes(k))], visible:visible.length?visible:d.visible, widths, density:value.density==='compact'?'compact':'normal',wrap:value.wrap!==false,zone:value.zone==='local'?'local':'utc'};
}
export function moveColumn(layout,key,direction) { const next=normalizeLayout(layout), i=next.order.indexOf(key), j=i+direction; if(i>=0&&j>=0&&j<next.order.length) [next.order[i],next.order[j]]=[next.order[j],next.order[i]]; return next; }
export function visibleColumns(layout) { const l=normalizeLayout(layout); return l.order.filter(k=>l.visible.includes(k)).map(k=>({...columns.find(c=>c.key===k),width:l.widths[k]??columns.find(c=>c.key===k).width})); }
export function timestampLabel(value,zone='utc') {
    if (!value) return '—';
    const d=new Date(value); if(!Number.isFinite(d.getTime())) return String(value);
    // Preserve server microseconds in UTC; Date is only used for local display.
    if(zone==='utc') return String(value).replace('T',' ').replace(/Z$/,' UTC');
    return d.toLocaleString('ru-RU',{fractionalSecondDigits:3,timeZoneName:'short',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit'});
}
// Literal highlighting, not a claim to reproduce PostgreSQL stemming/FTS ranks.
export function highlightParts(value,query) {
    const s=String(value??''), q=String(query??'').trim();
    if(!q || q.length>512) return [{text:s,match:false}];
    const pattern=new RegExp(q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'),'giu');
    const parts=[]; let end=0;
    for(const m of s.matchAll(pattern)){ if(m.index>end) parts.push({text:s.slice(end,m.index),match:false}); parts.push({text:m[0],match:true});end=m.index+m[0].length; }
    if(end<s.length||!parts.length) parts.push({text:s.slice(end),match:false});return parts;
}
export function pageChanged(baseline,page) { return Boolean(baseline && page?.page_digest && baseline!==page.page_digest); }
