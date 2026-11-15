import { CajeerLogsClient, DeliveryError } from './client.js'
const c = new CajeerLogsClient(process.argv[2], process.argv[3], { signed: true, attempts: 2, timeoutMs: 2000 })
const event = { timestamp: '2026-01-01T00:00:00Z', source:'app', level:'info', message:'Привет', context:{} }
const result = await c.ingest([event,event], 'ts-retry')
if(result.stored !== 2) throw new Error('Bad receipt')
if(!(await c.ingest(event,'ts-replay')).replayed) throw new Error('Bad replay')
for (const [key,status] of [['ts-denied',401],['ts-redirect',307],['ts-malformed',201]]) {
  try { await c.ingest(event,key); throw new Error('Expected failure') }
  catch(e) { if(!(e instanceof DeliveryError) || e.status !== status) throw e }
}
for (const bad of [{timestamp:'2026-02-30T00:00:00Z'},{message:123},{context:null},{tags:[null]},{source:'APP'},{message:'bad\x1b'},{context:{n:NaN}},{message:'\uD800'}]) {
  let rejected=false
  try { await c.ingest({...event,...bad},'ts-invalid') } catch(e) { if(e instanceof DeliveryError) throw e; rejected=true }
  if(!rejected) throw new Error('Invalid event accepted')
}
const v2 = new CajeerLogsClient(process.argv[2], process.argv[3], { signingKeyId:'33333333-3333-4333-8333-333333333333', signingSecret:'sk_'+'b'.repeat(64) })
if ((await v2.ingest(event,'ts-v2')).stored !== 1) throw new Error('v2 failed')
console.log('TypeScript SDK wire tests passed')
