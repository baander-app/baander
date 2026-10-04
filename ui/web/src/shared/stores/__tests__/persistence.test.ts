import { describe, expect, it, vi } from 'vitest'
import { createStore } from 'zustand/vanilla'
import { persist } from 'zustand/middleware'
import { createSelectiveJSONStorage } from '../persistence'

describe('selective persistence', () => {
  it('does not serialize or write unchanged durable state during transient updates', () => {
    const setItem = vi.fn()
    const encode = vi.fn(() => ['song'])
    const queue: { toJSON: () => string[] } = { toJSON: encode }
    const storage = createSelectiveJSONStorage<{ queue: typeof queue }>(() => ({ getItem: () => null, setItem, removeItem: vi.fn() }))
    const store = createStore(persist(() => ({ queue, time: 0 }), { name: 'player', storage, partialize: state => ({ queue: state.queue }) }))
    store.setState({ time: 1 })
    setItem.mockClear(); encode.mockClear()
    for (let time = 2; time <= 100; time++) store.setState({ time })
    expect(setItem).not.toHaveBeenCalled()
    expect(encode).not.toHaveBeenCalled()
    store.setState({ queue: { toJSON: () => ['new-song'] } })
    expect(setItem).toHaveBeenCalledOnce()
  })

  it('hydrates, removes, and retries a failed write without caching the failure', () => {
    const setItem = vi.fn().mockImplementationOnce(() => { throw new Error('Storage full') })
    const storage = createSelectiveJSONStorage<{ volume: number }>(() => ({
      getItem: () => JSON.stringify({ state: { volume: 25 }, version: 1 }), setItem, removeItem: vi.fn(),
    }))!
    expect(storage.getItem('preferences')).toEqual({ state: { volume: 25 }, version: 1 })
    storage.setItem('preferences', { state: { volume: 25 }, version: 1 })
    expect(setItem).not.toHaveBeenCalled()
    expect(() => storage.setItem('preferences', { state: { volume: 50 }, version: 1 })).toThrow('Storage full')
    storage.setItem('preferences', { state: { volume: 50 }, version: 1 })
    expect(setItem).toHaveBeenCalledTimes(2)
    storage.removeItem('preferences')
    storage.setItem('preferences', { state: { volume: 50 }, version: 1 })
    expect(setItem).toHaveBeenCalledTimes(3)
  })
})
