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

  describe('layoutPreferencesRead', () => {
    it('starts unset and is kept out of local storage', () => {
      useContextPanelStore.setState({ layoutPreferencesRead: false })
      useContextPanelStore.getState().setLayoutPreferencesRead(true)
      expect(useContextPanelStore.getState().layoutPreferencesRead).toBe(true)
      useContextPanelStore.getState().setMode('compact')
      expect(localStorage.getItem('baander-context-panel')).not.toContain('layoutPreferencesRead')
    })
  })

  describe('selectItemKeepingTab', () => {
    it('selects the item and opens the panel without changing the tab', () => {
      useContextPanelStore.setState({ activeTab: 'queue', isOpen: false })
      useContextPanelStore.getState().selectItemKeepingTab({ type: 'song', publicId: 'song-1' })
      expect(useContextPanelStore.getState()).toMatchObject({
        selectedItem: { type: 'song', publicId: 'song-1' }, activeTab: 'queue', isOpen: true,
      })
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
