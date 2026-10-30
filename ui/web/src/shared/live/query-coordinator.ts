import { CancelledError, type Query, type QueryClient, type QueryKey } from '@tanstack/react-query'
import { expandLiveFamilies } from './descriptors'
import { bindLiveReadContext } from './http-admission'
import type { LiveQueryDescriptor } from './descriptor-types'
import type { LiveFamily } from './protocol'
import type { LiveRecovery } from './recovery'

const MAX_PENDING_READS = 256
const BACKGROUND_READ_CAPACITY = MAX_PENDING_READS - 4

interface DirtyState {
  generation: number
  acknowledged: number
  started: number
  failures: number
  retryAt: number
}

export interface LiveReadAdapter {
  id: string
  family: LiveFamily
  isActive: () => boolean
  read: (signal: AbortSignal) => Promise<unknown>
  purge?: () => void
}

interface AdapterState extends DirtyState {
  adapter: LiveReadAdapter
  request: AbortController | null
}

/** Serializes authoritative reads without owning selection, drafts, or playback. */
export class LiveQueryCoordinator {
  private readonly states = new WeakMap<Query, DirtyState>()
  private readonly activeReads = new Set<string>()
  private readonly foregroundReads = new Set<string>()
  private preferAdapters = true
  private cursor = 0
  private adapterCursor = 0
  private readonly boundSignals = new WeakSet<AbortSignal>()
  private wakeAt = Infinity
  private readonly families = new Map<LiveFamily, number>()
  private readonly lastPass = new Map<LiveFamily, number>()
  private readonly lastSample = new Map<LiveQueryDescriptor, number>()
  private readonly adapters = new Map<string, AdapterState>()
  private readonly holds = new Map<LiveFamily, number>()
  private readonly reads = new WeakSet<AbortSignal>()
  private timer: ReturnType<typeof setTimeout> | null = null
  private stops: (() => void)[] = []
  private running = false
  private generation = 0
  private visible = true
  private online = true

  private readonly client: QueryClient
  private readonly recovery: Pick<
    LiveRecovery,
    'getSnapshot' | 'subscribeEvents' | 'subscribe' | 'completeRecovery' | 'resourceReadFailed'
  >
  private readonly descriptors: readonly LiveQueryDescriptor[]

  constructor(client: QueryClient, recovery: LiveQueryCoordinator['recovery'], descriptors: readonly LiveQueryDescriptor[]) {
    this.client = client
    this.recovery = recovery
    this.descriptors = descriptors
  }

  start(): () => void {
    if (this.running) throw new Error('Live coordinator already started')
    this.running = true
    this.visible = typeof document === 'undefined' || document.visibilityState !== 'hidden'
    this.online = typeof navigator === 'undefined' || navigator.onLine
    this.stops.push(this.recovery.subscribeEvents(event => {
      if (event.type === 'reset') {
        for (const query of this.client.getQueryCache().getAll()) {
          if (this.match(query.queryKey).some(descriptor => event.families.includes(descriptor.family))) {
            query.cancel().catch(() => undefined)
            query.setState({ data: undefined, dataUpdatedAt: 0, status: 'pending', fetchStatus: 'idle' })
            this.client.removeQueries({ queryKey: query.queryKey, exact: true })
          }
        }
        for (const state of this.adapters.values()) {
          if (event.families.includes(state.adapter.family)) {
            state.request?.abort()
            state.adapter.purge?.()
          }
        }
      } else {
        const families = event.type === 'baseline' ? event.authority.families : event.families
        this.markDirty(families, event.type === 'baseline')
      }
    }))
    this.stops.push(this.recovery.subscribe(() => this.schedule()))
    this.stops.push(this.client.getQueryCache().subscribe(event => {
      const query = event.query
      if (event.type === 'removed' || query.state.fetchStatus === 'idle') {
        this.activeReads.delete(`query:${query.queryHash}`)
        this.foregroundReads.delete(`query:${query.queryHash}`)
        this.schedule()
        if (event.type === 'removed') return
      }
      if (!this.match(query.queryKey).length) return
      const state = this.stateFor(query)
      if (event.type === 'updated' && event.action.type === 'fetch') {
        state.started = state.generation
      }
      if (event.type === 'updated' && event.action.type === 'success'
        && !event.action.manual && !query.state.fetchMeta?.fetchMore) {
        state.acknowledged = Math.max(state.acknowledged, state.started)
        state.failures = 0
        state.retryAt = 0
      }
      if (event.type === 'updated' && event.action.type === 'error') {
        state.failures++
        state.retryAt = Date.now() + Math.min(30_000, 1_000 * 2 ** Math.min(state.failures - 1, 5))
        this.recovery.resourceReadFailed()
      }
      if (state.generation > state.acknowledged && !query.state.isInvalidated) query.invalidate()
      this.schedule()
    }))
    this.stops.push(this.client.getMutationCache().subscribe(event => {
      if (event.type === 'updated' && event.action.type === 'pending') {
        // Cancel before optimistic callbacks can update the cache. The signal fence also
        // rejects fetchers which finish after cancellation without consuming their signal.
        this.client.cancelQueries({
          predicate: query => this.matches(query.queryKey),
        }).catch(() => undefined)
        for (const state of this.adapters.values()) state.request?.abort()
      }
      this.schedule()
    }))
    if (typeof document !== 'undefined') {
      const visibility = () => this.setVisible(document.visibilityState !== 'hidden')
      const connectivity = () => {
        this.online = navigator.onLine
        this.schedule()
      }
      document.addEventListener('visibilitychange', visibility)
      window.addEventListener('online', connectivity)
      window.addEventListener('offline', connectivity)
      this.stops.push(() => {
        document.removeEventListener('visibilitychange', visibility)
        window.removeEventListener('online', connectivity)
        window.removeEventListener('offline', connectivity)
      })
    }
    if (this.recovery.getSnapshot().authorityReady) this.hint(this.recovery.getSnapshot().families)
    this.schedule()
    return () => this.stop()
  }

  stop(): void {
    this.running = false
    this.client.cancelQueries({ predicate: query => this.matches(query.queryKey) }).catch(() => undefined)
    this.activeReads.clear()
    this.foregroundReads.clear()
    if (this.timer) clearTimeout(this.timer)
    this.timer = null
    this.stops.splice(0).forEach(stop => stop())
    for (const state of this.adapters.values()) state.request?.abort()
  }

  getWorkSnapshot(): { active: number; background: number; wakeScheduled: boolean } {
    return {
      active: this.activeReads.size,
      background: this.activeReads.size - this.foregroundReads.size,
      wakeScheduled: this.timer !== null,
    }
  }

  matches(key: QueryKey): boolean {
    return this.match(key).length > 0
  }

  /** Initial automatic reads use the same bounded admission as scheduled refreshes. */
  acquireRead(key: QueryKey, signal: AbortSignal, foreground = false): void {
    const query = this.client.getQueryCache().find({ queryKey: key, exact: true })
    if (!query) throw new CancelledError({ revert: true })
    const id = `query:${query.queryHash}`
    foreground = foreground && (!this.activeReads.has(id) || this.foregroundReads.has(id))
    const held = this.match(key).some(descriptor => (this.holds.get(descriptor.family) ?? 0) > 0)
    const blocked = !this.running || !this.recovery.getSnapshot().authorityReady || !this.online
      || (!foreground && (!this.visible || this.client.isMutating() > 0 || held))
    const atCapacity = foreground
      ? this.activeReads.size >= MAX_PENDING_READS
      : this.backgroundAtCapacity()
    if (blocked || (!this.activeReads.has(id) && atCapacity)) {
      const state = this.stateFor(query)
      if (state.generation <= state.acknowledged) state.generation = ++this.generation
      query.invalidate()
      query.cancel({ revert: true }).catch(() => undefined)
      this.schedule()
      throw new CancelledError({ revert: true })
    }
    this.activeReads.add(id)
    if (foreground) this.foregroundReads.add(id)
    if (!this.boundSignals.has(signal)) {
      this.boundSignals.add(signal)
      const release = () => {
        signal.removeEventListener('abort', release)
        this.activeReads.delete(id)
        this.foregroundReads.delete(id)
        this.schedule()
      }
      signal.addEventListener('abort', release, { once: true })
    }
  }

  /** Called by the public QueryClient wrapper for every page of one fetch. */
  readStarted(key: QueryKey, signal: AbortSignal): void {
    const descriptor = this.match(key)[0]
    const query = this.client.getQueryCache().find({ queryKey: key, exact: true })
    if (!descriptor || !query) return
    bindLiveReadContext(signal, {
      family: descriptor.family,
      priority: this.foregroundReads.has(`query:${query.queryHash}`) ? 'foreground' : 'background',
    })
    if (!this.reads.has(signal)) {
      const state = this.stateFor(query)
      state.started = state.generation
      this.reads.add(signal)
    }
  }

  hint(families: readonly LiveFamily[]): void {
    this.markDirty(families)
  }

  private markDirty(sourceFamilies: readonly LiveFamily[], baseline = false): void {
    const families = expandLiveFamilies(sourceFamilies)
    const generation = ++this.generation
    for (const family of families) {
      this.families.set(family, generation)
      for (const query of this.client.getQueryCache().getAll()) {
        const affected = this.match(query.queryKey).some(descriptor => {
          if (descriptor.family !== family || descriptor.refreshPolicy === 'demand') return false
          const deploymentFixed = descriptor.refreshPolicy === 'deployment' || descriptor.deploymentFixed
          return baseline || !deploymentFixed || sourceFamilies.includes('deployment-definitions')
        })
        if (affected) {
          this.stateFor(query).generation = generation
          query.invalidate()
        }
      }
      for (const state of this.adapters.values()) {
        if (state.adapter.family === family) state.generation = generation
      }
    }
    this.schedule()
  }

  setVisible(visible: boolean): void {
    this.visible = visible
    this.schedule()
  }

  /** Hold before optimistic writes; release after the confirmed write or rollback. */
  beginMutation(sourceFamilies: readonly LiveFamily[]): () => void {
    const families = expandLiveFamilies(sourceFamilies)
    for (const family of families) this.holds.set(family, (this.holds.get(family) ?? 0) + 1)
    this.client.cancelQueries({
      predicate: query => this.match(query.queryKey).some(descriptor => families.includes(descriptor.family)),
    }).catch(() => undefined)
    for (const state of this.adapters.values()) {
      if (families.includes(state.adapter.family)) state.request?.abort()
    }
    let released = false
    return () => {
      if (released) return
      released = true
      for (const family of families) this.holds.set(family, Math.max(0, (this.holds.get(family) ?? 1) - 1))
      this.hint(families)
    }
  }

  registerAdapter(adapter: LiveReadAdapter): () => void {
    if (this.adapters.has(adapter.id)) throw new Error(`Duplicate live reader: ${adapter.id}`)
    const state: AdapterState = {
      adapter,
      request: null,
      generation: this.families.get(adapter.family) ?? 0,
      acknowledged: 0,
      started: 0,
      failures: 0,
      retryAt: 0,
    }
    this.adapters.set(adapter.id, state)
    this.schedule()
    return () => {
      state.request?.abort()
      this.adapters.delete(adapter.id)
    }
  }

  notifyAdapterActivityChanged(): void {
    this.schedule()
  }

  private match(key: QueryKey): LiveQueryDescriptor[] {
    return this.descriptors.filter(descriptor => descriptor.matches(key))
  }

  private backgroundAtCapacity(): boolean {
    return this.activeReads.size >= MAX_PENDING_READS
      || this.activeReads.size - this.foregroundReads.size >= BACKGROUND_READ_CAPACITY
  }

  private stateFor(query: Query): DirtyState {
    let state = this.states.get(query)
    if (!state) {
      const generations = this.match(query.queryKey).map(descriptor => this.families.get(descriptor.family) ?? 0)
      state = {
        generation: Math.max(0, ...generations),
        acknowledged: 0,
        started: 0,
        failures: 0,
        retryAt: 0,
      }
      this.states.set(query, state)
    }
    return state
  }

  private schedule(delay = 100): void {
    if (!this.running) return
    const due = Date.now() + Math.max(1, delay)
    if (this.timer && this.wakeAt <= due) return
    if (this.timer) clearTimeout(this.timer)
    this.wakeAt = due
    this.timer = setTimeout(() => {
      this.timer = null
      this.wakeAt = Infinity
      this.flush()
    }, Math.max(1, delay))
  }

  private flush(): void {
    if (!this.running) return
    const snapshot = this.recovery.getSnapshot()
    if (!this.visible || !this.online || !snapshot.authorityReady) return
    const now = Date.now()
    let nextWake = Infinity
    const active = this.client.getQueryCache().getAll().filter(query => query.isActive() && !query.isDisabled())
    for (const descriptor of this.descriptors) {
      if (!descriptor.sampleIntervalMs || !snapshot.families.includes(descriptor.family)) continue
      if (!active.some(query => descriptor.matches(query.queryKey))) continue
      const sampleAge = now - (this.lastSample.get(descriptor) ?? 0)
      if (sampleAge >= descriptor.sampleIntervalMs) {
        this.lastSample.set(descriptor, now)
        const generation = ++this.generation
        for (const query of active) {
          if (descriptor.matches(query.queryKey)) {
            this.stateFor(query).generation = generation
            query.invalidate()
          }
        }
      }
      const nextSample = (this.lastSample.get(descriptor) ?? 0) + descriptor.sampleIntervalMs
      nextWake = Math.min(nextWake, Math.max(1, nextSample - now))
    }
    const started = new Set<LiveFamily>()
    let pending = false
    const refreshAdapters = () => {
      const adapterStates = [...this.adapters.values()]
      const orderedAdapters = [...adapterStates.slice(this.adapterCursor), ...adapterStates.slice(0, this.adapterCursor)]
      for (const state of orderedAdapters) {
        const { adapter } = state
        if (!snapshot.families.includes(adapter.family) || !adapter.isActive() || state.generation <= state.acknowledged) continue
        pending = true
        if (state.retryAt > now) {
          nextWake = Math.min(nextWake, state.retryAt - now)
          continue
        }
        if (state.request || this.backgroundAtCapacity()
          || this.client.isMutating() > 0 || this.holds.get(adapter.family)) continue
        const passAge = now - (this.lastPass.get(adapter.family) ?? -Infinity)
        if (!started.has(adapter.family) && passAge < 500) {
          nextWake = Math.min(nextWake, 500 - passAge)
          continue
        }
        const adapterId = `adapter:${adapter.id}`
        this.activeReads.add(adapterId)
        this.adapterCursor = (adapterStates.indexOf(state) + 1) % Math.max(1, adapterStates.length)
        started.add(adapter.family)
        const request = new AbortController()
        state.request = request
        request.signal.addEventListener('abort', () => {
          if (state.request !== request) return
          state.request = null
          this.activeReads.delete(adapterId)
          this.schedule()
        }, { once: true })
        const generation = state.generation
        bindLiveReadContext(request.signal, { family: adapter.family, priority: 'background' })
        Promise.resolve().then(() => adapter.read(request.signal)).then(() => {
          if (request.signal.aborted) return
          state.acknowledged = generation
          state.failures = 0
          state.retryAt = 0
        }).catch(() => {
          if (request.signal.aborted) return
          state.retryAt = Date.now() + Math.min(30_000, 1_000 * 2 ** Math.min(state.failures++, 5))
          this.recovery.resourceReadFailed()
        }).finally(() => {
          if (state.request === request) {
            this.activeReads.delete(adapterId)
            state.request = null
          }
          this.schedule()
        })
      }
    }
    if (this.preferAdapters) refreshAdapters()
    const ordered = [...active.slice(this.cursor), ...active.slice(0, this.cursor)]
    for (const query of ordered) {
      const descriptors = this.match(query.queryKey).filter(descriptor =>
        snapshot.families.includes(descriptor.family) && descriptor.refreshPolicy !== 'demand',
      )
      if (!descriptors.length) continue
      const state = this.stateFor(query)
      if (state.generation <= state.acknowledged) continue
      pending = true
      const family = descriptors[0].family
      if (state.retryAt > now) {
        nextWake = Math.min(nextWake, state.retryAt - now)
        continue
      }
      if (query.state.fetchStatus !== 'idle' || this.client.isMutating() > 0 || this.holds.get(family)) continue
      const id = `query:${query.queryHash}`
      if (!this.activeReads.has(id) && this.backgroundAtCapacity()) continue
      const passAge = now - (this.lastPass.get(family) ?? -Infinity)
      if (!started.has(family) && passAge < 500) {
        nextWake = Math.min(nextWake, 500 - passAge)
        continue
      }
      this.activeReads.add(id)
      this.cursor = (active.indexOf(query) + 1) % Math.max(1, active.length)
      started.add(family)
      state.started = state.generation
      query.fetch(undefined, { cancelRefetch: false }).catch(() => undefined).finally(() => this.schedule())
    }
    if (!this.preferAdapters) refreshAdapters()
    if (started.size) this.preferAdapters = !this.preferAdapters
    for (const family of started) this.lastPass.set(family, now)
    if (!pending && snapshot.status !== 'current') this.recovery.completeRecovery()
    if (Number.isFinite(nextWake)) this.schedule(nextWake)
  }
}
