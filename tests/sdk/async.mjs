import { CajeerLogsClient, DeliveryError, JobFailedError, JobPendingError } from './client.js'
const c = new CajeerLogsClient(process.argv[2],process.argv[3],{signed:true,attempts:2,timeoutMs:2000})
const event = {timestamp:'2026-11-17T12:00:00Z',source:'app',level:'info',message:'async'}
for (const key of ['ts-async-lost','ts-async-retry','ts-async-replay']) {
  const r=await c.ingestAsync([event,event],key)
  if(r.received!==2 || (await c.waitForJob(r,2000,50)).result.stored!==2) throw Error('Missing async completion')
  if(key==='ts-async-replay') {
    const replay=await c.ingestAsync([event,event],key)
    if(!replay.replayed || replay.job_id!==r.job_id) throw Error('Invalid replay')
  }
}
for(const kind of ['dead','cancelled']) {
  const r=await c.ingestAsync(event,'ts-async-'+kind)
  try {await c.waitForJob(r,250,50);throw Error('False completion')}
  catch(e) {if(!(e instanceof JobFailedError)||e.status!==kind) throw e}
}
const pending=await c.ingestAsync(event,'ts-async-pending')
try {await c.waitForJob(pending,150,50);throw Error('False completion')}
catch(e) {if(!(e instanceof JobPendingError)||e.jobId!==pending.job_id) throw e}
const invalid=await c.ingestAsync([event,event],'ts-async-invalid')
try {await c.waitForJob(invalid,1000,50);throw Error('False completion')}
catch(e) {if(e instanceof JobPendingError || e instanceof JobFailedError || !String(e).includes('Invalid job result')) throw e}
for (const [key,status] of [['ts-async-redirect',307],['ts-async-401',401],['ts-async-badreceipt',202]]) {
  try {await c.ingestAsync(event,key);throw Error('False acceptance')}
  catch(e){if(!(e instanceof DeliveryError)||e.status!==status) throw e}
}
console.log('TypeScript async recovery HTTP tests passed')
