import { describe, it, expect, vi, beforeEach } from 'vitest'
import { renderHook, waitFor, act } from '@testing-library/react'
import { useSidebarConfig } from '@/features/layout/hooks/use-sidebar-config'
import { useSidebarStore } from '@/features/layout/stores/sidebar-store'
import { useMediaModeStore } from '@/features/layout/stores/media-mode-store'
import { ALL_SCHEMAS } from '@/features/layout/schemas'

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: {
    get: vi.fn(),
  },
}))

import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

const mockedGet = vi.mocked(AXIOS_INSTANCE.get)

beforeEach(() => {
  localStorage.clear()
  vi.clearAllMocks()
  useMediaModeStore.setState({ activeMedia: 'music' })
  useSidebarStore.setState({
    items: [],
    schemas: { ...ALL_SCHEMAS },
    isLoading: false,
    error: null,
    isEditorOpen: false,
  })
})

describe('use-sidebar-config (extended)', () => {
  it('falls back to static default schema on API error', async () => {
    mockedGet.mockRejectedValueOnce(new Error('Network error'))

    renderHook(() => useSidebarConfig())

    await waitFor(() => {
      const schema = useSidebarStore.getState().getActiveSchema()
      expect(schema).toEqual(ALL_SCHEMAS.music)
    })
  })

  it('falls back to static default schema on empty API response', async () => {
    mockedGet.mockResolvedValueOnce({ data: null })

    renderHook(() => useSidebarConfig())

    await waitFor(() => {
      const schema = useSidebarStore.getState().getActiveSchema()
      expect(schema).toEqual(ALL_SCHEMAS.music)
    })
  })

  it('uses schema from API when available', async () => {
    const customSchema = {
      ...ALL_SCHEMAS.music,
      sections: [ALL_SCHEMAS.music.sections[0]],
    }
    mockedGet.mockResolvedValueOnce({ data: customSchema })

    renderHook(() => useSidebarConfig())

    await waitFor(() => {
      const schema = useSidebarStore.getState().getActiveSchema()
      expect(schema.sections).toHaveLength(1)
    })
  })

  it('sets loading to false after fetch completes', async () => {
    mockedGet.mockRejectedValueOnce(new Error('Network error'))

    renderHook(() => useSidebarConfig())

    await waitFor(() => {
      expect(useSidebarStore.getState().isLoading).toBe(false)
    })
  })

  describe('smart skeleton caching', () => {
    it('does not show loading when schema is already cached', async () => {
      // Schemas are pre-loaded from ALL_SCHEMAS in beforeEach
      mockedGet.mockImplementation(() => new Promise(() => {})) // never resolves

      const { result } = renderHook(() => useSidebarConfig())

      // isLoading should be false because music schema has cached sections
      expect(result.current.isLoading).toBe(false)
    })

    it('shows loading when schema has no cached content', async () => {
      // Set music schema to empty sections (simulating first load with no cache)
      useSidebarStore.setState({
        schemas: { ...ALL_SCHEMAS, music: { ...ALL_SCHEMAS.music, sections: [] } },
      })
      mockedGet.mockImplementation(() => new Promise(() => {})) // never resolves

      const { result } = renderHook(() => useSidebarConfig())

      expect(result.current.isLoading).toBe(true)
    })

    it('hides loading once empty schema is fetched and populated', async () => {
      useSidebarStore.setState({
        schemas: { ...ALL_SCHEMAS, music: { ...ALL_SCHEMAS.music, sections: [] } },
        isLoading: false,
      })
      mockedGet.mockResolvedValueOnce({ data: ALL_SCHEMAS.music })

      const { result } = renderHook(() => useSidebarConfig())

      // Initially loading because no cached content
      expect(result.current.isLoading).toBe(true)

      await waitFor(() => {
        expect(result.current.isLoading).toBe(false)
      })
    })
  })
})
