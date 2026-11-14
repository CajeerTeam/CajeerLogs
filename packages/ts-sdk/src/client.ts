import type { LogEvent, IngestReceipt } from './types.js'

export interface ClientOptions {
  timeoutMs?: number
  attempts?: number
  signed?: boolean
  apiPrefix?: string
  allowInsecureHttp?: boolean
}
export class DeliveryError extends Error {
  constructor(public readonly status: number | null, public readonly idempotencyKey: string) {
    super(`CajeerLogs delivery failed (HTTP ${status ?? 'unknown'}); retry with the same body and key`)
  }
}
const hex = (bytes: ArrayBuffer | Uint8Array) => Array.from(new Uint8Array(bytes), b => b.toString(16).padStart(2, '0')).join('')
const random = () => hex(crypto.getRandomValues(new Uint8Array(16)))
const encoder = new TextEncoder()
const delay = (ms: number) => new Promise<void>(resolve => setTimeout(resolve, ms))

export class CajeerLogsClient {
  private readonly url: URL
  private readonly attempts: number
  private readonly timeout: number
  private readonly signed: boolean
  constructor(baseUrl: string, private readonly token: string, options: ClientOptions = {}) {
    const base = new URL(baseUrl)
    const prefix = options.apiPrefix ?? '/api/v1'
    if (!['http:', 'https:'].includes(base.protocol) || base.username || base.password || base.search || base.hash || base.pathname !== '/' || !/^\/[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/.test(prefix) || !/^[A-Za-z0-9._~-]{1,512}$/.test(token)) throw new Error('Invalid client configuration')
    if (base.protocol === 'http:' && !options.allowInsecureHttp && !['localhost', '127.0.0.1', '[::1]'].includes(base.hostname)) throw new Error('HTTPS required outside loopback')
    this.url = new URL(prefix + '/ingest', base)
    this.attempts = options.attempts ?? 3
    this.timeout = options.timeoutMs ?? 10000
    this.signed = options.signed ?? false
    if (!Number.isInteger(this.attempts) || this.attempts < 1 || this.attempts > 10 || !Number.isFinite(this.timeout) || this.timeout <= 0 || this.timeout > 120000) throw new Error('Invalid delivery limits')
  }
  async ingest(event: LogEvent | LogEvent[] | { events: LogEvent[] }, idempotencyKey = random()): Promise<IngestReceipt> {
    const payload = Array.isArray(event) ? { events: event } : event
    const count = 'events' in payload ? payload.events.length : 1
    const body = JSON.stringify(payload)
    if (!/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/.test(idempotencyKey) || count < 1 || count > 100 || encoder.encode(body).length > 1048576) throw new Error('Invalid body or idempotency key')
    for (let attempt = 0; attempt < this.attempts; attempt++) {
      const headers: Record<string, string> = { 'Content-Type': 'application/json', Authorization: `Bearer ${this.token}`, 'Idempotency-Key': idempotencyKey }
      if (this.signed) {
        const timestamp = String(Math.floor(Date.now() / 1000)), nonce = random()
        const digest = hex(await crypto.subtle.digest('SHA-256', encoder.encode(body)))
        const canonical = ['cajeerlogs-ingest-v1', 'POST', this.url.pathname, timestamp, nonce, idempotencyKey, digest].join('\n')
        const key = await crypto.subtle.importKey('raw', encoder.encode(this.token), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign'])
        Object.assign(headers, { 'X-Log-Timestamp': timestamp, 'X-Log-Nonce': nonce, 'X-Log-Signature': hex(await crypto.subtle.sign('HMAC', key, encoder.encode(canonical))) })
      }
      let status: number | null = null, wait = 250 * 2 ** attempt
      const controller = new AbortController()
      const timer = setTimeout(() => controller.abort(), this.timeout)
      try {
        const response = await fetch(this.url, { method: 'POST', headers, body, signal: controller.signal, redirect: 'manual', credentials: 'omit' })
        status = response.status
        const retry = response.headers.get('Retry-After') ?? ''
        if (/^\d+$/.test(retry)) wait = Math.max(wait, Number(retry) * 1000)
        if (status === 200 || status === 201) {
          const reader = response.body?.getReader()
          if (!reader) throw new Error('Missing receipt')
          const chunks: Uint8Array[] = []; let length = 0
          try { for (;;) { const {done, value} = await reader.read(); if (done) break; length += value.length; if (length > 65536) throw new Error('Receipt too large'); chunks.push(value) } } finally { await reader.cancel().catch(() => {}); reader.releaseLock() }
          const bytes = new Uint8Array(length); let offset = 0; for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.length }
          const r = JSON.parse(new TextDecoder().decode(bytes)) as IngestReceipt
          if (r.accepted === true && r.received === count && r.stored === (status === 200 ? 0 : count) && r.rejected === 0 && r.replayed === (status === 200) && Array.isArray(r.event_ids) && r.event_ids.length === count && r.event_ids.every(id => typeof id === 'string' && id.length > 0)) return r
        } else { await response.body?.cancel() }
      } catch { /* network error or invalid receipt: retry same bytes */ } finally { clearTimeout(timer) }
      if (attempt + 1 === this.attempts || wait > 30000 || (status !== null && ![200,201,408,429,500,502,503,504].includes(status))) throw new DeliveryError(status, idempotencyKey)
      await delay(wait + Math.floor(Math.random() * 100))
    }
    throw new Error('Unreachable')
  }
}
