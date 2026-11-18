import test from 'node:test'
import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { mkdtemp, readdir, readFile, writeFile, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { spawn } from 'node:child_process'
import { createHmac, createHash } from 'node:crypto'
import { CajeerLogsClient, DurableBatchDelivery, DeliveryError, SpoolFullError, SpoolExpiredError, SpoolCorruptError } from '../dist/index.js'
const token='cl_'+'a'.repeat(64)
const event = message => ({timestamp:'2026-11-18T12:00:00Z',source:'app',level:'info',message})
const records=new Map()
let online=true
const server=createServer(async(req,res)=>{
  try {
    assert.equal(req.url,'/api/v1/ingest')
    assert.equal(req.headers.authorization,`Bearer ${token}`)
    const chunks=[]; for await(const chunk of req) chunks.push(chunk)
    const raw=Buffer.concat(chunks), key=req.headers['idempotency-key'], nonce=req.headers['x-log-nonce']
    const canonical=['cajeerlogs-ingest-v1','POST',req.url,req.headers['x-log-timestamp'],nonce,key,createHash('sha256').update(raw).digest('hex')].join('\n')
    assert.equal(req.headers['x-log-signature'],createHmac('sha256',token).update(canonical).digest('hex'))
    const payload=JSON.parse(raw.toString()); const events=payload.events ?? [payload]
    assert.ok(Array.isArray(events) && events.length>0 && events.length<=100 && raw.length<=1048576)
    let rec=records.get(key)
    if(rec){ assert.ok(raw.equals(rec.raw),'body changed on retry'); assert.ok(!rec.nonces.has(nonce),'nonce reused') }
    else {rec={raw,events,nonces:new Set(),attempts:0,committed:false};records.set(key,rec)}
    rec.nonces.add(nonce);rec.attempts++
    let status
    if(events.some(x=>x.message.startsWith('denied-'))) status=401
    else if(events.some(x=>x.message.startsWith('throttle-')) && rec.attempts===1) status=429
    else if(!online) status=503
    else if(rec.committed) status=200
    else {status=201;rec.committed=true}
    if(status===201 && events.some(x=>x.message.startsWith('lost-'))){req.socket.destroy();return}
    const response=status===200||status===201 ? {accepted:true,received:events.length,stored:status===200?0:events.length,rejected:0,replayed:status===200,event_ids:events.map((_,i)=>'00000000-0000-4000-8000-'+(i+1).toString(16).padStart(12,'0'))} : {}
    const bytes=Buffer.from(JSON.stringify(response))
    res.writeHead(status,{'content-type':'application/json','content-length':bytes.length,'retry-after':'0'}); res.end(bytes)
  }catch(e){ failures.push(String(e));res.writeHead(500);res.end() }
})
const failures=[]
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve))
const url=`http://127.0.0.1:${server.address().port}`
const client=(options={})=>new CajeerLogsClient(url,token,{signed:true,attempts:1,timeoutMs:1000,...options})
const dir=async()=>mkdtemp(join(tmpdir(),'cajeer-ts-stable-'))
const spool=async(directory,options={})=>DurableBatchDelivery.open(client(),{directory,flushIntervalMs:0,batchEvents:3,...options})
const run=(args)=>new Promise((resolve,reject)=>{
  const child=spawn(process.execPath,[new URL('./process.mjs',import.meta.url).pathname,...args],{stdio:['ignore','pipe','pipe']})
  let output='',errors=''
  child.stdout.on('data',v=>output+=v)
  child.stderr.on('data',v=>errors+=v)
  child.on('close',code=>code===0?resolve(output):reject(new Error(`child ${code}: ${errors}`)))
  child.on('error',reject)
})

test('durably enqueue, cap queue, batch, flush with signed HTTP and exact receipts', async()=>{
  const root=await dir()
  try {
    const q=await spool(root,{maxEvents:5})
    for(let i=0;i<5;i++) await q.enqueue(event('normal-'+i))
    assert.equal((await q.status()).events,5)
    await assert.rejects(q.enqueue(event('overflow')),SpoolFullError)
    assert.equal(await q.flush(1),3)
    assert.equal(await q.flush(),2)
    assert.deepEqual((await q.status()).events,0)
    assert.equal([...records.values()].filter(x=>x.events[0].message.startsWith('normal-')).length,2)
    await q.close()
  } finally {await rm(root,{recursive:true,force:true})}
})

test('429 retry preserves bytes/key and uses a new signature nonce',async()=>{
  const c=client({attempts:2})
  const result=await c.ingest(event('throttle-then-commit'),'ts-throttle')
  assert.equal(result.stored,1)
  const rec=records.get('ts-throttle')
  assert.equal(rec.attempts,2)
  assert.equal(rec.nonces.size,2)
})

test('503 keeps durable events and retries after server recovers',async()=>{
  const root=await dir();online=false
  try{
    const q=await spool(root)
    await q.enqueue(event('outage-one'));await q.enqueue(event('outage-two'))
    await assert.rejects(q.flush(),DeliveryError)
    assert.equal((await q.status()).events,2)
    assert.equal((await q.status()).batches,1)
    online=true
    assert.equal(await q.flush(),2)
    assert.equal((await q.status()).events,0)
    await q.close()
  }finally{online=true;await rm(root,{recursive:true,force:true})}
})

test('lost HTTP response: restart new client and reuse exact body/key with fresh HMAC nonce', async()=>{
  const root=await dir()
  try {
    const q=await spool(root);await q.enqueue(event('lost-ack'))
    await assert.rejects(q.flush(),DeliveryError);await q.close()
    const pending=[...records.values()].find(x=>x.events[0].message==='lost-ack')
    assert.equal(pending.attempts,1)
    const recovered=await spool(root)
    assert.equal(await recovered.flush(),1)
    assert.equal(pending.attempts,2)
    assert.equal(pending.nonces.size,2)
    assert.equal((await recovered.status()).events,0)
    await recovered.close()
  }finally{await rm(root,{recursive:true,force:true})}
})

test('expired unknown delivery fails closed, retains exact pending batch', async()=>{
  const root=await dir()
  try {
    const q=await spool(root,{recoveryWindowMs:1000})
    await q.enqueue(event('denied-old'))
    await assert.rejects(q.flush(),DeliveryError)
    const [name]=await readdir(join(root,'batches'))
    const p=join(root,'batches',name), batch=JSON.parse(await readFile(p,'utf8'))
    batch.createdAt=Date.now()-3000
    batch.sha256=createHash('sha256').update(JSON.stringify([batch.key,batch.body,batch.ids,batch.createdAt])).digest('hex')
    await writeFile(p,JSON.stringify(batch))
    await assert.rejects(q.flush(),SpoolExpiredError)
    assert.equal((await q.status()).events,1)
    await q.close()
  }finally{await rm(root,{recursive:true,force:true})}
})

test('credentials bound to queue; malformed pending metadata rejected',async()=>{
  const root=await dir()
  try{
    const q=await spool(root);await q.enqueue(event('denied-security'))
    await assert.rejects(q.flush(),DeliveryError);await q.close()
    const other=new CajeerLogsClient(url,'cl_'+'b'.repeat(64),{signed:true})
    await assert.rejects(DurableBatchDelivery.open(other,{directory:root,flushIntervalMs:0}),SpoolCorruptError)
    const [file]=await readdir(join(root,'batches'))
    await writeFile(join(root,'batches',file),'{corrupt')
    await assert.rejects(spool(root),SpoolCorruptError)
  }finally{await rm(root,{recursive:true,force:true})}
})

test('separate Node processes append concurrently and independently recover all events',async()=>{
  const root=await dir()
  try{
    const jobs=await Promise.all(Array.from({length:3},()=>run(['enqueue',url,token,root,'25'])))
    assert.equal(jobs.length,3)
    const q=await spool(root,{batchEvents:10})
    assert.equal((await q.status()).events,75)
    assert.equal(await q.flush(),75)
    assert.equal((await q.status()).events,0)
    await q.close()
    const expected=[...records.values()].filter(x=>x.events[0].message.startsWith('worker-') && x.committed)
    assert.equal(expected.reduce((acc,rec)=>acc+rec.events.length,0),75)
  }finally{await rm(root,{recursive:true,force:true})}
})

test('large batches split at real 1 MiB HTTP boundary',async()=>{
  const root=await dir()
  try {
    const q=await spool(root,{batchEvents:100,maxEvents:150})
    for(let i=0;i<70;i++) await q.enqueue(event('large-'+i+'-'+ 'x'.repeat(16000)))
    assert.equal(await q.flush(),70)
    const messages=[...records.values()].filter(r=>r.committed && r.events[0].message.startsWith('large-'))
    assert.equal(messages.reduce((a,r)=>a+r.events.length,0),70)
    assert.ok(messages.length>=2)
    assert.ok(messages.every(r=>r.raw.length<=1048576 && r.events.length<=100))
    await q.close()
  }finally {await rm(root,{recursive:true,force:true})}
})

test('tampered event body is rejected before HTTP without deleting the queue',async()=>{
  const root=await dir()
  try {
    const q=await spool(root)
    await q.enqueue(event('integrity-check'))
    const [file]=await readdir(join(root,'events'))
    const path=join(root,'events',file)
    const raw=JSON.parse(await readFile(path,'utf8'))
    raw.body=raw.body.replace('integrity-check','forged-message')
    await writeFile(path,JSON.stringify(raw))
    await assert.rejects(q.flush(),SpoolCorruptError)
    assert.equal((await q.status()).events,1)
    await q.close()
  } finally { await rm(root,{recursive:true,force:true}) }
})

test('background timer flushes a durable event without manual HTTP calls',async()=>{
  const root=await dir()
  try {
    const q=await DurableBatchDelivery.open(client(),{directory:root,flushIntervalMs:100,batchEvents:50})
    await q.enqueue(event('timer-batch'))
    let complete=false
    for(let i=0;i<35;i++) {
      if((await q.status()).events===0) {complete=true;break}
      await new Promise(resolve=>setTimeout(resolve,50))
    }
    assert.ok(complete,'background delivery did not finish')
    await q.close()
  } finally { await rm(root,{recursive:true,force:true}) }
})

test('client AbortSignal stops retries and keeps caller control',async()=>{
  const abort=new AbortController();abort.abort(new Error('stopped'))
  await assert.rejects(client({attempts:3}).ingest(event('abort'), 'ts-abort', abort.signal),/stopped/)
})

test('clean server assertions and shut down test HTTP server',()=>{
  server.closeAllConnections();server.close()
  assert.deepEqual(failures,[])
})
