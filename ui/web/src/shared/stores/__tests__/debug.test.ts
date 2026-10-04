import { describe, expect, it, vi } from 'vitest'
import { createStore, type StateCreator } from 'zustand/vanilla'
import { persist } from 'zustand/middleware'
import { createStoreDebugger, captureState } from '../debug'
import { createSelectiveJSONStorage } from '../persistence'

describe('store tracing', () => {
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
    expect(recorder.instrument('counter', creator)).toBe(creator)
    const store = createStore(recorder.instrument('counter', creator))
    expect(store.getState().action).toBe(action)
    const listener = vi.fn()
    recorder.subscribe(listener)
    for (let i = 1; i <= 1000; i++) store.setState({ count: i })
    expect(recorder.getSnapshot()).toEqual({ events: [], stores: {}, droppedEvents: 0 })
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
    expect(recorder.getSnapshot().droppedEvents).toBe(5)
    expect(captureState(Array.from({ length: 100 }, (_, index) => index)).complete).toBe(false)
    expect(JSON.parse(recorder.exportTrace()).schemaVersion).toBe(1)
    recorder.clear()
    store.setState({ count: 9 })
    expect(recorder.getSnapshot().events[0].id).toBe(9)
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
    expect(JSON.parse(exported).stores.large.complete).toBe(false)
  })

  it('bounds changed-key lists for many-field updates and marks exported snapshots incomplete', () => {
    const initial = Object.fromEntries(Array.from({ length: 1000 }, (_, index) => [`field${index}`, 0]))
    const changed = Object.fromEntries(Object.keys(initial).map(key => [key, 1]))
    const recorder = createStoreDebugger(true)
    const store = createStore(recorder.instrument('many', () => initial))
    store.setState(changed)
    const exported = JSON.parse(recorder.exportTrace())
    expect(exported.events[0].changedKeys).toHaveLength(60)
    expect(exported.events[0].changedKeys).toEqual(Object.keys(initial).slice(0, 60))
    expect(exported.events[0].before.complete).toBe(false)
    expect(exported.events[0].after.complete).toBe(false)
  })

  it('traces oversized method names with bounded, explicitly truncated labels', () => {
    const key = 'method'.repeat(200_000)
    const recorder = createStoreDebugger(true)
    const method = vi.fn(() => 3)
    const store = createStore(recorder.instrument('method', () => ({ [key]: method })))
    expect(store.getState()[key]()).toBe(3)
    expect(method).toHaveBeenCalledOnce()
    const events = recorder.getSnapshot().events
    expect(events.map(event => event.kind)).toEqual(['action', 'resolved'])
    expect(events.every(event => event.action.length <= 512 && event.action.endsWith('…[truncated]'))).toBe(true)
    expect(events.every(event => !event.stack || event.stack.length <= 4096)).toBe(true)
    expect(recorder.exportTrace().includes(key)).toBe(false)
    expect(recorder.exportTrace().length).toBeLessThan(10_000)
  })

})
