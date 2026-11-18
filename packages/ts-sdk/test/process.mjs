import { CajeerLogsClient, DurableBatchDelivery } from '../dist/index.js'
const [action, url, token, directory, count = '0'] = process.argv.slice(2)
const client = new CajeerLogsClient(url,token,{signed:true,attempts:1,timeoutMs:1000})
const queue = await DurableBatchDelivery.open(client,{directory,batchEvents:10,flushIntervalMs:0})
if (action==='enqueue') for(let i=0;i<Number(count);i++) await queue.enqueue({timestamp:'2026-11-18T00:00:00Z',source:'app',level:'info',message:`worker-${process.pid}-${i}`})
if (action==='flush') console.log(await queue.flush())
await queue.close()
