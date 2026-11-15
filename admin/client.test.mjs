import test from 'node:test';
import assert from 'node:assert/strict';
import { createApiClient, ApiError } from '../public/admin/assets/api/client.js?v=0.3.6';
import { state, advanceSession, resetWorkspace } from '../public/admin/assets/app/store.js?v=0.3.6';
const json = (data, status=200, headers={}) => new Response(JSON.stringify(data),{status,headers:{'Content-Type':'application/json',...headers}});
function fixture(fetcher) { let invalidated=0; const session={csrf:'csrf-value',authEpoch:0,sessionController:new AbortController()}; const client=createApiClient({origin:'https://logs.test',prefix:'/api/v1',fetcher,session,onUnauthorized:()=>invalidated++}); return {client,session,invalidated:()=>invalidated}; }
test('same-origin URL, query, credentials, CSRF, JSON, If-Match',async()=>{ const {client}=fixture(async (url,opts)=>{assert.equal(url.href,'https://logs.test/api/v1/logs?q=a%26b&limit=0');assert.equal(opts.credentials,'same-origin');assert.equal(opts.headers['X-CSRF-Token'],'csrf-value');assert.equal(opts.headers['If-Match'],'"hash"');assert.equal(opts.body,'{"x":1}');assert.equal(opts.redirect,'error');return json({ok:true});});assert.deepEqual(await client.request('/logs',{method:'POST',query:{q:'a&b',limit:0,skip:''},body:{x:1},ifMatch:'"hash"'}),{ok:true});await assert.rejects(client.get('/../secret'),/Недопустимый/);await assert.rejects(client.get('//evil.test'),/Недопустимый/); });
test('login and bearer 401 do not invalidate cookie session; regular 401 does',async()=>{const {client,invalidated}=fixture(async (url,o)=>{if(url.pathname.endsWith('/token')){assert.equal(o.credentials,'omit');assert.equal(o.headers.Authorization,'Bearer token');assert.equal(o.headers['X-CSRF-Token'],undefined);}return json({error:'unauthorized'},401);});for(const [path,opts] of [['/auth/login',{method:'POST',login:true}],['/auth/token',{bearer:'token'}]]) await assert.rejects(client.request(path,opts),{status:401});assert.equal(invalidated(),0);await assert.rejects(client.get('/auth/me'),{status:401});assert.equal(invalidated(),1);});
test('structured errors, raw response, 204 and readiness',async()=>{const {client}=fixture(async url=>{if(url.pathname==='/ready')return json({status:'down'},503);if(url.pathname.endsWith('/export'))return new Response('csv',{headers:{'X-Page-Digest':'digest'}});if(url.pathname.endsWith('/empty'))return new Response(null,{status:204});return json({},429,{'X-Request-ID':'req-1','Retry-After':'4'});});await assert.rejects(client.get('/logs'),e=>e instanceof ApiError&&e.status===429&&e.requestId==='req-1'&&e.retryAfter==='4');assert.equal((await client.request('/export',{raw:true})).headers.get('X-Page-Digest'),'digest');assert.equal(await client.get('/empty'),null);assert.equal((await client.ready()).status,503);});
test('stale session and route responses cannot update state or logout',async()=>{let resolve;const {client,session,invalidated}=fixture(()=>new Promise(r=>resolve=r));const old=client.get('/logs');session.authEpoch++;session.sessionController.abort();session.sessionController=new AbortController();resolve(json({},401));await assert.rejects(old,{name:'AbortError'});assert.equal(invalidated(),0);const controller=new AbortController();const request=client.get('/logs',{},controller.signal);controller.abort();resolve(json({items:[]}));await assert.rejects(request,{name:'AbortError'});});
test('invalid JSON and network failure are actionable; no automatic write retry',async()=>{let count=0;const {client}=fixture(async()=>{count++;if(count===1)return new Response('html');throw new TypeError('network');});await assert.rejects(client.get('/logs'),/некорректный JSON/);await assert.rejects(client.write('/users','POST',{}),/соединение/);assert.equal(count,2);});
test('session advance aborts old requests and workspace reset clears private state',()=>{const old=state.sessionController;state.filters={q:'private'};state.project='project';advanceSession();resetWorkspace();assert.equal(old.signal.aborted,true);assert.equal(state.sessionController.signal.aborted,false);assert.equal(state.filters,null);assert.equal(state.project,null);});

import { qrMatrix } from '../public/admin/assets/components/qr-encoder.js?v=0.3.6';
test('local TOTP QR encodes URI without browser network or external services', () => {
    const uri='otpauth://totp/CajeerLogs%3Atest%40example.com?secret=JBSWY3DPEHPK3PXP&issuer=CajeerLogs&algorithm=SHA1&digits=6&period=30';
    const matrix=qrMatrix(uri);
    assert.ok(matrix.length>=21 && matrix.length<=177 && matrix.every(row=>row.length===matrix.length && row.every(v=>typeof v==='boolean')));
    assert.deepEqual(matrix,qrMatrix(uri));
    assert.notDeepEqual(matrix,qrMatrix(uri.replace('JBSWY3DPEHPK3PXP','AAAAAAAAAAAAAAAA')));
    assert.throws(()=>qrMatrix('https://example.com'),/Invalid TOTP URI/);
});

import { rangeFor, previousRange, denseBuckets, sourceHealth, explorerFilters, delta, parseUtc } from '../public/admin/assets/app/analytics.js?v=0.3.6';
test('UTC analytics periods and bounded granularity',()=>{
 const now=Date.parse('2026-01-02T00:03:20.123Z');
 const r=rangeFor({period:'15m',interval:'auto'},now);assert.equal(r.interval,'minute');assert.equal(r.from,'2026-01-01T23:48:20.123Z');assert.equal(r.to,'2026-01-02T00:03:20.123Z');
 assert.equal(previousRange(r).to,r.from);assert.equal(previousRange(r).from,'2026-01-01T23:33:20.123Z');
 assert.equal(rangeFor({period:'30d',interval:'auto'},now).interval,'day');assert.throws(()=>rangeFor({period:'30d',interval:'minute'},now));
 assert.throws(()=>parseUtc('2026-02-30T10:00'));assert.throws(()=>rangeFor({period:'custom',from:'2026-01-01T00:00',to:'2026-02-02T00:00',interval:'auto'}));
});
test('dense UTC buckets, partial edge drill-down, and no fabricated counts',()=>{
 const r={from:'2026-01-01T23:58:30Z',to:'2026-01-02T00:01:05Z',interval:'minute'};
 const rows=denseBuckets({buckets:[{timestamp:'2026-01-02T00:00:00.000000Z',count:3,errors:2}]},r);
 assert.equal(rows.length,4);assert.deepEqual(rows.map(b=>b.count),[0,0,3,0]);assert.equal(rows[2].errors,2);assert.equal(rows[0].from,Date.parse(r.from));assert.equal(rows[3].to,Date.parse(r.to));
 const filters=explorerFilters(r,'source',{level:'error'});assert.equal(filters.from,'2026-01-01T23:58:30.000');assert.equal(filters.source_id,'source');assert.equal(filters.level,'error');assert.equal(filters.consistency,'live');
});
test('source health differentiates status and admission recency; delta handles zero baseline',()=>{
 const now=Date.parse('2026-01-02T00:00:00Z');
 assert.equal(sourceHealth({status:'disabled',last_seen_at:'2026-01-01T23:59:00Z'},now,60),'Отключён');assert.equal(sourceHealth({status:'active',last_seen_at:null},now,60),'Нет поступлений');
 assert.equal(sourceHealth({status:'active',last_seen_at:'2026-01-01T22:00:00Z'},now,60),'Нет недавних поступлений');assert.equal(sourceHealth({status:'active',last_seen_at:'2026-01-01T23:30:00Z'},now,60),'Недавно принимал');assert.equal(delta(1,0),'рост с 0');assert.equal(delta(0,0),'0%');
 state.dashboard={project:'private'};resetWorkspace();assert.equal(state.dashboard,null);
});

import { defaultLayout, normalizeLayout, moveColumn, visibleColumns, timestampLabel, highlightParts, pageChanged } from '../public/admin/assets/app/explorer.js?v=0.3.6';
test('Explorer layout normalizes invalid preferences, enforces visibility and bounded widths',()=>{
    const l=normalizeLayout({order:['host','host','unknown'],visible:['host','bad'],widths:{host:900,message:10,id:'12'},density:'bad',zone:'bad'});
    assert.equal(l.order[0],'host'); assert.equal(l.order.length,12); assert.deepEqual(l.visible,['host']);assert.deepEqual(l.widths,{host:800,message:90});assert.equal(l.density,'normal');assert.equal(l.zone,'utc');
    assert.equal(visibleColumns(l)[0].width,800);assert.ok(normalizeLayout({visible:[]}).visible.length);assert.equal(moveColumn(defaultLayout(),'level',-1).order[0],'level');
});
test('Explorer literal highlighting never interprets HTML or regex and preserves UTC microseconds',()=>{
    const s='<img src=x> [a] [A]'; const p=highlightParts(s,'[a]');assert.equal(p.map(x=>x.text).join(''),s);assert.deepEqual(p.filter(x=>x.match).map(x=>x.text),['[a]','[A]']);assert.equal(timestampLabel('2026-11-15T01:02:03.123456Z'),'2026-11-15 01:02:03.123456 UTC');assert.equal(pageChanged('a',{page_digest:'b'}),true);assert.equal(pageChanged(null,{page_digest:'b'}),false);
});

import { validateFilters, queryFilters, preset, removeFilter } from '../public/admin/assets/app/filters.js?v=0.3.6';
test('advanced filter validation, canonical lists and lossless JSON scalar',()=>{
 const raw='12345678901234567890.123456789';
 const f=validateFilters({levels:'["warning","error","error"]',tags_all:'["prod","db"]',context_path:'["list","0","list"]',context_equals:raw,from:'2026-01-01T00:00:00.123456Z',to:'2026-01-02T00:00:00Z'});
 assert.equal(f.levels,'["error","warning"]');assert.equal(f.context_path,'["list","0","list"]');assert.equal(f.context_equals,raw);assert.equal(queryFilters(f,'project').from,'2026-01-01T00:00:00.123456Z');
 assert.throws(()=>validateFilters({levels:'["fake"]'}));assert.throws(()=>validateFilters({context_path:'["a"]'}));assert.throws(()=>validateFilters({q_mode:'phrase'}));assert.throws(()=>validateFilters({context_equals:'[]'}));
 assert.equal(removeFilter(f,'from').context_path,undefined);assert.equal(removeFilter(f,'from').context_equals,undefined);assert.equal(preset('hour',new Date('2026-01-01T01:00:00Z')).from,'2026-01-01T00:00:00.000Z');
});

import { jsonDocument, pointer, searchJson, prettyJson } from '../public/admin/assets/app/event-json.js?v=0.3.6';
test('event JSON source spans preserve numbers, escaped keys, arrays and JSON Pointer',()=>{
 const raw='{"data":{"context":{"a/b~c":[12345678901234567890,1.234567890123456789,null,true,"<script>\\"x\\"</script>"],"__proto__":{"safe":false}}}}';
 const d=jsonDocument(raw),event=d.member(d.root,'data'),context=d.member(event,'context'),list=d.member(context,'a/b~c');
 assert.deepEqual([...d.children(list)].map(x=>d.raw(x.node)),['12345678901234567890','1.234567890123456789','null','true','"<script>\\"x\\"</script>"']);
 assert.equal(pointer(['a/b~c','0']),'/a~1b~0c/0'); assert.equal(d.raw(d.member(context,'__proto__')),'{"safe":false}');
 assert.ok(prettyJson(d,event).includes('12345678901234567890'));assert.equal(prettyJson(d,event,10),null);
 assert.throws(()=>jsonDocument('{"x":}'));
});
test('event JSON search matches decoded keys and scalar values and stops at budget',()=>{
 const d=jsonDocument('{"nested":[{"name":"UPPER"},false,12345678901234567890],"escaped":"a\\nb"}');
 assert.deepEqual([...searchJson(d,d.root,'upper')].map(x=>pointer(x.path)),['/nested/0/name']);
 assert.deepEqual([...searchJson(d,d.root,'12345678901234567890')].map(x=>pointer(x.path)),['/nested/2']);
 const it=searchJson(d,d.root,'missing',{limit:2});assert.deepEqual(it.next(),{value:true,done:true});
});
