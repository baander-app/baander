import { describe, it, expect, beforeEach } from 'vitest'
import {
  useViewModeStore,
  VIEW_MODES,
  type ViewMode,
} from '../view-mode-store'

describe('useViewModeStore', () => {
  beforeEach(() => {
    useViewModeStore.setState({ viewMode: 'grid' })
  })

  it('starts with grid as default', () => {
    const state = useViewModeStore.getState()
    expect(state.viewMode).toBe('grid')
  })

  it('setViewMode updates the mode', () => {
    useViewModeStore.getState().setViewMode('list')
    expect(useViewModeStore.getState().viewMode).toBe('list')
  })

  it('setViewMode can set all view modes', () => {
    const modes: ViewMode[] = ['grid', 'list', 'columns', 'timeline', 'activity', 'discover']
    for (const mode of modes) {
      useViewModeStore.getState().setViewMode(mode)
      expect(useViewModeStore.getState().viewMode).toBe(mode)
    }
  })

  it('setViewMode overwrites previous mode', () => {
    useViewModeStore.getState().setViewMode('timeline')
    useViewModeStore.getState().setViewMode('discover')
    expect(useViewModeStore.getState().viewMode).toBe('discover')
  })

  it('VIEW_MODES contains all six modes', () => {
    expect(VIEW_MODES).toEqual(['grid', 'list', 'columns', 'timeline', 'activity', 'discover'])
    expect(VIEW_MODES).toHaveLength(6)
  })
})
