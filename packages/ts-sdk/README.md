# CajeerLogs TypeScript SDK 0.5.8

Node.js 20+ (рекомендуется 22 LTS), ESM, нативные fetch и Web Crypto, без runtime-зависимостей. Из корня репозитория:

```sh
npm --prefix packages/ts-sdk install
npm --prefix packages/ts-sdk run build
npm --prefix packages/ts-sdk pack
```

Установите полученный tgz в своё серверное приложение. Не встраивайте write token в публичный browser bundle.

```ts
import { CajeerLogsClient } from '@cajeer/logs-sdk'
const client = new CajeerLogsClient(process.env.CAJEER_URL!, process.env.CAJEER_TOKEN!, { signed: true })
const receipt = await client.ingest({ timestamp: new Date().toISOString(), source: 'app', level: 'info', message: 'Started' }, 'job-42')
console.log(receipt.event_ids)
```

Single/batch (до 100), typed receipt, timeout, retry/backoff, HMAC и redirect prevention. `DeliveryError` содержит `status` и `idempotencyKey`. Для повторения сохраняйте тот же сериализуемый payload, порядок ключей и Idempotency-Key. [SDK & Integrations](../../docs/SDK-Integrations.md).

## Async delivery 0.5.8

`await client.ingestAsync(events, key)` возвращает durable `AsyncDeliveryReceipt`; после 202 используйте `await client.waitForJob(receipt, 30000, 500)` до фактического `completed` с уникальными event IDs. `sendAsyncBody(exactJson,key)` повторяет только неизменные байты и ключ при неопределённом результате POST, `jobStatus(id,count)` возобновляет статус. `JobPendingError` и `JobFailedError` нельзя трактовать как успешную запись. Для переживания перезапуска приложения сохраняйте body/key или receipt в собственном защищённом outbox. [Подробности](../../docs/SDK-Delivery-Recovery.md).
