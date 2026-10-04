import type { StateCreator, StoreMutatorIdentifier } from 'zustand'

export const STORE_DEBUG_SETTING = 'baander-store-debug'
const MAX_CAPTURE_KEY_LENGTH = 512
const MAX_CAPTURE_STACK_LENGTH = 4096
function captureStack(): string | undefined {
  const stack = new Error().stack
  return stack && stack.length > MAX_CAPTURE_STACK_LENGTH
    ? `${stack.slice(0, MAX_CAPTURE_STACK_LENGTH - 12)}…[truncated]` : stack
}
function enabledAtStartup(): boolean {
  if (!import.meta.env?.DEV) return false
  try { return localStorage.getItem(STORE_DEBUG_SETTING) === 'true' } catch { return false }
}

export type DebugValue = null | boolean | number | string | DebugValue[] | { [key: string]: DebugValue }
export interface StateCapture { value: DebugValue; complete: boolean }
export interface StoreTraceEvent {
  schemaVersion: 1
  id: number
  parentId: number | null
  store: string
  kind: 'action' | 'update' | 'resolved' | 'rejected'
  action: string
  timestamp: number
  stack?: string
  args?: StateCapture
  before?: StateCapture
  after?: StateCapture
  changedKeys?: string[]
}
export interface StoreDebugSnapshot {
  events: readonly StoreTraceEvent[]
  stores: Readonly<Record<string, StateCapture>>
  droppedEvents: number
}

function freezeCapture<T>(value: T): T {
  if (value && typeof value === 'object' && !Object.isFrozen(value)) {
    for (const child of Object.values(value)) freezeCapture(child)
    Object.freeze(value)
  }
  return value
}

/** Limit retained diagnostic data; arbitrary object enumeration itself is not bounded. */
export function captureState(value: unknown, sensitive = false): StateCapture {
  let complete = true
  let budget = 600
  const seen = new WeakSet<object>()
  function visit(input: unknown, depth: number): DebugValue {
    if (--budget < 0 || depth > 6) { complete = false; return '[truncated]' }
    if (input === null || typeof input === 'boolean') return input
    if (typeof input === 'number') return Number.isFinite(input) ? input : String(input)
    if (typeof input === 'string') {
      if (input.length > 512) { complete = false; return `${input.slice(0, 512)}…` }
      return input
    }
    if (typeof input !== 'object') { complete = false; return `[${typeof input}]` }
    if (seen.has(input)) { complete = false; return '[circular]' }
    seen.add(input)
    if (Array.isArray(input)) {
      const length = Object.getOwnPropertyDescriptor(input, 'length')?.value as number
      if (length > 50) complete = false
      return Array.from({ length: Math.min(length, 50) }, (_, index) => {
        const descriptor = Object.getOwnPropertyDescriptor(input, String(index))
        if (!descriptor || !('value' in descriptor)) { complete = false; return '[accessor or empty slot]' }
        return visit(descriptor.value, depth + 1)
      })
    }
    const prototype = Object.getPrototypeOf(input)
    if (prototype !== Object.prototype && prototype !== null) { complete = false; return '[native or class instance]' }
    const output: Record<string, DebugValue> = Object.create(null)
    const keys = Object.getOwnPropertyNames(input)
    if (keys.length > 60) complete = false
    for (const key of keys.slice(0, 60)) {
      if (key.length > MAX_CAPTURE_KEY_LENGTH) { complete = false; continue }
      const descriptor = Object.getOwnPropertyDescriptor(input, key)
      if (!descriptor) { complete = false; continue }
      if (!('value' in descriptor)) { complete = false; output[key] = '[accessor]'; continue }
      if (typeof descriptor.value === 'function') continue
      if (/token|password|secret|credential|authorization|cookie|dpop|privatekey/i.test(key)
        || (sensitive && !['isAuthenticated', 'isLoading'].includes(key))) {
        complete = false
        output[key] = '[redacted]'
      } else output[key] = visit(descriptor.value, depth + 1)
    }
    return output
  }
  try {
    const captured = visit(value, 0)
    return freezeCapture({ value: captured, complete })
  } catch {
    return Object.freeze({ value: '[unavailable]', complete: false })
  }
}

/** Separate instances make recording deterministic in tests without enabling app tracing. */
export function createStoreDebugger(enabled: boolean, limit = 200) {
  let snapshot: StoreDebugSnapshot = Object.freeze({ events: Object.freeze([]), stores: Object.freeze({}), droppedEvents: 0 })
  let nextId = 1
  let parentId: number | null = null
  const listeners = new Set<() => void>()
  const capacity = Math.max(1, Math.min(1000, limit))
  const notify = () => { for (const listener of listeners) { try { listener() } catch { /* Debugging must not break actions. */ } } }
  const append = (event: StoreTraceEvent) => {
    const events = [...snapshot.events, freezeCapture(event)]
    const dropped = Math.max(0, events.length - capacity)
    snapshot = Object.freeze({ ...snapshot, events: Object.freeze(events.slice(dropped)), droppedEvents: snapshot.droppedEvents + dropped })
    notify()
  }
  function instrument<T extends object, Mps extends [StoreMutatorIdentifier, unknown][] = [], Mcs extends [StoreMutatorIdentifier, unknown][] = []>(
    name: string, creator: StateCreator<T, Mps, Mcs>,
  ): StateCreator<T, Mps, Mcs> {
    // No wrapper, action replacement, subscription, clock, or capture on the disabled path.
    if (!enabled) return creator
    return ((set, get, api) => {
      const sensitive = /auth/i.test(name)
      const capture = (state: unknown) => captureState(state, sensitive)
      const wrapSet = (original: typeof set): typeof set => ((...args: unknown[]) => {
        const beforeState = get()
        const before = capture(beforeState)
        const result = Reflect.apply(original, undefined, args)
        const afterState = get()
        if (!Object.is(beforeState, afterState)) {
          const after = capture(afterState)
          const beforeRecord = (beforeState ?? {}) as Record<string, unknown>
          const afterRecord = (afterState ?? {}) as Record<string, unknown>
          const changedKeys = [...new Set([...Object.keys(beforeRecord), ...Object.keys(afterRecord)])]
            .filter(key => key.length <= MAX_CAPTURE_KEY_LENGTH && !Object.is(beforeRecord[key], afterRecord[key]))
            .slice(0, 60)
          snapshot = Object.freeze({ ...snapshot, stores: Object.freeze({ ...snapshot.stores, [name]: after }) })
          append({ schemaVersion: 1, id: nextId++, parentId, store: name, kind: 'update', action: 'setState',
            timestamp: Date.now(), stack: captureStack(), before, after, changedKeys })
        }
        return result
      }) as typeof set
      const state = creator(wrapSet(set), get, api)
      api.setState = wrapSet(api.setState as typeof set) as typeof api.setState
      const actions = new Map<string, { original: unknown; wrapped: (this: unknown, ...args: unknown[]) => unknown }>()
      const wrapActions = (source: T): T => {
        const wrapped = { ...source }
        for (const [key, value] of Object.entries(source as Record<string, unknown>)) {
          if (typeof value !== 'function') continue
          const label = key.length > MAX_CAPTURE_KEY_LENGTH ? `${key.slice(0, MAX_CAPTURE_KEY_LENGTH - 12)}…[truncated]` : key
          let action = actions.get(key)
          if (!action || action.original !== value) {
            action = { original: value, wrapped: function (this: unknown, ...args: unknown[]) {
              const id = nextId++
              const previous = parentId
              append({ schemaVersion: 1, id, parentId: previous, store: name, kind: 'action', action: label,
                timestamp: Date.now(), stack: captureStack(),
                args: sensitive ? { value: '[redacted]', complete: false } : captureState(args) })
              parentId = id
              const settled = (kind: 'resolved' | 'rejected') => append({ schemaVersion: 1, id: nextId++, parentId: id,
                store: name, kind, action: label, timestamp: Date.now() })
              try {
                const result = Reflect.apply(value, this, args)
                if (result instanceof Promise) result.then(() => settled('resolved'), () => settled('rejected'))
                else settled('resolved')
                return result
              } catch (error) { settled('rejected'); throw error }
              finally { parentId = previous }
            } }
            actions.set(key, action)
          }
          Object.assign(wrapped, { [key]: action.wrapped })
        }
        return wrapped
      }
      const wrapped = wrapActions(state)
      // Persist exposes its original creator result through getInitialState.
      // Keep those defaults and stable snapshot identity, with traced actions.
      const getInitialState = api.getInitialState
      let tracedInitial: T | undefined
      api.getInitialState = () => {
        if (tracedInitial === undefined) {
          const initial = getInitialState()
          tracedInitial = initial === wrapped ? wrapped : wrapActions(initial)
        }
        return tracedInitial
      }
      snapshot = Object.freeze({ ...snapshot, stores: Object.freeze({ ...snapshot.stores, [name]: capture(wrapped) }) })
      return wrapped
    }) as StateCreator<T, Mps, Mcs>
  }
  return {
    enabled,
    instrument,
    getSnapshot: () => snapshot,
    subscribe(listener: () => void) {
      if (!enabled) return () => {}
      listeners.add(listener)
      return () => { listeners.delete(listener) }
    },
    clear() { snapshot = Object.freeze({ ...snapshot, events: Object.freeze([]), droppedEvents: 0 }); notify() },
    exportTrace() {
      return JSON.stringify({ schemaVersion: 1, purpose: 'diagnostic; side-effect replay is not supported',
        asyncParentage: 'Synchronous updates are linked; updates after await retain call stacks but are not guessed into a flow.',
        ...snapshot }, null, 2)
    },
  }
}

export const storeDebugger = createStoreDebugger(enabledAtStartup())
export const withStoreDebug = storeDebugger.instrument

/** The reload ensures disabled stores never retain runtime tracing wrappers. */
export function setStoreDebugEnabled(enabled: boolean): void {
  localStorage.setItem(STORE_DEBUG_SETTING, String(enabled))
  window.location.reload()
}
