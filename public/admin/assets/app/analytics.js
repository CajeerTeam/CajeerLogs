// UTC analytics helpers. All intervals are half-open [from,to), like LogQuery.
export const MINUTE = 60000;
export const DAY = 86400000;
export const periods = {'15m':15*MINUTE,'1h':60*MINUTE,'6h':360*MINUTE,'24h':DAY,'7d':7*DAY,'30d':30*DAY};
export const widths = {minute:MINUTE,'5min':5*MINUTE,hour:60*MINUTE,day:DAY};
export const errorLevels = ['error','critical','alert','emergency'];
export const utcInput = ms => new Date(ms).toISOString().slice(0,23);
export function parseUtc(value) {
    if (typeof value !== 'string' || !/^\d{4}-\d\d-\d\dT\d\d:\d\d(?::\d\d(?:\.\d{1,3})?)?$/.test(value)) throw new Error('Укажите корректную дату и время UTC.');
    const ms = Date.parse(value+'Z');
    if (!Number.isFinite(ms) || !utcInput(ms).startsWith(value)) throw new Error('Укажите корректную дату и время UTC.');
    return ms;
}
export function rangeFor(config, now=Date.now()) {
    const to = config.period==='custom' ? parseUtc(config.to) : Math.floor(now);
    const from = config.period==='custom' ? parseUtc(config.from) : to-periods[config.period];
    const duration=to-from;
    if (!Number.isFinite(duration)||duration<=0||duration>31*DAY) throw new Error('Период должен быть больше нуля и не превышать 31 день.');
    const auto = duration<=60*MINUTE?'minute':duration<=6*60*MINUTE?'5min':duration<=7*DAY?'hour':'day';
    const interval = config.interval==='auto'?auto:config.interval;
    if (!Object.hasOwn(widths,interval) || (interval==='minute'&&duration>6*60*MINUTE) || (interval==='5min'&&duration>2*DAY)) throw new Error('Выберите более крупный интервал для этого периода.');
    return {from:new Date(from).toISOString(),to:new Date(to).toISOString(),interval};
}
export function previousRange(range) {
    const from=Date.parse(range.from), to=Date.parse(range.to);
    return {...range,from:new Date(from-(to-from)).toISOString(),to:range.from};
}
export function denseBuckets(stats, range) {
    const from=Date.parse(range.from),to=Date.parse(range.to),width=widths[range.interval];
    if (!width||!Number.isFinite(from)||!Number.isFinite(to)||from>=to||Math.ceil((to-from)/width)>744) throw new Error('Некорректный диапазон графика.');
    const lookup=new Map(stats.buckets.map(b=>[Date.parse(b.timestamp),b]));
    const rows=[];
    for(let t=Math.floor(from/width)*width;t<to;t+=width) {
        const data=lookup.get(t);
        rows.push({timestamp:new Date(t).toISOString(),from:Math.max(t,from),to:Math.min(t+width,to),count:Number(data?.count||0),errors:Number(data?.errors||0)});
    }
    return rows;
}
export function sourceHealth(source, now, thresholdMinutes) {
    if (source.status==='archived') return 'Архивный';
    if (source.status==='disabled') return 'Отключён';
    if (source.status!=='active') return 'Неизвестно';
    const seen=Date.parse(source.last_seen_at);
    if (!Number.isFinite(seen)) return 'Нет поступлений';
    return now-seen<=thresholdMinutes*MINUTE?'Недавно принимал':'Нет недавних поступлений';
}
export function delta(current, previous) {
    if (previous===0) return current===0?'0%':'рост с 0';
    const percent=(current-previous)/previous*100;
    return (percent>0?'+':'')+percent.toLocaleString('ru-RU',{maximumFractionDigits:1})+'%';
}
export function explorerFilters(range, source, extra={}) {
    return {limit:'50',consistency:'live',from:utcInput(Date.parse(range.from)),to:utcInput(Date.parse(range.to)),...(source?{source_id:source}:{}),...extra};
}
