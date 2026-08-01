import { describe, it, expect, beforeEach } from 'vitest'
import { useSidebarStore } from '@/features/layout/stores/sidebar-store'
import { useMediaModeStore } from '@/features/layout/stores/media-mode-store'
import { ALL_SCHEMAS } from '@/features/layout/schemas'

beforeEach(() => {
  localStorage.clear()
  useMediaModeStore.setState({ activeMedia: 'music' })
  useSidebarStore.setState({
    items: [],
    schemas: { ...ALL_SCHEMAS },
    isLoading: false,
    error: null,
    isEditorOpen: false,
  })
})

describe('sidebar-store (extended)', () => {
  describe('schemas', () => {
    it('schemas initialize from ALL_SCHEMAS', () => {
      expect(useSidebarStore.getState().schemas).toEqual(ALL_SCHEMAS)
    })

    it('setSchema updates a single media type schema', () => {
      const customSchema = { ...ALL_SCHEMAS.music, sections: [] }
      useSidebarStore.getState().setSchema('music', customSchema)
      expect(useSidebarStore.getState().schemas.music.sections).toEqual([])
    })
  })

  describe('getActiveSchema', () => {
    it('returns the schema for activeMedia from media-mode-store', () => {
      const schema = useSidebarStore.getState().getActiveSchema()
      expect(schema.mediaType).toBe('music')
      expect(schema.sections.length).toBeGreaterThan(0)
    })

    it('returns correct schema after switching media mode', () => {
      useMediaModeStore.getState().setActiveMedia('movies')
      const schema = useSidebarStore.getState().getActiveSchema()
      expect(schema.mediaType).toBe('movies')
    })
  })

  describe('backward compat', () => {
    it('preserves existing items array', () => {
      useSidebarStore.getState().setItems([{ id: 'test', type: 'page_link' as const, label: 'Test', icon: 'home', config: {} }])
      expect(useSidebarStore.getState().items).toHaveLength(1)
    })

    it('preserves existing actions', () => {
      expect(typeof useSidebarStore.getState().setLoading).toBe('function')
      expect(typeof useSidebarStore.getState().setError).toBe('function')
      expect(typeof useSidebarStore.getState().setEditorOpen).toBe('function')
    })
  })
})
