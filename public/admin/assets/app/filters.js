import { levels } from './store.js?v=0.8.3';

export const listFields = Object.freeze([
 ['levels','Уровни',32],['sources','Источники',100],['services','Сервисы',255],['hosts','Hosts',255],
 ['source_ids','UUID источников',36],['trace_ids','Trace IDs',128],['request_ids','Request IDs',128],['tags_all','Все теги (ALL)',128],['tags_any','Любой тег (ANY)',128]
]);
export const defaultFilters = () => ({limit:'50',consistency:'live'});
const bytes = s => new TextEncoder().encode(s).length;
export function readList(raw) { if (!raw) return []; const v=JSON.parse(raw); if (!Array.isArray(v)||v.some(x=>typeof x!=='string')) throw new Error('Некорректный список.'); return v; }
export function validateFilters(draft) {
 const next={}; for (const [k,v] of Object.entries(draft)) if (typeof v==='string'&&v.trim()!=='') next[k]=v;
 const uuid=/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
 const slug=/^[a-z0-9]+(?:-[a-z0-9]+)*$/;
 let count=0;
 for (const [key,label,max] of [...listFields,['context_path','JSON-путь',256]]) {
   if (!next[key]) continue;
   const values=readList(next[key]);
   if (!values.length||values.length>(key==='context_path'?8:16)||values.some(v=>!v.trim()||bytes(v)>max||v.includes('\0')||(key==='levels'&&!levels.includes(v))||(key==='sources'&&!slug.test(v))||(key==='source_ids'&&!uuid.test(v)))) throw new Error('Недопустимые значения: '+label);
   if (key!=='context_path') count+=values.length;
   next[key]=JSON.stringify(key==='context_path'?values:[...new Set(values)].sort());
   if(bytes(next[key])>(key==='context_path'?2048:4096)) throw new Error('Слишком длинный список: '+label);
 }
 if(count>64) throw new Error('Максимум 64 значения в списках.');
 for(const [key,max] of Object.entries({q:512,source:100,source_id:36,service:255,host:255,trace_id:128,request_id:128,tag:128,context:4096,context_equals:4096,related_event:36})) if(next[key]&&(bytes(next[key])>max||next[key].includes('\0'))) throw new Error('Недопустимая длина: '+key);
 if(next.source&&!slug.test(next.source)||next.source_id&&!uuid.test(next.source_id)) throw new Error('Некорректный источник.');
 if(next.related_event&&!uuid.test(next.related_event)) throw new Error('Недопустимый UUID события корреляции.');
 if(next.relation&&(!next.related_event||!['trace','request','either'].includes(next.relation)))throw new Error('Недопустимый режим корреляции.');
 if(next.q_mode&&!next.q) throw new Error('Для режима поиска введите текст.');
 for(const key of ['from','to']) if(next[key]) { const v=next[key]; if(!Number.isFinite(Date.parse(v.endsWith('Z')?v:v+'Z'))) throw new Error('Некорректная дата.'); }
 const from=next.from?Date.parse(next.from.endsWith('Z')?next.from:next.from+'Z'):null,to=next.to?Date.parse(next.to.endsWith('Z')?next.to:next.to+'Z'):null;
 if(from!==null&&to!==null&&from>=to) throw new Error('Начало периода должно быть раньше конца.');
 if(next.error_fingerprint&&(!/^[0-9a-f]{64}$/.test(next.error_fingerprint)||from===null||to===null||to-from>31*86400000)) throw new Error('Для группировки ошибок нужен отпечаток и диапазон до 31 дня.');
 if(next.related_event&&(from===null||to===null||to-from>7*86400000))throw new Error('Корреляция требует диапазон не более 7 дней.');
 if(next.context_path&&(from===null||to===null||to-from>31*86400000)) throw new Error('Для JSON-пути нужен диапазон до 31 дня.');
 if(next.context) { let obj;try{obj=JSON.parse(next.context);}catch{throw new Error('JSON context: некорректный JSON.');}if(!obj||Array.isArray(obj)||typeof obj!=='object')throw new Error('JSON context должен быть объектом.'); }
 if(next.context_equals) { if(!next.context_path)throw new Error('Укажите JSON-путь.');let v;try{v=JSON.parse(next.context_equals);}catch{throw new Error('Некорректное JSON-значение.');}if(v!==null&&typeof v==='object')throw new Error('Значение JSON-пути должно быть скаляром.'); }
 if(Object.values(next).reduce((n,v)=>n+bytes(v),0)>16000) throw new Error('Суммарный размер фильтров слишком большой.');
 return {...defaultFilters(),...next};
}
export function queryFilters(filters,project) { const f={...(filters||defaultFilters()),project_id:project}; for(const k of ['from','to']) if(f[k]&&!f[k].endsWith('Z')) {const v=f[k];f[k]=/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,6})?$/.test(v)?v+'Z':new Date(v+'Z').toISOString();}return f; }
export function preset(name, now=new Date()) { const f=defaultFilters(); if(name==='errors')f.levels=JSON.stringify(['alert','critical','emergency','error']);if(name==='critical')f.levels=JSON.stringify(['alert','critical','emergency']);if(name==='warnings')f.level='warning';if(name==='hour'){f.from=new Date(now.getTime()-3600000).toISOString();f.to=now.toISOString();}return f; }
export function removeFilter(filters,key) { const f={...filters};delete f[key];if(key==='q')delete f.q_mode;if(key==='context_path'||key==='from'||key==='to'){delete f.context_path;delete f.context_equals;if(key==='from'||key==='to'){delete f.related_event;delete f.relation;}}if(key==='related_event')delete f.relation;return f; }
