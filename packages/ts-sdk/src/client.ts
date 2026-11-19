import type { LogEvent, IngestReceipt, AsyncDeliveryReceipt, JobStatus } from './types.js'

export interface ClientOptions {
  timeoutMs?: number
  attempts?: number
  signed?: boolean
  signingKeyId?: string
  signingSecret?: string
  apiPrefix?: string
  allowInsecureHttp?: boolean
}
export class DeliveryError extends Error {
  constructor(public readonly status: number | null, public readonly idempotencyKey: string,
              public readonly apiError: string | null = null, public readonly requestId: string | null = null) {
    super(`CajeerLogs delivery failed (HTTP ${status ?? 'unknown'}); retry with the same body and key`)
  }
}
const hex = (bytes: ArrayBuffer | Uint8Array) => Array.from(new Uint8Array(bytes), b => b.toString(16).padStart(2, '0')).join('')
const random = () => hex(crypto.getRandomValues(new Uint8Array(16)))
export class JobPendingError extends Error {
  constructor(public readonly jobId: string) { super(`Async job ${jobId} is not confirmed complete`) }
}
export class JobFailedError extends Error {
  constructor(public readonly jobId: string, public readonly status: string) { super(`Async job ${jobId} reached ${status}`) }
}
export class JobStatusError extends Error {
  constructor(public readonly status: number) { super(`Job status request failed (HTTP ${status})`) }
}
const jobIdValid = (value: unknown): value is string => typeof value === 'string' && /^[1-9][0-9]{0,18}$/.test(value) && BigInt(value) <= 9223372036854775807n
const encoder = new TextEncoder()
const delay = (ms: number, signal?: AbortSignal) => new Promise<void>((resolve, reject) => {
  if (signal?.aborted) { reject(signal.reason); return }
  const timer = setTimeout(done, ms)
  function done() { signal?.removeEventListener('abort', aborted); resolve() }
  function aborted() { clearTimeout(timer); signal?.removeEventListener('abort', aborted); reject(signal?.reason) }
  signal?.addEventListener('abort', aborted, { once: true })
})

const fields = new Set(['timestamp','source','level','message','context','service','host','trace_id','request_id','tags'])
const levels = new Set(['debug','info','notice','warning','error','critical','alert','emergency'])
const object = (v: unknown): v is Record<string,unknown> => v !== null && typeof v === 'object' && !Array.isArray(v) && (Object.getPrototypeOf(v) === Object.prototype || Object.getPrototypeOf(v) === null)
const text = (v: unknown, max: number): v is string => typeof v === 'string' && v.replace(/^[ \t\r\n\x00\x0b]+|[ \t\r\n\x00\x0b]+$/g,'') !== '' && encoder.encode(v).length <= max
const validTimestamp = (v: unknown): boolean => {
  if (typeof v !== 'string') return false
  const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?(Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/.exec(v)
  if (!m || m[0] !== v) return false
  const [year,month,day,hour,minute,second] = m.slice(1,7).map(Number)
  const leap = year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0)
  const days = [31,leap?29:28,31,30,31,30,31,31,30,31,30,31]
  if (year<1 || month<1 || month>12 || day<1 || day>days[month-1] || hour>23 || minute>59 || second>59) return false
  const date = new Date(v)
  return Number.isFinite(date.getTime()) && date.getUTCFullYear() >= 1 && date.getUTCFullYear() <= 9999
}
export function validateLogEvent(e: unknown): void {
  if (!object(e) || Object.keys(e).some(k=>!fields.has(k))) throw new Error('Invalid event fields')
  if (!text(e.source,100) || !/^[a-z0-9]+(?:-[a-z0-9]+)*$(?![\s\S])/.test(e.source) || !text(e.level,32) || !levels.has(e.level.toLowerCase()) || !text(e.message,16384) || !validTimestamp(e.timestamp)) throw new Error('Invalid required event field')
  for (const [key,max] of [['service',255],['host',255],['trace_id',128],['request_id',128]] as const) if (e[key] != null && !text(e[key],max)) throw new Error('Invalid optional event field')
  if (e.context!==undefined && !object(e.context)) throw new Error('Invalid context')
  if (e.tags!==undefined && (!Array.isArray(e.tags) || e.tags.length>32 || !e.tags.every(t=>text(t,128)))) throw new Error('Invalid tags')
  let nodes=0
  const walk=(v: unknown, depth: number): void=>{
    if (depth>24 || ++nodes>8192) throw new Error('Event structure exceeds limits')
    if (typeof v==='string') {
      if (/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/.test(v) || /[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(?<![\uD800-\uDBFF])[\uDC00-\uDFFF]/.test(v)) throw new Error('Invalid string')
    } else if (Array.isArray(v)) {
      if (v.length>2048) throw new Error('Event structure exceeds limits')
      for (const child of v) walk(child,depth+1)
    } else if (object(v)) {
      const keys=Object.keys(v)
      if (keys.length>2048 || Object.getOwnPropertySymbols(v).length || 'toJSON' in v) throw new Error('Invalid JSON object')
      for (const key of keys) {
        if (encoder.encode(key).length>256 || /[\x00-\x1f\x7f]/.test(key)) throw new Error('Invalid key')
        if (/[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(?<![\uD800-\uDBFF])[\uDC00-\uDFFF]/.test(key)) throw new Error('Invalid key')
        if (depth===0 && v[key]===undefined && ['context','tags','service','host','trace_id','request_id'].includes(key)) continue
        walk(v[key],depth+1)
      }
    } else if (typeof v==='number') {
      if (!Number.isFinite(v)) throw new Error('Invalid JSON number')
    } else if (v!==null && typeof v!=='boolean') throw new Error('Unsupported JSON value')
  }
  walk(e,0)
  if (encoder.encode(JSON.stringify(e)).length>65536 || encoder.encode(JSON.stringify(e.context??{})).length>32768) throw new Error('Event or context exceeds size limit')
}

export class CajeerLogsClient {
  private readonly url: URL
  private readonly attempts: number
  private readonly timeout: number
  private readonly signed: boolean
  private readonly signingKeyId?: string
  private readonly signingSecret?: string
  constructor(baseUrl: string, private readonly token: string, options: ClientOptions = {}) {
    const base = new URL(baseUrl)
    const prefix = options.apiPrefix ?? '/api/v1'
    if (!['http:', 'https:'].includes(base.protocol) || base.username || base.password || base.search || base.hash || base.pathname !== '/' || !/^\/[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/.test(prefix) || !/^[A-Za-z0-9._~-]{1,512}$/.test(token)) throw new Error('Invalid client configuration')
    if (base.protocol === 'http:' && !options.allowInsecureHttp && !['localhost', '127.0.0.1', '[::1]'].includes(base.hostname)) throw new Error('HTTPS required outside loopback')
    this.url = new URL(prefix + '/ingest', base)
    this.attempts = options.attempts ?? 3
    this.timeout = options.timeoutMs ?? 10000
    this.signingKeyId = options.signingKeyId
    this.signingSecret = options.signingSecret
    if ((this.signingKeyId === undefined) !== (this.signingSecret === undefined) || (this.signingKeyId !== undefined && (!/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/.test(this.signingKeyId) || !/^sk_[a-f0-9]{64}$/.test(this.signingSecret!)))) throw new Error('Invalid separate signing configuration')
    this.signed = (options.signed ?? false) || this.signingKeyId !== undefined
    if (!Number.isInteger(this.attempts) || this.attempts < 1 || this.attempts > 10 || !Number.isFinite(this.timeout) || this.timeout <= 0 || this.timeout > 120000) throw new Error('Invalid delivery limits')
  }
  /** Fingerprint binds a disk outbox to one API endpoint, credential and signing configuration. */
  async spoolIdentity(): Promise<string> {
    const material = JSON.stringify([this.url.href, this.token, this.signed, this.signingKeyId, this.signingSecret])
    return hex(await crypto.subtle.digest('SHA-256', encoder.encode(material)))
  }
  async ingest(event: LogEvent | LogEvent[] | { events: LogEvent[] }, idempotencyKey = random(), signal?: AbortSignal): Promise<IngestReceipt> {
    const payload = Array.isArray(event) ? { events: event } : event
    if (!object(payload)) throw new Error('Invalid event or batch')
    const events = 'events' in payload ? payload.events : [payload]
    if (!Array.isArray(events) || events.length<1 || events.length>100 || ('events' in payload && Object.keys(payload).length!==1)) throw new Error('Invalid batch')
    for (const item of events) validateLogEvent(item)
    return this.sendBody(JSON.stringify(payload), idempotencyKey, signal)
  }
  /** Enqueue immutable events; HTTP 202 is durable admission, not completed storage. */
  async ingestAsync(event: LogEvent | LogEvent[] | {events: LogEvent[]}, idempotencyKey = random(), signal?: AbortSignal): Promise<AsyncDeliveryReceipt> {
    const payload = Array.isArray(event) ? { events: event } : event
    if (!object(payload)) throw new Error('Invalid event or batch')
    const events = 'events' in payload ? payload.events : [payload]
    if (!Array.isArray(events) || events.length < 1 || events.length > 100 || ('events' in payload && Object.keys(payload).length !== 1)) throw new Error('Invalid batch')
    for (const item of events) validateLogEvent(item)
    return this.sendAsyncBody(JSON.stringify(payload), idempotencyKey, signal)
  }
  /** Resume synchronous delivery with the exact persisted JSON text and key. */
  async sendBody(body: string, idempotencyKey: string, signal?: AbortSignal): Promise<IngestReceipt> {
    return this.postBody(body, idempotencyKey, false, signal) as Promise<IngestReceipt>
  }
  /** Persist body and key BEFORE this call for recovery across process restarts. */
  async sendAsyncBody(body: string, idempotencyKey: string, signal?: AbortSignal): Promise<AsyncDeliveryReceipt> {
    return this.postBody(body, idempotencyKey, true, signal) as Promise<AsyncDeliveryReceipt>
  }
  private async postBody(body: string, idempotencyKey: string, asyncMode: boolean, signal?: AbortSignal): Promise<IngestReceipt | AsyncDeliveryReceipt> {
    if (encoder.encode(body).length > 1048576) throw new Error('Invalid body or idempotency key')
    const payload = JSON.parse(body)
    if (!object(payload) || ('events' in payload && Object.keys(payload).length !== 1)) throw new Error('Invalid serialized event batch')
    const events = 'events' in payload ? payload.events : [payload]
    const count = Array.isArray(events) ? events.length : 0
    if (!/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/.test(idempotencyKey) || count < 1 || count > 100) throw new Error('Invalid body or idempotency key')
    const url = asyncMode ? new URL(this.url.pathname + '/async', this.url) : this.url
    if (signal?.aborted) throw signal.reason
    for (let attempt = 0; attempt < this.attempts; attempt++) {
      const headers: Record<string, string> = { 'Content-Type': 'application/json', Authorization: `Bearer ${this.token}`, 'Idempotency-Key': idempotencyKey }
      if (this.signed) {
        const timestamp = String(Math.floor(Date.now() / 1000)), nonce = random()
        const digest = hex(await crypto.subtle.digest('SHA-256', encoder.encode(body)))
        const prefix = this.signingKeyId === undefined ? ['cajeerlogs-ingest-v1'] : ['cajeerlogs-ingest-v2', this.signingKeyId]
        if (this.signingKeyId !== undefined) headers['X-Log-Key-Id'] = this.signingKeyId
        const canonical = [...prefix, 'POST', url.pathname, timestamp, nonce, idempotencyKey, digest].join('\n')
        const key = await crypto.subtle.importKey('raw', encoder.encode(this.signingSecret ?? this.token), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign'])
        Object.assign(headers, { 'X-Log-Timestamp': timestamp, 'X-Log-Nonce': nonce, 'X-Log-Signature': hex(await crypto.subtle.sign('HMAC', key, encoder.encode(canonical))) })
      }
      let status: number | null = null, wait = 250 * 2 ** attempt
      let apiError: string | null = null, requestId: string | null = null
      const controller = new AbortController()
      const abort = () => controller.abort(signal?.reason)
      signal?.addEventListener('abort', abort, { once: true })
      const timer = setTimeout(() => controller.abort(new Error('Request timeout')), this.timeout)
      try {
        if (signal?.aborted) throw signal.reason
        const response = await fetch(url, { method: 'POST', headers, body, signal: controller.signal, redirect: 'manual', credentials: 'omit' })
        status = response.status
        const retry = response.headers.get('Retry-After') ?? ''
        if (/^\d+$/.test(retry)) wait = Math.max(wait, Number(retry) * 1000)
        if ([200, asyncMode ? 202 : 201].includes(status)) {
          const r = await this.readBoundedJson(response) as Record<string, unknown>
          if (asyncMode) {
            if (r && r.accepted === true && r.received === count && r.replayed === (status === 200) && jobIdValid(r.job_id) && ['pending','running','completed','dead','cancelled'].includes(String(r.status))) return r as unknown as AsyncDeliveryReceipt
          } else {
            if (r && r.accepted === true && r.received === count && r.stored === (status === 200 ? 0 : count) && r.rejected === 0 && r.replayed === (status === 200) && Array.isArray(r.event_ids) && r.event_ids.length === count && r.event_ids.every(id => typeof id === 'string' && /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/.test(id)) && new Set(r.event_ids).size === count) return r as unknown as IngestReceipt
          }
        } else if (status >= 400) {
          try {
            const value = await this.readBoundedJson(response)
            if (object(value) && typeof value.error === 'string' && /^[a-z][a-z0-9_]{0,127}$/.test(value.error)
                && typeof value.request_id === 'string' && /^[a-f0-9]{32}$/.test(value.request_id)) {
              apiError = value.error; requestId = value.request_id
            }
          } catch { /* Untrusted/oversized error body; status remains authoritative. */ }
        } else { await response.body?.cancel() }
      } catch { /* network error or invalid receipt: retry SAME body, key, new HMAC nonce */ } finally { clearTimeout(timer); signal?.removeEventListener('abort', abort) }
      if (signal?.aborted) throw signal.reason
      if (attempt + 1 === this.attempts || wait > 30000 || (status !== null && ![200,201,202,408,429,500,502,503,504].includes(status))) throw new DeliveryError(status, idempotencyKey, apiError, requestId)
      await delay(wait + Math.floor(Math.random() * 100), signal)
    }
    throw new Error('Unreachable')
  }
  private async readBoundedJson(response: Response): Promise<unknown> {
    const reader = response.body?.getReader()
    if (!reader) throw new Error('Missing response body')
    const chunks: Uint8Array[] = []; let length = 0
    try { for (;;) { const {done, value} = await reader.read(); if (done) break; length += value.length; if (length > 65536) throw new Error('Response too large'); chunks.push(value) } }
    finally { await reader.cancel().catch(() => {}); reader.releaseLock() }
    const bytes = new Uint8Array(length); let offset = 0
    for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.length }
    return JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(bytes))
  }
  /** A GET does not enqueue, and authorization is checked against the live token family. */
  async jobStatus(jobId: string, expectedCount?: number, signal?: AbortSignal): Promise<JobStatus> {
    if (!jobIdValid(jobId)) throw new Error('Invalid job ID')
    const controller = new AbortController()
    const abort = () => controller.abort(signal?.reason)
    signal?.addEventListener('abort', abort, { once: true })
    const timer = setTimeout(() => controller.abort(new Error('Request timeout')), this.timeout)
    try {
      if (signal?.aborted) throw signal.reason
      const url = new URL(this.url.pathname + '/jobs/' + jobId, this.url)
      const response = await fetch(url, { method: 'GET', headers: { Authorization: `Bearer ${this.token}` }, signal: controller.signal, redirect: 'manual', credentials: 'omit' })
      if (response.status !== 200) { await response.body?.cancel(); throw new JobStatusError(response.status) }
      const r = await this.readBoundedJson(response) as Record<string, unknown>
      if (!r || r.id !== jobId || !['pending','running','completed','dead','cancelled'].includes(String(r.status)) || !Number.isInteger(r.attempts) || !Number.isInteger(r.max_attempts) || (r.attempts as number) < 0 || (r.max_attempts as number) < 1 || (r.attempts as number) > (r.max_attempts as number)) throw new Error('Invalid job status')
      if (r.status === 'completed') {
        const result = r.result as Record<string, unknown> | null
        if (!result || !Array.isArray(result.event_ids) || result.event_ids.length < 1 || result.event_ids.length > 100 || result.stored !== result.event_ids.length || (expectedCount !== undefined && result.stored !== expectedCount) || result.event_ids.some(id => typeof id !== 'string' || !/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/.test(id)) || new Set(result.event_ids).size !== result.event_ids.length) throw new Error('Invalid job result')
      } else if (r.result !== null) throw new Error('Unexpected unfinished job result')
      return r as unknown as JobStatus
    } finally { clearTimeout(timer); signal?.removeEventListener('abort', abort) }
  }
  async waitForJob(receipt: AsyncDeliveryReceipt, timeoutMs = 30000, pollMs = 500, signal?: AbortSignal): Promise<JobStatus> {
    if (!jobIdValid(receipt.job_id) || !Number.isInteger(receipt.received) || receipt.received < 1 || receipt.received > 100 || !Number.isFinite(timeoutMs) || timeoutMs <= 0 || timeoutMs > 3600000 || !Number.isFinite(pollMs) || pollMs < 50 || pollMs > 30000) throw new Error('Invalid async receipt or polling limits')
    const deadline = performance.now() + timeoutMs
    for (;;) {
      try {
        const state = await this.jobStatus(receipt.job_id, receipt.received, signal)
        if (state.status === 'completed') return state
        if (state.status === 'dead' || state.status === 'cancelled') throw new JobFailedError(receipt.job_id, state.status)
      } catch (err) {
        if (!(err instanceof JobStatusError && [408,429,500,502,503,504].includes(err.status)) && !(err instanceof TypeError)) throw err
      }
      const remaining = deadline - performance.now()
      if (remaining <= 0) throw new JobPendingError(receipt.job_id)
      await delay(Math.min(pollMs, remaining), signal)
    }
  }
}
