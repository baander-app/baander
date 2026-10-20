import { foldStoreEvent } from './reconstruct-captures'
import { verifyTransition } from './verify-transition'
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
export interface StoreTransitionDescriptor {
  readonly id: string
  readonly version: number
  readonly actions: readonly string[]
  validateState(value: DebugValue): boolean
  validateArgs(action: string, args: DebugValue): boolean
  calculate(state: DebugValue, action: string, args: DebugValue): DebugValue
}
export interface StateCapture { value: DebugValue; complete: boolean }
export interface StoreTraceEvent {
  schemaVersion: 2
  instanceId: number
  id: number
  parentId: number | null
  store: string
  kind: 'register' | 'retire' | 'action' | 'update' | 'resolved' | 'rejected'
  action: string
  timestamp: number
  stack?: string
  args?: StateCapture
  before?: StateCapture
  after?: StateCapture
  transition?: { id: string; version: number }
  completion?: 'synchronous'
  changedKeys?: string[]
}
export interface StoreInstanceCapture {
  name: string
  capture: StateCapture
  sourceEventId: number
}
export type StoreCaptures = Readonly<Record<string, StoreInstanceCapture>>
export interface StoreHistoryBaseline {
  epoch: number
  boundaryEventId: number | null
  stores: StoreCaptures
}
export interface StoreDebugSnapshot {
  schemaVersion: 2
  events: readonly StoreTraceEvent[]
  stores: StoreCaptures
  baseline: StoreHistoryBaseline
  droppedEvents: number
  omittedRegistrations: number
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
  let snapshot: StoreDebugSnapshot = freezeCapture({
    schemaVersion: 2, events: [], stores: {},
    baseline: { epoch: 1, boundaryEventId: null, stores: {} },
    droppedEvents: 0, omittedRegistrations: 0,
  })
  let nextId = 1
  let nextInstanceId = 1
  let admissions = 0
  let parentId: number | null = null
  const transitions = new Map<number, StoreTransitionDescriptor>()
  const retirements = new Map<number, () => void>()
  const listeners = new Set<() => void>()
  const capacity = Math.max(1, Math.min(1000, limit))
  const notify = () => { for (const listener of listeners) { try { listener() } catch { /* Debugging must not break actions. */ } } }
  const append = (event: StoreTraceEvent) => {
    const events = [...snapshot.events, freezeCapture(event)]
    const dropped = Math.max(0, events.length - capacity)
    let baseline = snapshot.baseline
    for (const evicted of events.slice(0, dropped)) {
      baseline = { ...baseline, boundaryEventId: evicted.id, stores: foldStoreEvent(baseline.stores, evicted) }
    }
    snapshot = freezeCapture({ ...snapshot, stores: foldStoreEvent(snapshot.stores, event), baseline,
      events: events.slice(dropped), droppedEvents: snapshot.droppedEvents + dropped })
    notify()
  }
  function instrument<T extends object, Mps extends [StoreMutatorIdentifier, unknown][] = [], Mcs extends [StoreMutatorIdentifier, unknown][] = []>(
    name: string, creator: StateCreator<T, Mps, Mcs>, descriptor?: StoreTransitionDescriptor,
  ): StateCreator<T, Mps, Mcs> {
    // No wrapper, action replacement, subscription, clock, or capture on the disabled path.
    if (!enabled) return creator
    return ((set, get, api) => {
      if (admissions >= 100) {
        snapshot = Object.freeze({ ...snapshot, omittedRegistrations: snapshot.omittedRegistrations + 1 })
        notify()
        return creator(set, get, api)
      }
      admissions++
      const instanceId = nextInstanceId++
      const sensitive = /auth/i.test(name)
      const label = name.slice(0, MAX_CAPTURE_KEY_LENGTH)
      const capture = (state: unknown) => captureState(state, sensitive)
      let active = false
      // Zustand notifies in subscription order, so capture before application listeners.
      const unsubscribe = api.subscribe((afterState, beforeState) => {
        if (!active) return
        try {
          const before = capture(beforeState)
          const after = capture(afterState)
          let changedKeys: string[] = []
          try {
            const beforeRecord = (beforeState ?? {}) as Record<string, unknown>
            const afterRecord = (afterState ?? {}) as Record<string, unknown>
            changedKeys = [...new Set([...Object.keys(beforeRecord), ...Object.keys(afterRecord)])]
              .filter(key => key.length <= MAX_CAPTURE_KEY_LENGTH
                && !Object.is(Object.getOwnPropertyDescriptor(beforeRecord, key)?.value,
                  Object.getOwnPropertyDescriptor(afterRecord, key)?.value))
              .slice(0, 60)
          } catch { /* An unavailable key list must not hide an unavailable capture. */ }
          append({ schemaVersion: 2, instanceId, id: nextId++, parentId, store: label, kind: 'update', action: 'setState',
            timestamp: Date.now(), stack: captureStack(), before, after, changedKeys })
        } catch { /* Recorder failures must not interrupt application subscribers. */ }
      })
      let state: T
      try {
        state = creator(set, get, api)
      } catch (error) {
        unsubscribe()
        admissions--
        throw error
      }
      const originalActions = Object.entries(state as Record<string, unknown>)
        .filter(([, value]) => typeof value === 'function')
      const trustedActions = new Map<string, unknown>()
      if (descriptor) {
        transitions.set(instanceId, descriptor)
        for (const [key, value] of originalActions) {
          if (descriptor.actions.includes(key) && originalActions.filter(([, other]) => other === value).length === 1) {
            trustedActions.set(key, value)
          }
        }
      }
      const actions = new Map<string, { original: unknown; wrapped: (this: unknown, ...args: unknown[]) => unknown }>()
      const wrapActions = (source: T): T => {
        const wrapped = { ...source }
        for (const [key, value] of Object.entries(source as Record<string, unknown>)) {
          if (typeof value !== 'function') continue
          const actionLabel = key.length > MAX_CAPTURE_KEY_LENGTH ? `${key.slice(0, MAX_CAPTURE_KEY_LENGTH - 12)}…[truncated]` : key
          let action = actions.get(key)
          if (!action || action.original !== value) {
            action = { original: value, wrapped: function (this: unknown, ...args: unknown[]) {
              if (!active) return Reflect.apply(value, this, args)
              const id = nextId++
              const previous = parentId
              const transition = trustedActions.get(key) === value ? transitions.get(instanceId) : undefined
              append({ schemaVersion: 2, instanceId, id, parentId: previous, store: label, kind: 'action', action: actionLabel,
                timestamp: Date.now(), stack: captureStack(),
                args: sensitive ? { value: '[redacted]', complete: false } : captureState(args),
                ...(transition ? { transition: { id: transition.id, version: transition.version }, before: capture(get()) } : {}) })
              parentId = id
              const settled = (kind: 'resolved' | 'rejected', synchronous = false) => {
                if (active) append({ schemaVersion: 2, instanceId, id: nextId++, parentId: id,
                  store: label, kind, action: actionLabel, timestamp: Date.now(),
                  ...(transition && synchronous ? { after: capture(get()), completion: 'synchronous' as const } : {}) })
              }
              try {
                const result = Reflect.apply(value, this, args)
                let asynchronous = false
                try {
                  asynchronous = result !== null && (typeof result === 'object' || typeof result === 'function')
                    && typeof result.then === 'function'
                } catch { asynchronous = true }
                if (result instanceof Promise) {
                  try { Promise.prototype.then.call(result, () => settled('resolved'), () => settled('rejected')) }
                  catch { /* Observing a result must not change the action's return value. */ }
                } else settled('resolved', !asynchronous)
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
      active = true
      retirements.set(instanceId, () => {
        active = false
        transitions.delete(instanceId)
        unsubscribe()
        admissions--
        append({ schemaVersion: 2, instanceId, id: nextId++, parentId, store: label,
          kind: 'retire', action: 'unregisterStore', timestamp: Date.now() })
      })
      append({ schemaVersion: 2, instanceId, id: nextId++, parentId, store: label,
        kind: 'register', action: 'registerStore', timestamp: Date.now(), after: capture(wrapped) })
      return wrapped
    }) as StateCreator<T, Mps, Mcs>
  }
  return {
    enabled,
    instrument,
    getSnapshot: () => snapshot,
    verifyAction: (actionId: number) => verifyTransition(snapshot, actionId, instanceId => transitions.get(instanceId)),
    subscribe(listener: () => void) {
      if (!enabled) return () => {}
      listeners.add(listener)
      return () => { listeners.delete(listener) }
    },
    unregisterStore(instanceId: number) {
      const retire = retirements.get(instanceId)
      if (!retire) return
      retirements.delete(instanceId)
      retire()
    },
    clear() {
      snapshot = freezeCapture({ ...snapshot, events: [], droppedEvents: 0,
        baseline: { epoch: snapshot.baseline.epoch + 1, boundaryEventId: nextId - 1 || null, stores: snapshot.stores } })
      notify()
    },
    exportTrace() {
      return JSON.stringify({ purpose: 'diagnostic; side-effect replay is not supported',
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
