import { describe, it, expect, beforeEach } from 'vitest'
import { useListColumnStore } from '../list-column-store'

describe('useListColumnStore', () => {
  beforeEach(() => {
    // Reset to defaults
    useListColumnStore.setState({
      visibleColumns: ['#', 'title', 'artist', 'album', 'year', 'duration'],
      columnOrder: ['#', 'title', 'artist', 'album', 'year', 'genre', 'duration', 'bitrate', 'format', 'createdAt'],
    })
  })

  it('has correct default visible columns', () => {
    const state = useListColumnStore.getState()
    expect(state.visibleColumns).toEqual(['#', 'title', 'artist', 'album', 'year', 'duration'])
  })

  it('has correct default column order', () => {
    const state = useListColumnStore.getState()
    expect(state.columnOrder).toHaveLength(10)
    expect(state.columnOrder[0]).toBe('#')
    expect(state.columnOrder[1]).toBe('title')
  })

  it('toggleColumn adds a hidden column', () => {
    useListColumnStore.getState().toggleColumn('genre')

    const state = useListColumnStore.getState()
    expect(state.visibleColumns).toContain('genre')
  })

  it('toggleColumn removes a visible column', () => {
    useListColumnStore.getState().toggleColumn('year')

    const state = useListColumnStore.getState()
    expect(state.visibleColumns).not.toContain('year')
  })

  it('toggleColumn round-trips (add then remove)', () => {
    useListColumnStore.getState().toggleColumn('genre')
    useListColumnStore.getState().toggleColumn('genre')

    const state = useListColumnStore.getState()
    expect(state.visibleColumns).not.toContain('genre')
  })

  it('reorderColumns sets new order', () => {
    useListColumnStore.getState().reorderColumns(['title', '#', 'artist'])

    const state = useListColumnStore.getState()
    expect(state.columnOrder).toEqual(['title', '#', 'artist'])
  })
})
