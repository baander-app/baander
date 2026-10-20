import { describe, expect, it, vi } from 'vitest'
import { createStore } from 'zustand/vanilla'
import { createStoreDebugger, type StoreDebugSnapshot, type StoreCaptures } from '../debug'
import { reconstructCaptures } from '../reconstruct-captures'

function at(snapshot: StoreDebugSnapshot, id: number): StoreCaptures {
  const result = reconstructCaptures(snapshot, id)
  expect(result.status).toBe('available')
  if (result.status !== 'available') throw new Error('Expected retained history')
  return result.stores
}

describe('captured store reconstruction', () => {
  it('folds interleaved duplicate instances through lifecycle, action and completion points', () => {
    const recorder = createStoreDebugger(true)
    const first = createStore(recorder.instrument<{ count: number; increment: () => void }>('selection', set => ({ count: 0, increment: () => set({ count: 1 }) })))
    const firstRegistration = recorder.getSnapshot().events.at(-1)!
    first.getState().increment()
    const [action, update, completion] = recorder.getSnapshot().events.slice(-3)
    const second = createStore(recorder.instrument('selection', () => ({ count: 10 })))
    const secondRegistration = recorder.getSnapshot().events.at(-1)!
    second.setState({ count: 11 })
    recorder.unregisterStore(firstRegistration.instanceId)
    const retirement = recorder.getSnapshot().events.at(-1)!
    const snapshot = recorder.getSnapshot()
    const original = { name: 'selection', capture: { value: { count: 0 }, complete: true }, sourceEventId: firstRegistration.id }
    const changed = { name: 'selection', capture: { value: { count: 1 }, complete: true }, sourceEventId: update.id }
    expect(at(snapshot, firstRegistration.id)).toEqual({ [firstRegistration.instanceId]: original })
    expect(at(snapshot, action.id)).toEqual({ [firstRegistration.instanceId]: original })
    expect(at(snapshot, update.id)).toEqual({ [firstRegistration.instanceId]: changed })
    expect(at(snapshot, completion.id)).toEqual({ [firstRegistration.instanceId]: changed })
    expect(at(snapshot, secondRegistration.id)).toEqual({
      [firstRegistration.instanceId]: changed,
      [secondRegistration.instanceId]: { name: 'selection', capture: { value: { count: 10 }, complete: true }, sourceEventId: secondRegistration.id },
    })
    expect(at(snapshot, retirement.id)).toEqual({
      [secondRegistration.instanceId]: { name: 'selection', capture: { value: { count: 11 }, complete: true }, sourceEventId: retirement.id - 1 },
    })
  })

  it('uses the eviction baseline and starts a new clear epoch without resetting identities', () => {
    const recorder = createStoreDebugger(true, 2)
    const first = createStore(recorder.instrument('first', () => ({ count: 0 })))
    const second = createStore(recorder.instrument('second', () => ({ count: 10 })))
    first.setState({ count: 1 })
    second.setState({ count: 11 })
    const beforeClear = recorder.getSnapshot()
    const oldest = beforeClear.events[0]
    expect(at(beforeClear, oldest.id)).toEqual({
      1: { name: 'first', capture: { value: { count: 1 }, complete: true }, sourceEventId: oldest.id },
      2: { name: 'second', capture: { value: { count: 10 }, complete: true }, sourceEventId: 2 },
    })
    expect(reconstructCaptures(beforeClear, 1)).toEqual({ status: 'unavailable' })
    recorder.clear()
    const cleared = recorder.getSnapshot()
    expect(cleared.baseline).toEqual({ epoch: 2, boundaryEventId: 4, stores: beforeClear.stores })
    expect(cleared.droppedEvents).toBe(0)
    first.setState({ count: 2 })
    const afterClear = recorder.getSnapshot()
    expect(afterClear.events[0].id).toBe(5)
    expect(at(afterClear, 5)).toEqual({
      1: { name: 'first', capture: { value: { count: 2 }, complete: true }, sourceEventId: 5 },
      2: { name: 'second', capture: { value: { count: 11 }, complete: true }, sourceEventId: 4 },
    })
    expect(at(beforeClear, oldest.id)[2].capture.value).toEqual({ count: 10 })
  })

  it('preserves incomplete markers and omissions without reading newer current captures', () => {
    const recorder = createStoreDebugger(true)
    const store = createStore(recorder.instrument('safe', () => ({ password: 'secret', list: Array.from({ length: 80 }, () => 1) })))
    const selected = recorder.getSnapshot().events.at(-1)!.id
    store.setState({ password: 'newer' })
    const snapshot = { ...recorder.getSnapshot(), omittedRegistrations: 1 }
    Object.defineProperty(snapshot, 'stores', { get: () => { throw new Error('Live registry read') } })
    const result = reconstructCaptures(snapshot, selected)
    expect(result.status).toBe('available')
    if (result.status !== 'available') return
    expect(result.partial).toBe(true)
    expect(result.stores[1].capture).toEqual({ value: { password: '[redacted]', list: Array(50).fill(1) }, complete: false })
    expect(result.stores[1].sourceEventId).toBe(selected)
    const complete = createStoreDebugger(true)
    createStore(complete.instrument('complete', () => ({ count: 1 })))
    expect(reconstructCaptures({ ...complete.getSnapshot(), omittedRegistrations: 1 }, 1)).toMatchObject({ partial: true })
  })

  it('invalidates a missing update capture, rejects unsupported history and detaches results', () => {
    const recorder = createStoreDebugger(true)
    createStore(recorder.instrument('counter', () => ({ nested: { count: 0 } })))
    const mutable: StoreDebugSnapshot = JSON.parse(recorder.exportTrace())
    const result = at(mutable, 1)
    expect(result[1]).not.toBe(mutable.events[0].after)
    expect(result[1].capture).not.toBe(mutable.events[0].after)
    expect(Object.isFrozen(result[1].capture.value)).toBe(true)
    expect(Object.isFrozen((result[1].capture.value as { nested: object }).nested)).toBe(true)
    mutable.events[0].after!.value = { changed: true }
    expect(result[1].capture.value).toEqual({ nested: { count: 0 } })
    expect(() => { (result[1].capture.value as { changed: boolean }).changed = true }).toThrow()
    expect(reconstructCaptures({ ...mutable, schemaVersion: 1 } as unknown as StoreDebugSnapshot, 1)).toEqual({ status: 'unavailable' })
    expect(reconstructCaptures(mutable, 999)).toEqual({ status: 'unavailable' })
    const missing: StoreDebugSnapshot = { ...mutable, events: [...mutable.events, {
      schemaVersion: 2, instanceId: 1, id: 2, parentId: null, store: 'counter', kind: 'update', action: 'setState', timestamp: 0,
    }] }
    expect(at(missing, 2)[1]).toEqual({ name: 'counter', capture: { value: '[unavailable]', complete: false }, sourceEventId: 2 })
  })

  it('invokes no actions, subscriptions, setters or clocks while reconstructing', () => {
    const recorder = createStoreDebugger(true)
    const effect = vi.fn()
    const store = createStore(recorder.instrument('counter', set => ({ count: 0, act: () => { effect(); set({ count: 1 }) } })))
    const snapshot = recorder.getSnapshot()
    const state = store.getState()
    const listener = vi.fn()
    store.subscribe(listener)
    const clock = vi.spyOn(Date, 'now')
    try {
      expect(reconstructCaptures(snapshot, snapshot.events[0].id)).toMatchObject({ status: 'available', partial: false })
      expect(clock).not.toHaveBeenCalled()
      expect(effect).not.toHaveBeenCalled()
      expect(listener).not.toHaveBeenCalled()
      expect(store.getState()).toBe(state)
      expect(recorder.getSnapshot()).toBe(snapshot)
    } finally {
      clock.mockRestore()
    }
  })
})
