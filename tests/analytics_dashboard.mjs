/** Exact analytics computation and scoped drill-down contract from the shipped ES module. */
import test from 'node:test';
import assert from 'node:assert/strict';
import {rangeFor,previousRange,denseBuckets,rateBuckets,levelBuckets,dimensionComparison,explorerFilters} from '../public/admin/assets/app/analytics.js';

const from='2026-11-18T12:00:00.000Z',to='2026-11-18T12:15:00.000Z';
const range={from,to,interval:'5min'};
const data={
  total:10,by_level:{debug:0,info:3,warning:2,error:5},
  by_source:[{source_id:'source-1',source:'api',count:8},{source_id:'source-2',source:'batch',count:2}],sources_truncated:false,
  by_service:[{service:'http',count:8},{service:'worker',count:2}],
  buckets:[{timestamp:'2026-11-18T12:00:00.000Z',count:3,errors:1},
    {timestamp:'2026-11-18T12:10:00.000Z',count:7,errors:4}],
  level_buckets:[{timestamp:'2026-11-18T12:00:00.000Z',level:'info',count:2},
    {timestamp:'2026-11-18T12:00:00.000Z',level:'error',count:1},
    {timestamp:'2026-11-18T12:10:00.000Z',level:'error',count:4},
    {timestamp:'2026-11-18T12:10:00.000Z',level:'warning',count:2},
    {timestamp:'2026-11-18T12:10:00.000Z',level:'info',count:1}]
};

test('dense UTC buckets include empty intervals without creating fictitious events',()=>{
  const result=denseBuckets(data,range);
  assert.deepEqual(result.map(x=>x.count),[3,0,7]);
  assert.deepEqual(result.map(x=>x.errors),[1,0,4]);
  assert.equal(result[1].from,Date.parse('2026-11-18T12:05:00.000Z'));
});

test('level time series uses real per-level counts and preserves exact half-open bin limits',()=>{
  const error=levelBuckets(data,range,'error');
  assert.deepEqual(error.map(x=>x.count),[1,0,4]);
  assert.equal(error.reduce((sum,x)=>sum+x.count,0),data.by_level.error);
  assert.deepEqual(levelBuckets(data,range,'warning').map(x=>x.count),[0,0,2]);
  assert.throws(()=>levelBuckets(data,range,'invalid'));
  assert.equal(new Date(error[0].to).toISOString(), '2026-11-18T12:05:00.000Z');
});

test('rate series uses error numerator and event denominator including empty bins',()=>{
  const points=rateBuckets(data,range);
  assert.equal(points[0].rate,100/3);
  assert.equal(points[1].rate,0);
  assert.equal(points[2].rate,400/7);
  assert.equal(data.buckets[0].errors+data.buckets[1].errors,5);
});

test('previous comparison uses a preceding equal-duration UTC window',()=>{
  assert.deepEqual(previousRange(range),{from:'2026-11-18T11:45:00.000Z',to:from,interval:'5min'});
  const custom=rangeFor({period:'custom',from:'2026-11-18T12:01',to:'2026-11-18T12:14',interval:'minute'});
  assert.equal(denseBuckets(data,custom).at(0).from,Date.parse('2026-11-18T12:01:00Z'));
  assert.equal(denseBuckets(data,custom).at(-1).to,Date.parse('2026-11-18T12:14:00Z'));
});

test('dimension comparison aligns source by UUID, never display name',()=>{
  const old={by_source:[{source_id:'source-1',source:'renamed-api',count:5},{source_id:'source-3',source:'legacy',count:4}],sources_truncated:false};
  const rows=dimensionComparison(data,old,'by_source');
  assert.equal(rows.find(x=>x.key==='source-1').change,3);
  assert.equal(rows.find(x=>x.key==='source-2').previous,0);
  assert.equal(rows.find(x=>x.key==='source-3').count,0);
});

test('limited top-N breakdown must never represent missing counts as zero',()=>{
  const current={by_source:[{source_id:'source-1',source:'first',count:8}],sources_truncated:true,by_service:Array.from({length:10},(_,i)=>({service:'service-'+i,count:20-i}))};
  const old={by_source:[{source_id:'source-2',source:'second',count:4}],sources_truncated:true,by_service:[{service:'old',count:5}]};
  for(const row of dimensionComparison(current,old,'by_source')) assert.equal(row.change,null);
  assert.equal(dimensionComparison(current,old,'by_service',20).find(x=>x.key==='old').count,null);
  assert.equal(dimensionComparison({by_level:{error:2}},{by_level:{error:0}},'by_level')[0].change,2);
  assert.throws(()=>dimensionComparison(data,old,'arbitrary'));
});

test('drilldown is constrained to selected source and half-open UTC interval',()=>{
  const filters=explorerFilters(range,'source-1',{level:'error'});
  assert.deepEqual(filters,{limit:'50',consistency:'live',from:'2026-11-18T12:00:00.000',to:'2026-11-18T12:15:00.000',source_id:'source-1',level:'error'});
  assert.equal(rateBuckets({buckets:[]},range).every(x=>x.rate===0),true);
});
