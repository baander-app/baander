import { describe, expect, it, vi } from 'vitest'
import { createStore } from 'zustand/vanilla'
import { persist } from 'zustand/middleware'
import { selectionTransition, calculateSelection, type CatalogItemType } from '@/features/catalog/stores/selection-transition'
import { captureState, createStoreDebugger, type DebugValue, type StoreDebugSnapshot, type StoreTraceEvent, type StoreTransitionDescriptor } from '../debug'
import { withNoopGuard } from '../with-noop-guard'
import { verifyTransition } from '../verify-transition'

const empty = { selectedId: null, selectedType: null }
const selected = { selectedId: 'album-1', selectedType: 'album' }
function event(kind: StoreTraceEvent['kind'], id: number, fields: Partial<StoreTraceEvent> = {}): StoreTraceEvent {
  return { schemaVersion: 2, instanceId: 1, id, parentId: kind === 'action' ? null : 1,
    store: 'catalog.selection', kind, action: 'select', timestamp: id, ...fields }
}
function history(): StoreDebugSnapshot {
  return { schemaVersion: 2, stores: {}, baseline: { epoch: 7, boundaryEventId: null, stores: {} },
    droppedEvents: 0, omittedRegistrations: 0, events: [
      event('action', 1, { before: captureState(empty), args: captureState(['album-1', 'album']), transition: { id: selectionTransition.id, version: 1 } }),
      event('update', 2, { before: captureState(empty), after: captureState(selected) }),
      event('resolved', 3, { after: captureState(selected), completion: 'synchronous' }),
    ] }
}
const lookup = () => selectionTransition

describe('isolated transition verification', () => {
  it('compares the real selection transition and returns bounded detached frozen values', () => {
    const snapshot = history()
    const result = verifyTransition(snapshot, 1, lookup)
    expect(result).toEqual({ status: 'match', actionId: 1, instanceId: 1, epoch: 7, expected: selected, recorded: selected })
    if (result.status === 'unavailable') throw new Error(result.reason)
    expect(result.recorded).not.toBe(snapshot.events[2].after!.value)
    expect(Object.isFrozen(result)).toBe(true)
    expect(Object.isFrozen(result.expected)).toBe(true)
    expect(Object.isFrozen(result.recorded)).toBe(true)
  })

  it('verifies the real clear transition from a selected item', () => {
    const snapshot = history()
    snapshot.events[0].action = 'clear'
    snapshot.events[0].args = captureState([])
    snapshot.events[0].before = captureState(selected)
    snapshot.events[1].before = captureState(selected)
    snapshot.events[1].after = captureState(empty)
    snapshot.events[2].action = 'clear'
    snapshot.events[2].after = captureState(empty)
    expect(verifyTransition(snapshot, 1, lookup)).toMatchObject({ status: 'match', expected: empty, recorded: empty })
  })

  it('reports a complete but different recorded result as a mismatch', () => {
    const snapshot = history()
    snapshot.events[1].after = captureState(empty)
    snapshot.events[2].after = captureState(empty)
    expect(verifyTransition(snapshot, 1, lookup)).toMatchObject({ status: 'mismatch', expected: selected, recorded: empty })
  })

  it('checks every boundary in an invocation with multiple updates', () => {
    const snapshot = history()
    snapshot.events = [
      snapshot.events[0],
      event('update', 2, { before: captureState(empty), after: captureState(empty) }),
      { ...snapshot.events[1], id: 3 },
      { ...snapshot.events[2], id: 4 },
    ]
    expect(verifyTransition(snapshot, 1, lookup).status).toBe('match')
    snapshot.events[2].before = captureState(selected)
    expect(verifyTransition(snapshot, 1, lookup)).toMatchObject({
      status: 'unavailable', reason: expect.stringContaining('chain'),
    })
  })

  it.each(['clear', 'select'] as const)('verifies a successful %s with no update', action => {
    const snapshot = history()
    const value = action === 'clear' ? empty : selected
    snapshot.events[0].action = action
    snapshot.events[0].args = captureState(action === 'clear' ? [] : ['album-1', 'album'])
    snapshot.events[0].before = captureState(value)
    snapshot.events = [snapshot.events[0], { ...snapshot.events[2], action, after: captureState(value) }]
    expect(verifyTransition(snapshot, 1, lookup).status).toBe('match')
  })

  const refusals: [string, (snapshot: StoreDebugSnapshot) => void, string][] = [
    ['older schema', s => { Object.assign(s, { schemaVersion: 1 }) }, 'schema'],
    ['evicted action', s => { s.events = s.events.slice(1) }, 'retained'],
    ['non-action selection', s => { s.events[0].kind = 'register' }, 'action'],
    ['nested selected action', s => { s.events[0].parentId = 9 }, 'nested'],
    ['old metadata', s => { delete s.events[0].transition }, 'metadata'],
    ['unknown identity', s => { s.events[0].transition!.id = 'unknown' }, 'descriptor'],
    ['unknown version', s => { s.events[0].transition!.version = 2 }, 'version'],
    ['unsupported action', s => { s.events[0].action = 'remove' }, 'supported'],
    ['invalid state', s => { s.events[0].before = captureState({ selectedId: 5, selectedType: 'album' }) }, 'state'],
    ['invalid arguments', s => { s.events[0].args = captureState(['album-1', 'invalid']) }, 'arguments'],
    ['invalid terminal state', s => { s.events[2].after = captureState({ selectedId: false, selectedType: 'album' }) }, 'state'],
    ['extra arguments', s => { s.events[0].args = captureState(['album-1', 'album', 'extra']) }, 'arguments'],
    ['incomplete before', s => { s.events[0].before = { value: empty, complete: false } }, 'complete'],
    ['missing arguments', s => { delete s.events[0].args }, 'complete'],
    ['incomplete terminal', s => { s.events[2].after = { value: selected, complete: false } }, 'complete'],
    ['missing terminal', s => { s.events = s.events.slice(0, 2) }, 'terminal'],
    ['duplicate terminal', s => { s.events = [...s.events, { ...s.events[2], id: 4 }] }, 'terminal'],
    ['rejection', s => { s.events[2].kind = 'rejected' }, 'rejected'],
    ['async completion', s => { delete s.events[2].completion }, 'synchronous'],
    ['foreign update', s => { s.events[1].instanceId = 2 }, 'update'],
    ['unattributed update', s => { s.events[1].parentId = null }, 'update'],
    ['indirect update', s => { s.events[1].parentId = 9 }, 'update'],
    ['contradictory inner terminal', s => { s.events = [s.events[0], event('resolved', 4, { parentId: 9 }), ...s.events.slice(1)] }, 'terminal'],
    ['nested action', s => { s.events = [s.events[0], event('action', 4, { parentId: 1 }), ...s.events.slice(1)] }, 'nested'],
    ['broken update start', s => { s.events[1].before = captureState(selected) }, 'chain'],
    ['broken terminal chain', s => { s.events[2].after = captureState(empty) }, 'chain'],
    ['changed no-update result', s => { s.events = [s.events[0], s.events[2]] }, 'chain'],
    ['foreign terminal', s => { s.events[2].instanceId = 2 }, 'terminal'],
    ['contradictory terminal action', s => { s.events[2].action = 'clear' }, 'terminal'],
    ['update after terminal', s => { s.events = [...s.events, event('update', 4)] }, 'boundaries'],
    ['terminal before invocation', s => { s.events = [s.events[2], s.events[0], s.events[1]] }, 'terminal'],
  ]
  it.each(refusals)('refuses %s', (_label, mutate, reason) => {
    const snapshot = history()
    mutate(snapshot)
    expect(verifyTransition(snapshot, 1, lookup)).toMatchObject({ status: 'unavailable', reason: expect.stringContaining(reason) })
  })

  it('refuses a retired descriptor and permits unrelated later activity', () => {
    const snapshot = history()
    snapshot.events = [...snapshot.events, event('update', 4, { instanceId: 2, parentId: null })]
    expect(verifyTransition(snapshot, 1, lookup).status).toBe('match')
    expect(verifyTransition(snapshot, 1, () => undefined)).toMatchObject({ status: 'unavailable', reason: expect.stringContaining('descriptor') })
  })

  it('detaches inputs, calls descriptor functions without this, and catches failures', () => {
    const snapshot = history()
    const before = snapshot.events[0].before!.value
    const args = snapshot.events[0].args!.value
    const calculate = vi.fn(function (this: unknown, state: DebugValue, _action: string, values: DebugValue) {
      expect(this).toBeUndefined()
      expect(state).not.toBe(before)
      expect(values).not.toBe(args)
      expect(Object.isFrozen(state)).toBe(true)
      expect(Object.isFrozen(values)).toBe(true)
      return selected
    })
    const descriptor: StoreTransitionDescriptor = { ...selectionTransition, calculate }
    expect(verifyTransition(snapshot, 1, () => descriptor).status).toBe('match')
    expect(calculate).toHaveBeenCalledOnce()
    descriptor.calculate = () => { throw new Error('calculator failed') }
    expect(verifyTransition(snapshot, 1, () => descriptor)).toMatchObject({ status: 'unavailable', reason: expect.stringContaining('failed') })
    expect(snapshot.events[0].before!.value).toBe(before)
    expect(snapshot.events[0].args!.value).toBe(args)
  })

  it('refuses unsafe calculation results and recaptured incomplete data', () => {
    const snapshot = history()
    const descriptor: StoreTransitionDescriptor = { ...selectionTransition, calculate: () => ({ selectedId: 'a'.repeat(600), selectedType: 'album' }) }
    expect(verifyTransition(snapshot, 1, () => descriptor).status).toBe('unavailable')
    descriptor.calculate = state => {
      Object.assign(state as object, { selectedId: 'mutated' })
      return state
    }
    expect(verifyTransition(snapshot, 1, () => descriptor).status).toBe('unavailable')
    expect(snapshot.events[0].before!.value).toEqual(empty)
    snapshot.events[0].before = { value: { selectedId: 'a'.repeat(600), selectedType: 'album' }, complete: true }
    expect(verifyTransition(snapshot, 1, lookup).status).toBe('unavailable')
  })

  it('leaves live state, notifications, persistence, history and capture references unchanged', () => {
    const recorder = createStoreDebugger(true)
    const storage = { getItem: vi.fn(() => null), setItem: vi.fn(), removeItem: vi.fn() }
    const store = createStore(recorder.instrument('catalog.selection', persist(withNoopGuard<{
      selectedId: string | null; selectedType: CatalogItemType | null
      select: (id: string, type: CatalogItemType) => void; clear: () => void
    }>(set => ({ ...empty, select: (id, type) => set(calculateSelection({ action: 'select', id, type })),
      clear: () => set(calculateSelection({ action: 'clear' })) })), { name: 'selection', storage }), selectionTransition))
    store.getState().select('album-1', 'album')
    const snapshot = recorder.getSnapshot()
    const action = snapshot.events.find(item => item.kind === 'action')!
    const state = store.getState()
    const writes = storage.setItem.mock.calls.length
    const notify = vi.fn()
    store.subscribe(notify)
    recorder.subscribe(notify)
    expect(verifyTransition(snapshot, action.id, lookup).status).toBe('match')
    expect(verifyTransition(snapshot, action.id, lookup).status).toBe('match')
    expect(store.getState()).toBe(state)
    expect(recorder.getSnapshot()).toBe(snapshot)
    expect(storage.setItem).toHaveBeenCalledTimes(writes)
    expect(notify).not.toHaveBeenCalled()
  })
})
