import { describe, it, expect, beforeEach } from 'vitest'
import { useSelectionStore } from '../selection-store'

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

  it('select overwrites previous selection', () => {
    useSelectionStore.getState().select('album-1', 'album')
    useSelectionStore.getState().select('artist-42', 'artist')

    const state = useSelectionStore.getState()
    expect(state.selectedId).toBe('artist-42')
    expect(state.selectedType).toBe('artist')
  })
})
