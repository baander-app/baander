import { vi } from 'vitest'

/**
 * Test doubles for the browser primitives the auth coordination relies on: the Web Locks API,
 * BroadcastChannel and IndexedDB. jsdom provides none of the first two and no IndexedDB.
 */

function abortError(): DOMException {
  return new DOMException('The lock request was aborted', 'AbortError')
}

type LockCallback<T> = () => Promise<T> | T

interface LockWaiter {
  grant: () => void
  reject: (error: unknown) => void
  signal?: AbortSignal
  onAbort?: () => void
}

/** A single-lock manager: one holder at a time, FIFO waiters, abortable waits. */
export class FakeLockManager {
  private holder: string | null = null
  private readonly waiters: LockWaiter[] = []
  /** Every lock request, in order, with how it ended. */
  readonly requests: Array<{ name: string; outcome: 'granted' | 'aborted' | 'pending' }> = []

  request<T>(name: string, callback: LockCallback<T>): Promise<T>
  request<T>(name: string, options: { signal?: AbortSignal }, callback: LockCallback<T>): Promise<T>
  request<T>(
    name: string,
    optionsOrCallback: { signal?: AbortSignal } | LockCallback<T>,
    maybeCallback?: LockCallback<T>,
  ): Promise<T> {
    const options = typeof optionsOrCallback === 'function' ? {} : optionsOrCallback
    const callback = (typeof optionsOrCallback === 'function' ? optionsOrCallback : maybeCallback) as LockCallback<T>
    const entry = { name, outcome: 'pending' as 'granted' | 'aborted' | 'pending' }
    this.requests.push(entry)

    if (options.signal?.aborted) {
      entry.outcome = 'aborted'
      return Promise.reject(abortError())
    }

    return new Promise<T>((resolve, reject) => {
      const waiter: LockWaiter = {
        signal: options.signal,
        reject,
        grant: () => {
          entry.outcome = 'granted'
          this.holder = name
          waiter.signal?.removeEventListener('abort', waiter.onAbort!)
          Promise.resolve()
            .then(callback)
            .then(resolve, reject)
            .finally(() => this.release())
        },
      }
      waiter.onAbort = () => {
        const index = this.waiters.indexOf(waiter)
        if (index === -1) return
        this.waiters.splice(index, 1)
        entry.outcome = 'aborted'
        reject(abortError())
      }
      waiter.signal?.addEventListener('abort', waiter.onAbort)
      this.waiters.push(waiter)
      this.dispatch()
    })
  }

  /** Takes the lock as another tab would; the returned function releases it. */
  hold(name = 'baander-auth-refresh'): () => void {
    let release!: () => void
    const held = new Promise<void>((resolve) => { release = resolve })
    this.request(name, () => held).catch(() => {})
    return release
  }

  isHeld(): boolean {
    return this.holder !== null
  }

  private release(): void {
    this.holder = null
    this.dispatch()
  }

  private dispatch(): void {
    if (this.holder !== null) return
    const next = this.waiters.shift()
    if (next) next.grant()
  }
}

/** Installs `locks` on `navigator`; the returned function removes it again. */
export function installLocks(locks: FakeLockManager | undefined): () => void {
  Object.defineProperty(navigator, 'locks', { value: locks, configurable: true, writable: true })
  return () => {
    delete (navigator as { locks?: unknown }).locks
  }
}

interface FakeMessageEvent<T> {
  data: T
}

/** An in-process BroadcastChannel: a message reaches every other channel of the same name at once. */
export class FakeBroadcastChannel {
  static instances: FakeBroadcastChannel[] = []
  static posted: Array<{ name: string; message: unknown }> = []

  readonly name: string
  onmessage: ((event: FakeMessageEvent<unknown>) => void) | null = null

  constructor(name: string) {
    this.name = name
    FakeBroadcastChannel.instances.push(this)
  }

  postMessage(message: unknown): void {
    FakeBroadcastChannel.posted.push({ name: this.name, message })
    FakeBroadcastChannel.dispatch(this.name, message, this)
  }

  close(): void {
    FakeBroadcastChannel.instances = FakeBroadcastChannel.instances.filter((channel) => channel !== this)
  }

  /** Delivers a message as another tab would have posted it. */
  static deliver(name: string, message: unknown): void {
    FakeBroadcastChannel.dispatch(name, message, null)
  }

  static reset(): void {
    FakeBroadcastChannel.instances = []
    FakeBroadcastChannel.posted = []
  }

  private static dispatch(name: string, message: unknown, sender: FakeBroadcastChannel | null): void {
    for (const channel of FakeBroadcastChannel.instances) {
      if (channel === sender || channel.name !== name) continue
      channel.onmessage?.({ data: structuredClone(message) })
    }
  }
}

export function installBroadcastChannel(): () => void {
  FakeBroadcastChannel.reset()
  vi.stubGlobal('BroadcastChannel', FakeBroadcastChannel)
  return () => {
    vi.unstubAllGlobals()
    FakeBroadcastChannel.reset()
  }
}

// --- IndexedDB -------------------------------------------------------------------------------

type Handler<E> = ((event: E) => void) | null

class FakeRequest<T> {
  result!: T
  error: DOMException | null = null
  onsuccess: Handler<unknown> = null
  onerror: Handler<unknown> = null
}

interface StoreOperation {
  transaction: number
  mode: IDBTransactionMode
  operation: 'get' | 'put' | 'delete'
  key: string
}

class FakeObjectStore {
  private readonly transaction: FakeTransaction
  private readonly data: Map<string, Record<string, unknown>>

  constructor(transaction: FakeTransaction, data: Map<string, Record<string, unknown>>) {
    this.transaction = transaction
    this.data = data
  }

  get(key: string): FakeRequest<unknown> {
    return this.transaction.enqueue('get', key, () => {
      const value = this.data.get(key)
      return value === undefined ? undefined : structuredClone(value)
    })
  }

  put(value: Record<string, unknown>): FakeRequest<string> {
    const key = String(value[this.transaction.keyPath])
    return this.transaction.enqueue('put', key, () => {
      this.transaction.assertWritable()
      this.data.set(key, structuredClone(value))
      return key
    })
  }

  delete(key: string): FakeRequest<undefined> {
    return this.transaction.enqueue('delete', key, () => {
      this.transaction.assertWritable()
      this.data.delete(key)
      return undefined
    })
  }
}

class FakeTransaction {
  oncomplete: Handler<unknown> = null
  onerror: Handler<unknown> = null
  onabort: Handler<unknown> = null
  error: DOMException | null = null

  readonly id: number
  readonly mode: IDBTransactionMode
  readonly keyPath: string
  private readonly committed: Map<string, Record<string, unknown>>
  private readonly staged: Map<string, Record<string, unknown>>
  private readonly log: StoreOperation[]
  private readonly jobs: Array<() => void> = []
  private aborted = false
  private finished = false

  constructor(
    id: number,
    mode: IDBTransactionMode,
    keyPath: string,
    committed: Map<string, Record<string, unknown>>,
    staged: Map<string, Record<string, unknown>>,
    log: StoreOperation[],
  ) {
    this.id = id
    this.mode = mode
    this.keyPath = keyPath
    this.committed = committed
    this.staged = staged
    this.log = log
  }

  objectStore(): FakeObjectStore {
    return new FakeObjectStore(this, this.staged)
  }

  abort(): void {
    this.aborted = true
  }

  assertWritable(): void {
    if (this.mode !== 'readwrite') throw new DOMException('The transaction is read-only', 'ReadOnlyError')
  }

  enqueue<T>(operation: StoreOperation['operation'], key: string, work: () => T): FakeRequest<T> {
    if (this.finished) throw new DOMException('The transaction has finished', 'TransactionInactiveError')
    const request = new FakeRequest<T>()
    this.jobs.push(() => {
      if (this.aborted) return
      this.log.push({ transaction: this.id, mode: this.mode, operation, key })
      try {
        request.result = work()
        request.onsuccess?.({ target: request })
      } catch (error) {
        request.error = error as DOMException
        this.error = request.error
        this.aborted = true
        request.onerror?.({ target: request })
      }
    })
    return request
  }

  /** Runs the queued requests, one per microtask, then commits or aborts. */
  async run(): Promise<void> {
    while (this.jobs.length > 0 && !this.aborted) {
      await Promise.resolve()
      this.jobs.shift()!()
    }
    await Promise.resolve()
    this.finished = true
    if (this.aborted) {
      this.onabort?.({ target: this })
      return
    }
    this.committed.clear()
    for (const [key, value] of this.staged) this.committed.set(key, value)
    this.oncomplete?.({ target: this })
  }
}

class FakeDatabase {
  readonly objectStoreNames = {
    contains: (name: string) => this.stores.has(name),
  }

  readonly log: StoreOperation[]
  private readonly stores = new Map<string, { keyPath: string; data: Map<string, Record<string, unknown>> }>()
  private queue: Promise<void> = Promise.resolve()
  private nextTransaction = 1

  constructor(log: StoreOperation[]) {
    this.log = log
  }

  createObjectStore(name: string, options: { keyPath: string }): void {
    this.stores.set(name, { keyPath: options.keyPath, data: new Map() })
  }

  transaction(name: string, mode: IDBTransactionMode = 'readonly'): FakeTransaction {
    const store = this.stores.get(name)
    if (!store) throw new DOMException(`No object store ${name}`, 'NotFoundError')
    const staged = new Map<string, Record<string, unknown>>()
    const transaction = new FakeTransaction(this.nextTransaction++, mode, store.keyPath, store.data, staged, this.log)
    // Transactions on one store run one after the other, as IndexedDB serializes them.
    this.queue = this.queue.then(async () => {
      for (const [key, value] of store.data) staged.set(key, value)
      await transaction.run()
    })
    return transaction
  }

  close(): void {}

  /** The committed records, for assertions. */
  records(name: string): Map<string, Record<string, unknown>> {
    return this.stores.get(name)?.data ?? new Map()
  }
}

/**
 * An in-memory IndexedDB with one database and serialized transactions. Records are kept by
 * structured clone, as a browser does, so a CryptoKey written in one record reads back as a usable
 * key with its extractability intact; any record id works, the credential record and the pending
 * logouts alike.
 */
export class FakeIndexedDb {
  readonly log: StoreOperation[] = []
  private database: FakeDatabase | null = null

  open(): FakeRequest<FakeDatabase> & { onupgradeneeded: Handler<unknown> } {
    const request = Object.assign(new FakeRequest<FakeDatabase>(), { onupgradeneeded: null as Handler<unknown> })
    queueMicrotask(() => {
      const created = this.database === null
      this.database ??= new FakeDatabase(this.log)
      request.result = this.database
      if (created) request.onupgradeneeded?.({ target: request })
      request.onsuccess?.({ target: request })
    })
    return request
  }

  /** Writes a record straight into the store, as an earlier page would have left it. */
  seed(storeName: string, record: Record<string, unknown>): void {
    this.database ??= new FakeDatabase(this.log)
    if (!this.database.objectStoreNames.contains(storeName)) {
      this.database.createObjectStore(storeName, { keyPath: 'id' })
    }
    this.database.records(storeName).set(String(record.id), structuredClone(record))
  }

  records(storeName: string): Map<string, Record<string, unknown>> {
    return this.database?.records(storeName) ?? new Map()
  }
}

export function installIndexedDb(): { db: FakeIndexedDb; restore: () => void } {
  const db = new FakeIndexedDb()
  vi.stubGlobal('indexedDB', { open: () => db.open() })
  return { db, restore: () => vi.unstubAllGlobals() }
}
