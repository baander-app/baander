import { describe, it, expect, vi, beforeEach } from 'vitest'
import { renderHook } from '@testing-library/react'

const mockRefetch = vi.fn()

type MockConfig = {
  data?: unknown
  isLoading?: boolean
  error?: unknown
}

let mockConfig: MockConfig = {}

vi.mock('@/shared/api-client/gen/endpoints', () => ({
  useGetRecommendationIndex: () => ({
    data: mockConfig.data ?? undefined,
    isLoading: mockConfig.isLoading ?? false,
    error: mockConfig.error ?? null,
    refetch: mockRefetch,
  }),
}))

import { useDiscoverViewModel } from '../use-discover-view-model'

function makeRecommendations() {
  return {
    data: [
      {
        id: 'rec-1',
        name: 'Because you listened to Album A',
        source_type: 'album',
        source_id: 'album-a',
        target_type: 'album',
        target_id: 'album-b',
        score: 0.9,
        position: 1,
        user_id: 'user-1',
        created_at: '2026-01-01T00:00:00Z',
        updated_at: '2026-01-01T00:00:00Z',
      },
      {
        id: 'rec-2',
        name: 'Because you listened to Album A',
        source_type: 'album',
        source_id: 'album-a',
        target_type: 'album',
        target_id: 'album-c',
        score: 0.8,
        position: 2,
        user_id: 'user-1',
        created_at: '2026-01-01T00:00:00Z',
        updated_at: '2026-01-01T00:00:00Z',
      },
      {
        id: 'rec-3',
        name: 'Similar to Artist X',
        source_type: 'artist',
        source_id: 'artist-x',
        target_type: 'artist',
        target_id: 'artist-y',
        score: 0.7,
        position: 1,
        user_id: 'user-1',
        created_at: '2026-01-01T00:00:00Z',
        updated_at: '2026-01-01T00:00:00Z',
      },
    ],
  }
}

describe('useDiscoverViewModel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockConfig = {}
  })

  it('groups recommendations by source entity', () => {
    mockConfig.data = makeRecommendations()
    const { result } = renderHook(() => useDiscoverViewModel())

    expect(result.current.clusters).toHaveLength(2)
    expect(result.current.clusters[0].sourceId).toBe('album-a')
    expect(result.current.clusters[0].sourceType).toBe('album')
    expect(result.current.clusters[0].items).toHaveLength(2)
    expect(result.current.clusters[1].sourceId).toBe('artist-x')
    expect(result.current.clusters[1].sourceType).toBe('artist')
    expect(result.current.clusters[1].items).toHaveLength(1)
  })

  it('sets sourceName from enriched sourceName field or falls back to sourceId', () => {
    mockConfig.data = makeRecommendations()
    const { result } = renderHook(() => useDiscoverViewModel())

    // Without enriched sourceName, the hook falls back to sourceId
    expect(result.current.clusters[0].sourceName).toBe('album-a')
    expect(result.current.clusters[1].sourceName).toBe('artist-x')
  })

  it('sorts items by position within each cluster', () => {
    mockConfig.data = makeRecommendations()
    const { result } = renderHook(() => useDiscoverViewModel())

    const albumCluster = result.current.clusters[0]
    expect(albumCluster.items[0].position).toBe(1)
    expect(albumCluster.items[1].position).toBe(2)
  })

  it('returns empty clusters when no data', () => {
    mockConfig.data = { data: [] }
    const { result } = renderHook(() => useDiscoverViewModel())
    expect(result.current.clusters).toHaveLength(0)
  })

  it('returns isLoading true when loading', () => {
    mockConfig.isLoading = true
    mockConfig.data = undefined
    const { result } = renderHook(() => useDiscoverViewModel())
    expect(result.current.isLoading).toBe(true)
  })

  it('exposes error from API', () => {
    mockConfig.error = new Error('API error')
    mockConfig.data = undefined
    const { result } = renderHook(() => useDiscoverViewModel())
    expect(result.current.error).toBeDefined()
  })

  it('refresh calls refetch', () => {
    mockConfig.data = makeRecommendations()
    const { result } = renderHook(() => useDiscoverViewModel())
    result.current.refresh()
    expect(mockRefetch).toHaveBeenCalledOnce()
  })

  it('skips recommendations missing source_type or source_id', () => {
    mockConfig.data = {
      data: [
        {
          id: 'rec-bad',
          name: 'Bad rec',
          source_type: undefined,
          source_id: 'album-a',
          target_type: 'album',
          target_id: 'album-b',
          score: 0.9,
          position: 1,
        },
      ],
    }
    const { result } = renderHook(() => useDiscoverViewModel())
    expect(result.current.clusters).toHaveLength(0)
  })
})
