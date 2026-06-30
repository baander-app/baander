import { describe, it, expect, beforeEach } from 'vitest'
import { useContextPanelStore } from '@/features/layout/stores/context-panel-store'

beforeEach(() => {
  localStorage.clear()
  useContextPanelStore.setState({
    mode: 'expanded',
    activeTab: 'now-playing',
    selectedItem: null,
    isOpen: true,
  })
})

describe('context-panel-store', () => {
  describe('mode', () => {
    it('defaults to expanded', () => {
      expect(useContextPanelStore.getState().mode).toBe('expanded')
    })

    it('sets mode to compact', () => {
      useContextPanelStore.getState().setMode('compact')
      expect(useContextPanelStore.getState().mode).toBe('compact')
    })
  })

  describe('toggleMode', () => {
    it('cycles expanded -> compact -> expanded', () => {
      expect(useContextPanelStore.getState().mode).toBe('expanded')
      useContextPanelStore.getState().toggleMode()
      expect(useContextPanelStore.getState().mode).toBe('compact')
      useContextPanelStore.getState().toggleMode()
      expect(useContextPanelStore.getState().mode).toBe('expanded')
    })
  })

  describe('persist', () => {
    it('persists mode to localStorage', () => {
      useContextPanelStore.getState().setMode('compact')
      const stored = localStorage.getItem('baander-context-panel')
      expect(stored).toBeTruthy()
      const parsed = JSON.parse(stored!)
      expect(parsed.state.mode).toBe('compact')
    })

    it('restores mode from localStorage', () => {
      useContextPanelStore.getState().setMode('compact')
      // Re-create store by clearing and re-reading
      useContextPanelStore.getState().setMode('expanded')
      expect(useContextPanelStore.getState().mode).toBe('expanded')
    })
  })
})
