import { describe, expect, it, vi } from 'vitest'
import { createStore } from 'zustand/vanilla'
import { persist } from 'zustand/middleware'
import { createSelectiveJSONStorage } from '../persistence'
import { withNoopGuard } from '../with-noop-guard'

describe('no-op state guard', () => {
  it('skips cold no-op persistence and subscriber delivery; keeps real updates', () => {
    const write = vi.fn()
    const store = createStore(persist(withNoopGuard<{ count: number; setCount: (count: number) => void }>(set => ({
      count: 0, setCount: count => set({ count }),
    })), { name: 'counter', storage: createSelectiveJSONStorage(() => ({ getItem: () => null, setItem: write, removeItem: vi.fn() })),
      partialize: state => ({ count: state.count }) }))
    const listener = vi.fn()
    store.subscribe(listener)
    store.getState().setCount(0)
    store.setState(state => state)
    store.setState({ count: 0 })
    expect(write).not.toHaveBeenCalled()
    expect(listener).not.toHaveBeenCalled()
    store.getState().setCount(1)
    expect(write).toHaveBeenCalledOnce()
    expect(listener).toHaveBeenCalledOnce()
  })

  it('honors replacement deletion and evaluates updater functions once', () => {
    const store = createStore(withNoopGuard<{ count: number; extra?: boolean }>(() => ({ count: 1, extra: true })))
    const update = vi.fn(() => ({ count: 1 }))
    store.setState(update, true)
    expect(update).toHaveBeenCalledOnce()
    expect(store.getState()).toEqual({ count: 1 })
    const listener = vi.fn()
    store.subscribe(listener)
    store.setState({ count: 1 }, true)
    expect(listener).not.toHaveBeenCalled()
  })
  it('applies enumerable symbol updates and preserves symbol deletion during replacement', () => {
    const key = Symbol('clock')
    const store = createStore(withNoopGuard<{ count: number; [key]?: number }>(() => ({ count: 0, [key]: 1 })))
    const listener = vi.fn()
    store.subscribe(listener)
    store.setState({ [key]: 2 })
    expect(store.getState()[key]).toBe(2)
    expect(listener).toHaveBeenCalledOnce()
    store.setState({ [key]: 2 })
    expect(listener).toHaveBeenCalledOnce()
    store.setState({ count: 0 }, true)
    expect(Object.hasOwn(store.getState(), key)).toBe(false)
    expect(listener).toHaveBeenCalledTimes(2)
  })

})
