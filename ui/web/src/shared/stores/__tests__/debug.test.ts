import { describe, expect, it, vi } from 'vitest'
import { createStore, type StateCreator } from 'zustand/vanilla'
import { persist } from 'zustand/middleware'
import { createStoreDebugger, captureState, type StoreTransitionDescriptor } from '../debug'
import { createSelectiveJSONStorage } from '../persistence'

const counterTransition: StoreTransitionDescriptor = {
  id: 'counter',
  version: 1,
  actions: ['add', 'noop', 'run'],
  validateState: value => value !== null && typeof value === 'object' && !Array.isArray(value)
    && typeof value.count === 'number',
  validateArgs: (_action, args) => Array.isArray(args) && args.length === 0,
  calculate: state => state,
}

describe('store tracing', () => {
  it('verifies without changing live state or history and releases retired transitions', () => {
    const recorder = createStoreDebugger(true)
    const store = createStore(recorder.instrument('counter', () => ({ count: 0, noop: () => {} }), counterTransition))
    store.getState().noop()
    const actionId = recorder.getSnapshot().events.find(event => event.kind === 'action')!.id
    const state = store.getState()
    const snapshot = recorder.getSnapshot()
    const listener = vi.fn()
    const unsubscribeStore = store.subscribe(listener)
    const unsubscribeRecorder = recorder.subscribe(listener)
    expect(recorder.verifyAction(actionId).status).toBe('match')
    expect(recorder.verifyAction(actionId).status).toBe('match')
    expect(store.getState()).toBe(state)
    expect(recorder.getSnapshot()).toBe(snapshot)
    expect(listener).not.toHaveBeenCalled()
    unsubscribeStore()
    unsubscribeRecorder()
    recorder.unregisterStore(1)
    expect(recorder.verifyAction(actionId).status).toBe('unavailable')
  })

  it('captures trusted synchronous invocation boundaries including no-op actions after clear', () => {
    const recorder = createStoreDebugger(true)
    const store = createStore(recorder.instrument<{ count: number; add: () => void; noop: () => void }>('counter', set => ({
      count: 0,
      add: () => set({ count: 1 }),
      noop: () => {},
    }), counterTransition))
    const action = store.getState().add
    const initial = store.getInitialState()
    store.getState().add()
    expect(recorder.getSnapshot().events.slice(1)).toMatchObject([
      { kind: 'action', before: { value: { count: 0 }, complete: true }, transition: { id: 'counter', version: 1 } },
      { kind: 'update' },
      { kind: 'resolved', after: { value: { count: 1 }, complete: true }, completion: 'synchronous' },
    ])
    expect(store.getState().add).toBe(action)
    expect(store.getInitialState()).toBe(initial)
    recorder.clear()
    store.getState().noop()
    expect(recorder.getSnapshot().events).toMatchObject([
      { kind: 'action', before: { value: { count: 1 }, complete: true }, transition: { id: 'counter', version: 1 } },
      { kind: 'resolved', after: { value: { count: 1 }, complete: true }, completion: 'synchronous' },
    ])
  })

  it('does not grant trust to aliased originals or differing initial-state actions', () => {
    const recorder = createStoreDebugger(true)
    const run = () => {}
    const aliased = createStore(recorder.instrument('aliased', () => ({ count: 0, run, alias: run }), counterTransition))
    aliased.getState().run()
    const store = createStore(recorder.instrument<{ count: number; run: () => void }>('initial', (_set, _get, api) => {
      api.getInitialState = () => ({ count: 0, run: () => {} })
      return { count: 0, run: () => {} }
    }, counterTransition))
    store.getInitialState().run()
    expect(recorder.getSnapshot().events.filter(event => event.kind === 'action')
      .every(event => event.transition === undefined && event.before === undefined)).toBe(true)
    store.getState().run()
    expect(recorder.getSnapshot().events.at(-2)).toMatchObject({
      kind: 'action', transition: { id: 'counter', version: 1 },
    })
  })

  it('withholds synchronous success for promises, thenables and thrown actions without changing returns', async () => {
    const recorder = createStoreDebugger(true)
    const pending = Promise.resolve(3)
    const thenable = { then: vi.fn() }
    const readThen = vi.fn(() => { throw new Error('then unavailable') })
    const throwingThen = Object.defineProperty({}, 'then', { get: readThen })
    const failure = new Error('action failed')
    for (const result of [pending, thenable, throwingThen]) {
      const store = createStore(recorder.instrument('counter', () => ({ count: 0, run: () => result }), counterTransition))
      expect(store.getState().run()).toBe(result)
    }
    const store = createStore(recorder.instrument('counter', () => ({ count: 0, run: () => { throw failure } }), counterTransition))
    expect(() => store.getState().run()).toThrow(failure)
    await pending
    const terminals = recorder.getSnapshot().events.filter(event => event.kind === 'resolved' || event.kind === 'rejected')
    expect(terminals.length).toBeGreaterThan(0)
    expect(terminals.every(event => event.completion === undefined && event.after === undefined)).toBe(true)
    expect(thenable.then).not.toHaveBeenCalled()
    expect(readThen).not.toHaveBeenCalled()
  })

  it('does not trust replaced actions or capture incomplete data as complete', () => {
    const recorder = createStoreDebugger(true)
    const store = createStore(recorder.instrument('counter', () => ({ count: 0, run: () => {} }), counterTransition))
    const replacement = () => {}
    store.setState({ run: replacement })
    store.getState().run()
    expect(recorder.getSnapshot().events.filter(event => event.kind === 'action')).toHaveLength(0)
    recorder.unregisterStore(1)
    const retired = recorder.getSnapshot()
    store.getInitialState().run()
    expect(recorder.getSnapshot()).toBe(retired)
    const sensitive = createStore(recorder.instrument('auth.counter', () => ({ count: 0, run: () => {} }), counterTransition))
    sensitive.getState().run()
    const invocation = recorder.getSnapshot().events.find(event => event.kind === 'action')!
    expect(invocation.before?.complete).toBe(false)
    expect(invocation.args?.complete).toBe(false)
  })

  it('records outer mutations before same-store and cross-store subscriber writes', () => {
    const recorder = createStoreDebugger(true)
    const other = createStore(recorder.instrument('other', () => ({ count: 0 })))
    const store = createStore(recorder.instrument<{ count: number }>('counter', (_set, _get, api) => {
      api.subscribe(state => {
        if (state.count === 1) {
          api.setState({ count: 2 })
          other.setState({ count: 3 })
        }
      })
      return { count: 0 }
    }))
    store.setState({ count: 1 })
    const updates = recorder.getSnapshot().events.filter(event => event.kind === 'update')
    expect(updates.map(event => [event.store, event.before?.value, event.after?.value])).toEqual([
      ['counter', { count: 0 }, { count: 1 }],
      ['counter', { count: 1 }, { count: 2 }],
      ['other', { count: 0 }, { count: 3 }],
    ])
  })

  it('folds evicted registrations and updates into the baseline before the retained window', () => {
    const recorder = createStoreDebugger(true, 2)
    const store = createStore(recorder.instrument('counter', () => ({ count: 0 })))
    store.setState({ count: 1 })
    store.setState({ count: 2 })
    store.setState({ count: 3 })
    expect(recorder.getSnapshot().baseline).toEqual({
      epoch: 1, boundaryEventId: 2,
      stores: { 1: { name: 'counter', capture: { value: { count: 1 }, complete: true }, sourceEventId: 2 } },
    })
  })

  it('registers duplicate names after construction and preserves no-op notification behavior', () => {
    const recorder = createStoreDebugger(true)
    const first = createStore(recorder.instrument('same', (set) => {
      set({ count: 99 })
      return { count: 0 }
    }))
    const second = createStore(recorder.instrument('same', () => ({ count: 5 })))
    const initial = recorder.getSnapshot()
    expect(initial.events.map(event => [event.kind, event.instanceId])).toEqual([['register', 1], ['register', 2]])
    expect(initial.stores[1].capture.value).toEqual({ count: 0 })
    expect(initial.stores[2].capture.value).toEqual({ count: 5 })
    first.setState(first.getState())
    expect(recorder.getSnapshot()).toBe(initial)
    second.setState({ count: 6 })
    expect(initial.stores[2].capture.value).toEqual({ count: 5 })
    expect(Object.isFrozen(recorder.getSnapshot().stores[2].capture.value)).toBe(true)
  })

  it('releases failed construction subscriptions and reserves admission before recursive creators', () => {
    const recorder = createStoreDebugger(true)
    const unsubscribed = vi.fn()
    const failure = new Error('creator failed')
    const failed = recorder.instrument<{ count: number }>('failed', () => { throw failure })
    expect(() => createStore<{ count: number }>((set, get, api) => {
      const subscribe = api.subscribe
      api.subscribe = listener => {
        const release = subscribe(listener)
        return () => { unsubscribed(); release() }
      }
      return failed(set, get, api)
    })).toThrow(failure)
    expect(unsubscribed).toHaveBeenCalledOnce()
    expect(recorder.getSnapshot().events).toHaveLength(0)
    // A nested creator sees the outer provisional admission.
    const action = vi.fn()
    for (let index = 0; index < 99; index++) createStore(recorder.instrument('active', () => ({ count: index })))
    let omittedAction: (() => void) | undefined
    createStore(recorder.instrument('last', () => {
      omittedAction = createStore(recorder.instrument('omitted', () => ({ action }))).getState().action
      return { count: 100 }
    }))
    expect(omittedAction).toBe(action)
    expect(Object.keys(recorder.getSnapshot().stores)).toHaveLength(100)
    expect(recorder.getSnapshot().omittedRegistrations).toBe(1)
  })

  it('retires idempotently, stops actions and pending completions and admits a replacement', async () => {
    const recorder = createStoreDebugger(true, 2)
    let finish!: () => void
    const pending = new Promise<void>(resolve => { finish = resolve })
    const action = vi.fn(() => pending)
    const first = createStore(recorder.instrument('temporary', () => ({ count: 0, action })))
    const firstId = recorder.getSnapshot().events[0].instanceId
    for (let index = 1; index < 100; index++) createStore(recorder.instrument('active', () => ({ count: index })))
    first.getState().action()
    recorder.unregisterStore(firstId)
    const retired = recorder.getSnapshot()
    recorder.unregisterStore(firstId)
    first.setState({ count: 3 })
    expect(first.getState().action()).toBe(pending)
    finish()
    await pending
    expect(recorder.getSnapshot()).toBe(retired)
    expect(retired.stores[firstId]).toBeUndefined()
    expect(retired.events.at(-1)?.kind).toBe('retire')
    createStore(recorder.instrument('replacement', () => ({ count: 101 })))
    expect(Object.keys(recorder.getSnapshot().stores)).toHaveLength(100)
    expect(recorder.getSnapshot().omittedRegistrations).toBe(0)
    // Evict retirement as well: the old identity leaves the baseline.
    const replacementId = recorder.getSnapshot().events.at(-1)!.instanceId
    recorder.unregisterStore(replacementId)
    createStore(recorder.instrument('next', () => ({ count: 102 })))
    expect(recorder.getSnapshot().baseline.stores[firstId]).toBeUndefined()
  })

  it('isolates recorder listeners and changed-key inspection from application mutations', () => {
    const recorder = createStoreDebugger(true)
    recorder.subscribe(() => { throw new Error('debug listener') })
    const store = createStore(recorder.instrument('safe', () => ({ count: 0 })))
    const appListener = vi.fn()
    store.subscribe(appListener)
    expect(() => store.setState({ count: 1 })).not.toThrow()
    expect(appListener).toHaveBeenCalledOnce()
    const getter = vi.fn(() => { throw new Error('getter') })
    const state = Object.defineProperty({ count: 2 }, 'unsafe', { enumerable: true, get: getter })
    expect(() => store.setState(state, true)).not.toThrow()
    expect(getter).not.toHaveBeenCalled()
    expect(recorder.getSnapshot().events.at(-1)?.after?.complete).toBe(false)
    const inaccessible = new Proxy({ count: 3 }, { ownKeys() { throw new Error('keys unavailable') } })
    expect(() => store.setState(inaccessible, true)).not.toThrow()
    expect(recorder.getSnapshot().events.at(-1)?.after).toEqual({ value: '[unavailable]', complete: false })
    expect(recorder.getSnapshot().stores[1].capture).toEqual({ value: '[unavailable]', complete: false })
  })

  it('bounds display names while checking the full raw name for sensitive state', () => {
    const recorder = createStoreDebugger(true)
    createStore(recorder.instrument('x'.repeat(600) + 'auth', () => ({ email: 'private@baander.app' })))
    const entry = recorder.getSnapshot().stores[1]
    expect(entry.name).toHaveLength(512)
    expect(entry.capture).toEqual({ value: { email: '[redacted]' }, complete: false })
    expect(recorder.exportTrace()).not.toContain('private@')
  })

  it('cannot be enabled by a saved preference in production builds', async () => {
    localStorage.setItem('baander-store-debug', 'true')
    vi.stubEnv('DEV', false)
    vi.resetModules()
    try {
      const module = await import('../debug')
      expect(module.storeDebugger.enabled).toBe(false)
      const creator = () => ({ count: 0 })
      expect(module.withStoreDebug('production', creator)).toBe(creator)
    } finally {
      localStorage.removeItem('baander-store-debug')
      vi.unstubAllEnvs()
      vi.resetModules()
    }
  })

  it('returns the exact creator when disabled and never wraps actions or captures updates', () => {
    const recorder = createStoreDebugger(false)
    const action = vi.fn()
    const creator: StateCreator<{ count: number; action: () => void }> = () => ({ count: 0, action })
    const descriptor = new Proxy(counterTransition, { get() { throw new Error('descriptor consulted') } })
    expect(recorder.instrument('counter', creator, descriptor)).toBe(creator)
    const store = createStore(recorder.instrument('counter', creator))
    expect(store.getState().action).toBe(action)
    const listener = vi.fn()
    recorder.subscribe(listener)
    for (let i = 1; i <= 1000; i++) store.setState({ count: i })
    expect(recorder.getSnapshot()).toEqual({ schemaVersion: 2, events: [], stores: {}, droppedEvents: 0,
      baseline: { epoch: 1, boundaryEventId: null, stores: {} }, omittedRegistrations: 0 })
    expect(listener).not.toHaveBeenCalled()
  })

  it('captures immutable before/after state, ordered nested calls and native setState', () => {
    const recorder = createStoreDebugger(true)
    const store = createStore(recorder.instrument<{ count: number; add: () => void; twice: () => void }>('counter', (set, get) => ({
      count: 0,
      add: () => set({ count: get().count + 1 }),
      twice: () => { get().add(); get().add() },
    })))
    store.getState().twice()
    const events = recorder.getSnapshot().events
    const outer = events.find(event => event.action === 'twice' && event.kind === 'action')!
    const nested = events.filter(event => event.action === 'add' && event.kind === 'action')
    expect(nested).toHaveLength(2)
    expect(nested.every(event => event.parentId === outer.id)).toBe(true)
    const changes = events.filter(event => event.kind === 'update')
    expect(changes.map(event => event.after?.value)).toEqual([{ count: 1 }, { count: 2 }])
    expect(changes[0].parentId).toBe(nested[0].id)
    expect(changes[0].stack).toContain('debug.test')
    expect(Object.isFrozen(changes[0].after?.value)).toBe(true)
    store.setState({ count: 3 })
    expect(recorder.getSnapshot().events.at(-1)?.parentId).toBeNull()
    expect(changes[0].after?.value).toEqual({ count: 1 })
    expect(events.map(event => event.id)).toEqual([...events.map(event => event.id)].sort((a, b) => a - b))
  })

  it('preserves async promise identity and errors without guessing parentage after await', async () => {
    const recorder = createStoreDebugger(true)
    let finish!: () => void
    const pending = new Promise<void>(resolve => { finish = resolve })
    const store = createStore(recorder.instrument('async', () => ({ run: () => pending })))
    expect(store.getState().run()).toBe(pending)
    finish()
    await pending
    expect(recorder.getSnapshot().events.at(-1)?.kind).toBe('resolved')
    const failure = new Error('private detail')
    const failed = createStore(recorder.instrument('failed', () => ({ run: () => Promise.reject(failure) })))
    await expect(failed.getState().run()).rejects.toBe(failure)
    expect(recorder.getSnapshot().events.at(-1)?.kind).toBe('rejected')
    expect(recorder.exportTrace()).not.toContain('private detail')
  })

  it('redacts authentication arguments/state and never invokes object accessors', () => {
    const recorder = createStoreDebugger(true)
    const store = createStore(recorder.instrument('auth', () => ({
      accessToken: 'secret-token', user: { email: 'private@baander.app' }, isAuthenticated: false,
      login: vi.fn<(email: string, password: string) => void>(),
    })))
    store.getState().login('private@baander.app', 'private-password')
    expect(recorder.exportTrace()).not.toMatch(/secret-token|private@|private-password/)
    const getter = vi.fn(() => 'secret')
    const value = Object.defineProperty({}, 'unsafe', { enumerable: true, get: getter })
    expect(captureState(value)).toEqual({ value: { unsafe: '[accessor]' }, complete: false })
    const array = Object.defineProperty([], '0', { enumerable: true, get: getter })
    expect(captureState(array)).toEqual({ value: ['[accessor or empty slot]'], complete: false })
    expect(getter).not.toHaveBeenCalled()
  })

  it('bounds retained history and marks incomplete captures for future replay', () => {
    const recorder = createStoreDebugger(true, 3)
    const store = createStore(recorder.instrument('counter', () => ({ count: 0 })))
    for (let count = 1; count <= 8; count++) store.setState({ count })
    expect(recorder.getSnapshot().events).toHaveLength(3)
    expect(recorder.getSnapshot().droppedEvents).toBe(6)
    expect(captureState(Array.from({ length: 100 }, (_, index) => index)).complete).toBe(false)
    expect(JSON.parse(recorder.exportTrace()).schemaVersion).toBe(2)
    recorder.clear()
    store.setState({ count: 9 })
    expect(recorder.getSnapshot().events[0].id).toBe(10)
  })

  it('composes with persistence without duplicate update records or changed middleware APIs', () => {
    const recorder = createStoreDebugger(true)
    const storage = createSelectiveJSONStorage<{ count: number }>(() => ({ getItem: () => null, setItem: vi.fn(), removeItem: vi.fn() }))
    const store = createStore(recorder.instrument('persisted', persist<{ count: number; increment: () => void }, [], [], { count: number }>(set => ({
      count: 0, increment: () => set(state => ({ count: state.count + 1 })),
    }), { name: 'counter', storage, partialize: state => ({ count: state.count }) })))
    recorder.clear()
    store.getState().increment()
    expect(recorder.getSnapshot().events.map(event => event.kind)).toEqual(['action', 'update', 'resolved'])
    store.setState({ count: 4 })
    expect(recorder.getSnapshot().events.filter(event => event.kind === 'update')).toHaveLength(2)
    expect(store.persist.hasHydrated()).toBe(true)
  })
  it('records later persistence hydration while keeping creator defaults and action identities', async () => {
    const recorder = createStoreDebugger(true)
    let persistedCount = 7
    const storage = createSelectiveJSONStorage<{ count: number }>(() => ({
      getItem: () => JSON.stringify({ state: { count: persistedCount }, version: 0 }),
      setItem: vi.fn(), removeItem: vi.fn(),
    }))
    const store = createStore(recorder.instrument('hydrate', persist<{ count: number; increment: () => void }, [], [], { count: number }>(set => ({
      count: 0, increment: () => set(state => ({ count: state.count + 1 })),
    }), { name: 'hydrate', storage, partialize: state => ({ count: state.count }) })))
    const initial = store.getInitialState()
    const action = store.getState().increment
    expect(initial.count).toBe(0)
    expect(initial.increment).toBe(action)
    expect(recorder.getSnapshot().events.map(event => event.kind)).toEqual(['register'])
    expect(recorder.getSnapshot().stores[1].capture.value).toEqual({ count: 7 })
    persistedCount = 9
    await store.persist.rehydrate()
    expect(store.getState().count).toBe(9)
    expect(store.getState().increment).toBe(action)
    expect(store.getInitialState()).toBe(initial)
    expect(recorder.getSnapshot().events.at(-1)).toMatchObject({
      kind: 'update', before: { value: { count: 7 } }, after: { value: { count: 9 } },
    })
  })

  it('keeps reset defaults and stable initial snapshot identity while tracing actions after persisted resets', () => {
    const recorder = createStoreDebugger(true)
    const storage = createSelectiveJSONStorage<{ count: number }>(() => ({
      getItem: () => JSON.stringify({ state: { count: 7 }, version: 0 }), setItem: vi.fn(), removeItem: vi.fn(),
    }))
    const store = createStore(recorder.instrument('reset', persist<{ count: number; increment: () => void }, [], [], { count: number }>(set => ({
      count: 0, increment: () => set(state => ({ count: state.count + 1 })),
    }), { name: 'reset', storage, partialize: state => ({ count: state.count }) })))
    expect(store.getState().count).toBe(7)
    const initial = store.getInitialState()
    expect(initial.count).toBe(0)
    expect(store.getInitialState()).toBe(initial)
    for (let reset = 0; reset < 2; reset++) {
      store.setState(initial, true)
      recorder.clear()
      store.getState().increment()
      const events = recorder.getSnapshot().events
      expect(events.map(event => event.kind)).toEqual(['action', 'update', 'resolved'])
      expect(events[1].parentId).toBe(events[0].id)
      expect(events[2].parentId).toBe(events[0].id)
      expect(store.getState().count).toBe(1)
    }
  })

  it('omits oversized object keys from captures and exports and marks the capture incomplete', () => {
    const hugeKey = 'x'.repeat(1_000_000)
    const state = { readable: 3, [hugeKey]: 1 }
    expect(captureState(state)).toEqual({ value: { readable: 3 }, complete: false })
    const recorder = createStoreDebugger(true)
    const store = createStore(recorder.instrument('large', () => state))
    store.setState({ readable: 4, [hugeKey]: 2 })
    const exported = recorder.exportTrace()
    expect(exported).not.toContain(hugeKey)
    expect(exported.length).toBeLessThan(10_000)
    expect(JSON.parse(exported).stores[1].capture.complete).toBe(false)
  })

  it('bounds changed-key lists for many-field updates and marks exported snapshots incomplete', () => {
    const initial = Object.fromEntries(Array.from({ length: 1000 }, (_, index) => [`field${index}`, 0]))
    const changed = Object.fromEntries(Object.keys(initial).map(key => [key, 1]))
    const recorder = createStoreDebugger(true)
    const store = createStore(recorder.instrument('many', () => initial))
    store.setState(changed)
    const exported = JSON.parse(recorder.exportTrace())
    expect(exported.events[1].changedKeys).toHaveLength(60)
    expect(exported.events[1].changedKeys).toEqual(Object.keys(initial).slice(0, 60))
    expect(exported.events[1].before.complete).toBe(false)
    expect(exported.events[1].after.complete).toBe(false)
  })

  it('traces oversized method names with bounded, explicitly truncated labels', () => {
    const key = 'method'.repeat(200_000)
    const recorder = createStoreDebugger(true)
    const method = vi.fn(() => 3)
    const store = createStore(recorder.instrument('method', () => ({ [key]: method })))
    expect(store.getState()[key]()).toBe(3)
    expect(method).toHaveBeenCalledOnce()
    const events = recorder.getSnapshot().events.filter(event => event.kind !== 'register')
    expect(events.map(event => event.kind)).toEqual(['action', 'resolved'])
    expect(events.every(event => event.action.length <= 512 && event.action.endsWith('…[truncated]'))).toBe(true)
    expect(events.every(event => !event.stack || event.stack.length <= 4096)).toBe(true)
    expect(recorder.exportTrace().includes(key)).toBe(false)
    expect(recorder.exportTrace().length).toBeLessThan(10_000)
  })

})
