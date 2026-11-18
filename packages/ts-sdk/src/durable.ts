/** Bounded, crash-recoverable Node.js delivery queue. All network calls use the real SDK HTTP client. */
import * as fs from 'node:fs/promises'
import { join, resolve } from 'node:path'
import { randomUUID, createHash } from 'node:crypto'
import { CajeerLogsClient, validateLogEvent } from './client.js'
import type { LogEvent } from './types.js'

export interface DurableDeliveryOptions {
  /** Private directory on a local, durable filesystem; do not share across hosts/NFS. */
  directory: string
  batchEvents?: number
  maxEvents?: number
  maxBytes?: number
  /** 0 disables background delivery; manual flush() still works. */
  flushIntervalMs?: number
  /** Fail closed after this age. Must not exceed the server's idempotency retention. */
  recoveryWindowMs?: number
}
export interface SpoolStatus {
  events: number
  bytes: number
  batches: number
  oldestCreatedAt: number | null
  lastError: string | null
}
export class SpoolFullError extends Error { constructor() { super('Durable queue is full; event was NOT accepted') } }
export class SpoolExpiredError extends Error {
  constructor(public readonly key: string) { super(`Batch ${key} exceeds the safe recovery window; reconcile it with the server before replaying`) }
}
export class SpoolCorruptError extends Error { constructor(message: string) { super('Durable queue corrupted: ' + message) } }

interface QueuedEvent { version: 1; body: string; sha256: string }
interface Batch {
  version: 1
  key: string
  body: string
  ids: string[]
  createdAt: number
  sha256: string
}
const encoder = new TextEncoder()
const MAX_BODY = 1048576
const MAX_EVENTS = 100
const digest = (s: string): string => createHash('sha256').update(s, 'utf8').digest('hex')
const batchDigest = (batch: Omit<Batch, 'sha256'>): string => digest(JSON.stringify([batch.key,batch.body,batch.ids,batch.createdAt]))
function deserializeEvent(serialized: string, file: string): string {
  let item: QueuedEvent
  try { item = JSON.parse(serialized) as QueuedEvent } catch { throw new SpoolCorruptError('invalid event JSON: ' + file) }
  if (item.version !== 1 || typeof item.body !== 'string' || item.sha256 !== digest(item.body)) {
    throw new SpoolCorruptError('invalid event checksum: ' + file)
  }
  try { validateLogEvent(JSON.parse(item.body)) }
  catch { throw new SpoolCorruptError('invalid event body: ' + file) }
  return item.body
}
const pause = (ms: number) => new Promise<void>(r => setTimeout(r, ms))
const validId = (id: string): boolean => /^(?:[0-9]{14}-)?[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/.test(id)
const entryFile = (id: string) => `${id}.json`

async function syncDirectory(dir: string): Promise<void> {
  try { const h = await fs.open(dir, 'r'); try { await h.sync() } finally { await h.close() } }
  catch (e) { if (process.platform !== 'win32') throw e }
}
async function atomicWrite(dir: string, file: string, data: string): Promise<void> {
  const temp = join(dir, '.' + randomUUID() + '.tmp')
  let handle: fs.FileHandle | undefined
  try {
    handle = await fs.open(temp, 'wx', 0o600)
    await handle.writeFile(data, 'utf8')
    await handle.sync()
    await handle.close(); handle = undefined
    await fs.rename(temp, join(dir, file))
    await syncDirectory(dir)
  } finally {
    await handle?.close().catch(() => {})
    await fs.unlink(temp).catch(e => { if (e.code !== 'ENOENT') throw e })
  }
}
async function files(dir: string): Promise<string[]> {
  const names = await fs.readdir(dir)
  for (const name of names) {
    if (name.startsWith('.') && name.endsWith('.tmp')) continue // uncommitted atomic writes
    if (!name.endsWith('.json') || !validId(name.slice(0, -5))) throw new SpoolCorruptError('unexpected file: ' + name)
  }
  return names.filter(n => n.endsWith('.json')).sort()
}
function alive(pid: number): boolean {
  try { process.kill(pid, 0); return true }
  catch (e) { return (e as NodeJS.ErrnoException).code !== 'ESRCH' }
}
async function locked<T>(root: string, action: () => Promise<T>): Promise<T> {
  const path = join(root, '.lock')
  const until = Date.now() + 10000
  for (;;) {
    let handle: fs.FileHandle | undefined
    try {
      handle = await fs.open(path, 'wx', 0o600)
      try {
        await handle.writeFile(JSON.stringify({ pid: process.pid }))
        await handle.sync()
        return await action()
      } finally {
        await handle.close()
        await fs.unlink(path)
      }
    } catch (e) {
      if (handle) throw e
      if ((e as NodeJS.ErrnoException).code !== 'EEXIST') throw e
      // Only reclaim an abandoned lock after its owner exited. A live owner is never stolen.
      const stat = await fs.stat(path).catch(() => null)
      if (stat && Date.now() - stat.mtimeMs > 5000) {
        const owner = await fs.readFile(path, 'utf8').then(s => JSON.parse(s) as { pid: number }).catch(() => null)
        if (owner && Number.isInteger(owner.pid) && !alive(owner.pid)) {
          await fs.unlink(path).catch(() => {})
          continue
        }
      }
      if (Date.now() >= until) throw new Error('Durable queue is locked by another process')
      await pause(25)
    }
  }
}

/**
 * enqueue() acknowledges durable *local* storage, NOT server commit. flush() confirms HTTP
 * 201/200 before removing files. The same immutable body/key is replayed after a crash.
 * Concurrency across local processes is supported for enqueue; use one delivery worker per spool.
 */
export class DurableBatchDelivery {
  private readonly eventsDir: string
  private readonly batchesDir: string
  private readonly batchEvents: number
  private readonly maxEvents: number
  private readonly maxBytes: number
  private readonly recoveryWindowMs: number
  private readonly intervalMs: number
  private timer?: ReturnType<typeof setInterval>
  private ongoing?: Promise<number>
  private closed = false
  private error: string | null = null
  private constructor(private readonly client: CajeerLogsClient, private readonly directory: string, options: DurableDeliveryOptions) {
    this.eventsDir = join(directory, 'events')
    this.batchesDir = join(directory, 'batches')
    this.batchEvents = options.batchEvents ?? 50
    this.maxEvents = options.maxEvents ?? 10000
    this.maxBytes = options.maxBytes ?? 64 * 1048576
    this.recoveryWindowMs = options.recoveryWindowMs ?? 23 * 3600000
    this.intervalMs = options.flushIntervalMs ?? 1000
    if (!Number.isInteger(this.batchEvents) || this.batchEvents < 1 || this.batchEvents > MAX_EVENTS ||
        !Number.isInteger(this.maxEvents) || this.maxEvents < this.batchEvents || this.maxEvents > 100000 ||
        !Number.isInteger(this.maxBytes) || this.maxBytes < 1048576 || this.maxBytes > 1024 * 1048576 ||
        !Number.isInteger(this.recoveryWindowMs) || this.recoveryWindowMs < 1000 || this.recoveryWindowMs > 7 * 24 * 3600000 ||
        !Number.isInteger(this.intervalMs) || this.intervalMs < 0 || (this.intervalMs > 0 && this.intervalMs < 100)) throw new Error('Invalid durable queue limits')
  }
  static async open(client: CajeerLogsClient, options: DurableDeliveryOptions): Promise<DurableBatchDelivery> {
    if (!options.directory || !options.directory.trim()) throw new Error('Durable spool directory required')
    const root = resolve(options.directory)
    const queue = new DurableBatchDelivery(client, root, options)
    for (const dir of [root, queue.eventsDir, queue.batchesDir]) {
      await fs.mkdir(dir, { recursive: true, mode: 0o700 })
      const st = await fs.lstat(dir)
      if (!st.isDirectory() || (process.platform !== 'win32' && (st.mode & 0o077) !== 0)) {
        throw new SpoolCorruptError('spool directories must be private (0700) and not symlinks')
      }
    }
    await locked(root, async () => {
      const path = join(root, 'scope.json')
      const expected = JSON.stringify({ version: 1, fingerprint: await client.spoolIdentity() })
      try { if (await fs.readFile(path, 'utf8') !== expected) throw new SpoolCorruptError('API endpoint or credential changed') }
      catch (e) {
        if ((e as NodeJS.ErrnoException).code !== 'ENOENT') throw e
        await atomicWrite(root, 'scope.json', expected)
      }
      await queue.snapshot() // reject damaged batches before accepting new events
    })
    if (queue.intervalMs) {
      queue.timer = setInterval(() => { void queue.flush().catch(e => { queue.error = String(e) }) }, queue.intervalMs)
      queue.timer.unref()
    }
    return queue
  }
  private ensureOpen(): void { if (this.closed) throw new Error('Durable queue is closed') }
  private async snapshot(): Promise<{ eventFiles: string[], batches: Array<{ file: string, batch: Batch }>, bytes: number, oldestEvent: number | null }> {
    const eventFiles = await files(this.eventsDir)
    const batchFiles = await files(this.batchesDir)
    let bytes = 0, oldestEvent: number | null = null
    for (const file of eventFiles) {
      const stat = await fs.stat(join(this.eventsDir, file))
      bytes += stat.size
      oldestEvent = oldestEvent === null ? stat.mtimeMs : Math.min(oldestEvent, stat.mtimeMs)
    }
    const batches: Array<{file:string,batch:Batch}> = []
    for (const file of batchFiles) {
      const data = await fs.readFile(join(this.batchesDir, file), 'utf8')
      bytes += Buffer.byteLength(data)
      let batch: Batch
      try { batch = JSON.parse(data) as Batch } catch { throw new SpoolCorruptError('invalid batch JSON: ' + file) }
      if (batch.version !== 1 || !batch.ids?.length || batch.ids.length > 100 || !batch.ids.every(validId) ||
          new Set(batch.ids).size !== batch.ids.length || !/^ts-[0-9a-f-]{36}$/.test(batch.key) ||
          !Number.isSafeInteger(batch.createdAt) || typeof batch.body !== 'string' || encoder.encode(batch.body).length > MAX_BODY ||
          batch.sha256 !== batchDigest(batch)) throw new SpoolCorruptError('invalid batch: ' + file)
      batches.push({ file, batch })
    }
    batches.sort((a,b) => a.batch.createdAt - b.batch.createdAt || a.file.localeCompare(b.file))
    return { eventFiles, batches, bytes, oldestEvent }
  }
  async status(): Promise<SpoolStatus> {
    this.ensureOpen()
    return locked(this.directory, async () => {
      const s = await this.snapshot()
      return { events: s.eventFiles.length, bytes: s.bytes, batches: s.batches.length,
        oldestCreatedAt: [s.oldestEvent, s.batches[0]?.batch.createdAt ?? null].filter((v): v is number => v !== null).reduce<number | null>((a,v) => a === null ? v : Math.min(a,v), null),
        lastError: this.error }
    })
  }
  /** Returns after fsync; on overflow throws without accepting an event. */
  async enqueue(event: LogEvent): Promise<void> {
    this.ensureOpen()
    validateLogEvent(event)
    const body = JSON.stringify(event)
    const stored = JSON.stringify({ version: 1, body, sha256: digest(body) } satisfies QueuedEvent)
    const size = encoder.encode(stored).length
    const count = await locked(this.directory, async () => {
      const snapshot = await this.snapshot()
      if (snapshot.eventFiles.length >= this.maxEvents || snapshot.bytes + size > this.maxBytes) throw new SpoolFullError()
      // Lexicographically ordered UTC timestamp + UUID keeps FIFO order across processes.
      const id = `${new Date().toISOString().slice(0,19).replace(/[-:T]/g, '')}-${randomUUID()}`
      await atomicWrite(this.eventsDir, entryFile(id), stored)
      return snapshot.eventFiles.length + 1
    })
    if (count >= this.batchEvents && count % this.batchEvents === 0 && this.intervalMs) {
      void this.flush().catch(e => { this.error = String(e) })
    }
  }
  private async nextBatch(): Promise<{ file: string, batch: Batch } | undefined> {
    return locked(this.directory, async () => {
      const s = await this.snapshot()
      if (s.batches.length) return s.batches[0]
      const ids: string[] = []
      const parts: string[] = []
      let used = encoder.encode('{"events":[]}').length
      for (const file of s.eventFiles) {
        const body = deserializeEvent(await fs.readFile(join(this.eventsDir, file), 'utf8'), file)
        const additional = encoder.encode(body).length + (parts.length ? 1 : 0)
        if (ids.length >= this.batchEvents || used + additional > MAX_BODY) break
        parts.push(body); ids.push(file.slice(0,-5)); used += additional
      }
      if (!ids.length) return undefined
      const body = `{"events":[${parts.join(',')}]}`
      const batchData = { version: 1 as const, key: 'ts-' + randomUUID(), body, ids, createdAt: Date.now() }
      const batch: Batch = { ...batchData, sha256: batchDigest(batchData) }
      const file = entryFile(randomUUID())
      await atomicWrite(this.batchesDir, file, JSON.stringify(batch))
      return { file, batch }
    })
  }
  /** Deliver up to maxBatches. All events remain durable on failure; caller can retry after recovery. */
  async flush(maxBatches = 100): Promise<number> {
    this.ensureOpen()
    if (!Number.isInteger(maxBatches) || maxBatches < 1 || maxBatches > 10000) throw new Error('Invalid flush limit')
    if (this.ongoing) return this.ongoing
    const work = async (): Promise<number> => {
      let delivered = 0
      for (let i = 0; i < maxBatches; i++) {
        const next = await this.nextBatch()
        if (!next) break
        if (Date.now() - next.batch.createdAt >= this.recoveryWindowMs) throw new SpoolExpiredError(next.batch.key)
        await this.client.sendBody(next.batch.body, next.batch.key)
        await locked(this.directory, async () => {
          for (const id of next.batch.ids) await fs.unlink(join(this.eventsDir, entryFile(id))).catch(e => { if (e.code !== 'ENOENT') throw e })
          await syncDirectory(this.eventsDir)
          await fs.unlink(join(this.batchesDir, next.file)).catch(e => { if (e.code !== 'ENOENT') throw e })
          await syncDirectory(this.batchesDir)
        })
        delivered += next.batch.ids.length
      }
      this.error = null
      return delivered
    }
    const promise = work()
    this.ongoing = promise
    try { return await promise } finally { if (this.ongoing === promise) this.ongoing = undefined }
  }
  /** Stop periodic work; data remains on disk for a subsequent process. */
  async close(): Promise<void> {
    if (this.closed) return
    this.closed = true
    if (this.timer) clearInterval(this.timer)
    await this.ongoing?.catch(() => {})
  }
}
