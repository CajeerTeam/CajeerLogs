import assert from 'node:assert/strict';
import {validateFilters,queryFilters,removeFilter} from '../public/admin/assets/app/filters.js';
import {test} from 'node:test';
const anchor='11111111-1111-4111-8111-111111111111';
const range={related_event:anchor,from:'2026-11-11T00:00:00Z',to:'2026-11-17T00:00:00Z'};
test('correlation filters survive client-side normalization and query construction',()=>{
    const f=validateFilters({...range,relation:'either'});
    assert.equal(f.related_event,anchor);
    assert.equal(f.relation,'either');
    assert.equal(queryFilters(f,'22222222-2222-4222-8222-222222222222').project_id,'22222222-2222-4222-8222-222222222222');
    assert.equal(removeFilter(f,'related_event').relation,undefined);
});
test('correlation rejects invalid UUID, range, and mode before network request',()=>{
    assert.throws(()=>validateFilters({...range,related_event:'not-a-uuid'}));
    assert.throws(()=>validateFilters({...range,relation:'sql'}));
    assert.throws(()=>validateFilters({...range,to:'2026-11-20T00:00:00Z'}));
    assert.throws(()=>validateFilters({...range,from:undefined}));
    assert.throws(()=>validateFilters({relation:'trace'}));
});
test('7-day bounded correlation remains compatible with other list filters',()=>{
    const q=validateFilters({...range,levels:'["warning","error"]',q:'timeout'});
    assert.equal(q.levels,'["error","warning"]');
    assert.equal(q.q,'timeout');
    assert.equal(removeFilter(q,'from').related_event,undefined);
});
