import { describe, it, expect, beforeEach, vi } from 'vitest'
import { useSelectionStore } from '../selection-store'
import { selectionTransition } from '../selection-transition'

describe('useSelectionStore', () => {
  beforeEach(() => {
    useSelectionStore.getState().clear()
  })

  it('starts with null selection', () => {
    const state = useSelectionStore.getState()
    expect(state.selectedId).toBeNull()
    expect(state.selectedType).toBeNull()
  })

  it('select sets id and type', () => {
    useSelectionStore.getState().select('album-1', 'album')

    const state = useSelectionStore.getState()
    expect(state.selectedId).toBe('album-1')
    expect(state.selectedType).toBe('album')
  })

  it('clear resets to null', () => {
    useSelectionStore.getState().select('album-1', 'album')
    useSelectionStore.getState().clear()

    const state = useSelectionStore.getState()
    expect(state.selectedId).toBeNull()
    expect(state.selectedType).toBeNull()
  })

  it('shares selection calculation and keeps repeated select and clear silent', () => {
    const listener = vi.fn()
    const unsubscribe = useSelectionStore.subscribe(listener)
    const initial = { selectedId: null, selectedType: null }
    expect(selectionTransition.calculate(initial, 'select', ['album-1', 'album']))
      .toEqual({ selectedId: 'album-1', selectedType: 'album' })
    expect(selectionTransition.calculate({ selectedId: 'album-1', selectedType: 'album' }, 'clear', []))
      .toEqual(initial)
    useSelectionStore.getState().select('album-1', 'album')
    useSelectionStore.getState().select('album-1', 'album')
    useSelectionStore.getState().clear()
    useSelectionStore.getState().clear()
    expect(listener).toHaveBeenCalledTimes(2)
    unsubscribe()
  })

  it('records the real selection actions and their no-ops with the shared transition', async () => {
    const previous = localStorage.getItem('baander-store-debug')
    localStorage.setItem('baander-store-debug', 'true')
    vi.resetModules()
    try {
      const { useSelectionStore: tracedStore } = await import('../selection-store')
      const { storeDebugger } = await import('@/shared/stores/debug')
      tracedStore.getState().select('album-1', 'album')
      tracedStore.getState().select('album-1', 'album')
      tracedStore.getState().clear()
      const events = storeDebugger.getSnapshot().events
      expect(events.filter(event => event.kind === 'action').map(event => event.transition))
        .toEqual(Array.from({ length: 3 }, () => ({ id: 'catalog.selection', version: 1 })))
      expect(events.filter(event => event.kind === 'resolved').map(event => event.after)).toEqual([
        { value: { selectedId: 'album-1', selectedType: 'album' }, complete: true },
        { value: { selectedId: 'album-1', selectedType: 'album' }, complete: true },
        { value: { selectedId: null, selectedType: null }, complete: true },
      ])
      expect(events.filter(event => event.kind === 'update')).toHaveLength(2)
      tracedStore.getState().select(Infinity as unknown as string, 'album')
      const malformedAction = storeDebugger.getSnapshot().events.findLast(event => event.kind === 'action')!
      expect(storeDebugger.verifyAction(malformedAction.id).status).toBe('unavailable')
    } finally {
      if (previous === null) localStorage.removeItem('baander-store-debug')
      else localStorage.setItem('baander-store-debug', previous)
      vi.resetModules()
    }
  })

  it('validates only complete selection data and supported argument shapes', () => {
    expect(selectionTransition.validateState({ selectedId: null, selectedType: null })).toBe(true)
    expect(selectionTransition.validateState({ selectedId: 'album-1', selectedType: 'album' })).toBe(true)
    expect(selectionTransition.validateState({ selectedId: null, selectedType: 'album' })).toBe(false)
    expect(selectionTransition.validateState({ selectedId: 'album-1', selectedType: 'unknown' })).toBe(false)
    expect(selectionTransition.validateState({ selectedId: 'album-1', selectedType: 'album', extra: 1 })).toBe(false)
    expect(selectionTransition.validateArgs('select', ['album-1', 'album'])).toBe(true)
    expect(selectionTransition.validateArgs('select', ['album-1', 'album', 'extra'])).toBe(false)
    expect(selectionTransition.validateArgs('select', ['album-1', 'unknown'])).toBe(false)
    expect(selectionTransition.validateArgs('clear', [])).toBe(true)
    expect(selectionTransition.validateArgs('clear', ['album-1'])).toBe(false)
    expect(selectionTransition.validateArgs('other', [])).toBe(false)
  })

  it('select overwrites previous selection', () => {
    useSelectionStore.getState().select('album-1', 'album')
    useSelectionStore.getState().select('artist-42', 'artist')

    const state = useSelectionStore.getState()
    expect(state.selectedId).toBe('artist-42')
    expect(state.selectedType).toBe('artist')
  })
})
