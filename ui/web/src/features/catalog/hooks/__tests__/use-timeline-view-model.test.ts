import { describe, it, expect, vi, beforeEach } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'

// --- Mocks ---

const mockGet = vi.fn()
vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: {
    get: (...args: unknown[]) => mockGet(...args),
  },
}))

vi.mock('@/shared/api-client/gen/endpoints', () => ({}))

import { useTimelineViewModel } from '../use-timeline-view-model'

function makeAlbum(
  publicId: string,
  year: number | null,
  title?: string,
) {
  return {
    uuid: `uuid-${publicId}`,
    publicId,
    title: title ?? `Album ${publicId}`,
    type: 'album',
    year,
    label: null,
    barcode: null,
    country: null,
    createdAt: '2024-01-01T00:00:00Z',
    coverImage: null,
    artists: [],
  }
}

function makeResponse(albums: ReturnType<typeof makeAlbum>[]) {
  return {
    data: albums,
    currentPage: 1,
    lastPage: 1,
    perPage: 100,
    total: albums.length,
  }
}

describe('useTimelineViewModel', () => {
  beforeEach(() => {
    mockGet.mockReset()
  })

  it('groups albums by decade and year', async () => {
    mockGet.mockResolvedValue({ data: makeResponse([
      makeAlbum('a', 2024),
      makeAlbum('b', 2023),
      makeAlbum('c', 2015),
      makeAlbum('d', 2014),
    ]) })

    const { result } = renderHook(() => useTimelineViewModel())

    await waitFor(() => {
      expect(result.current.isLoading).toBe(false)
    })

    const decades = result.current.decades
    expect(decades).toHaveLength(2)

    // Decades sorted descending: 2020s first, then 2010s
    expect(decades[0].label).toBe('2020s')
    expect(decades[0].years).toHaveLength(2)
    expect(decades[0].years[0].label).toBe('2024')
    expect(decades[0].years[0].albums).toHaveLength(1)
    expect(decades[0].years[1].label).toBe('2023')

    expect(decades[1].label).toBe('2010s')
    expect(decades[1].years).toHaveLength(2)
    expect(decades[1].years[0].label).toBe('2015')
    expect(decades[1].years[1].label).toBe('2014')
  })

  it('places albums without year into Unknown section', async () => {
    mockGet.mockResolvedValue({ data: makeResponse([
      makeAlbum('a', 2024),
      makeAlbum('b', null),
      makeAlbum('c', null, 'Unknown Album'),
    ]) })

    const { result } = renderHook(() => useTimelineViewModel())

    await waitFor(() => {
      expect(result.current.isLoading).toBe(false)
    })

    const decades = result.current.decades
    expect(decades).toHaveLength(2)
    expect(decades[0].label).toBe('2020s')
    expect(decades[1].label).toBe('Unknown')
    expect(decades[1].years[0].albums).toHaveLength(2)
  })

  it('returns empty decades when no albums have year', async () => {
    mockGet.mockResolvedValue({ data: makeResponse([
      makeAlbum('a', null),
      makeAlbum('b', null),
    ]) })

    const { result } = renderHook(() => useTimelineViewModel())

    await waitFor(() => {
      expect(result.current.isLoading).toBe(false)
    })

    expect(result.current.decades).toHaveLength(1)
    expect(result.current.decades[0].label).toBe('Unknown')
  })

  it('returns empty array when no albums', async () => {
    mockGet.mockResolvedValue({ data: makeResponse([]) })

    const { result } = renderHook(() => useTimelineViewModel())

    await waitFor(() => {
      expect(result.current.isLoading).toBe(false)
    })

    expect(result.current.decades).toHaveLength(0)
  })

  it('reports loading state', () => {
    mockGet.mockReturnValue(new Promise(() => {})) // Never resolves

    const { result } = renderHook(() => useTimelineViewModel())

    expect(result.current.isLoading).toBe(true)
    expect(result.current.decades).toHaveLength(0)
  })

  it('reports error state', async () => {
    mockGet.mockRejectedValue(new Error('Network error'))

    const { result } = renderHook(() => useTimelineViewModel())

    await waitFor(() => {
      expect(result.current.isLoading).toBe(false)
    })

    expect(result.current.error).toBeDefined()
  })

  it('sorts years within a decade descending', async () => {
    mockGet.mockResolvedValue({ data: makeResponse([
      makeAlbum('a', 2021),
      makeAlbum('b', 2024),
      makeAlbum('c', 2022),
    ]) })

    const { result } = renderHook(() => useTimelineViewModel())

    await waitFor(() => {
      expect(result.current.isLoading).toBe(false)
    })

    const decade = result.current.decades[0]
    expect(decade.years.map((y) => y.label)).toEqual(['2024', '2022', '2021'])
  })

  it('groups multiple albums in the same year', async () => {
    mockGet.mockResolvedValue({ data: makeResponse([
      makeAlbum('a', 2024),
      makeAlbum('b', 2024),
      makeAlbum('c', 2024),
    ]) })

    const { result } = renderHook(() => useTimelineViewModel())

    await waitFor(() => {
      expect(result.current.isLoading).toBe(false)
    })

    const decade = result.current.decades[0]
    expect(decade.years).toHaveLength(1)
    expect(decade.years[0].label).toBe('2024')
    expect(decade.years[0].albums).toHaveLength(3)
  })
})
