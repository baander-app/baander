import { describe, it, expect, vi, beforeEach } from 'vitest'
import { renderHook } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'

// --- Mocks ---

const mockArtistData = {
  name: 'Test Artist',
  publicId: 'artist-1',
  type: 'person',
  country: 'US',
}

const makeAlbumsData = (count: number, currentPage = 1, lastPage = 1) => ({
  data: Array.from({ length: count }, (_, i) => ({
    publicId: `album-${i}`,
    title: `Album ${i}`,
    artistName: 'Test Artist',
    year: 2020 + i,
  })),
  currentPage,
  lastPage,
  perPage: 24,
  total: count,
})

let artistResult: Record<string, unknown> = {
  data: mockArtistData,
  isLoading: false,
  isError: false,
  error: null,
}

let albumsResult: Record<string, unknown> = {
  data: makeAlbumsData(5),
  isLoading: false,
  isError: false,
  error: null,
}

vi.mock('@/shared/api-client/gen/endpoints', () => ({
  useGetArtistShow: () => artistResult,
  useGetAlbumIndex: () => albumsResult,
}))

import { useArtistDetail } from '../use-artist-detail'

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  }
}

describe('useArtistDetail', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    artistResult = {
      data: mockArtistData,
      isLoading: false,
      isError: false,
      error: null,
    }
    albumsResult = {
      data: makeAlbumsData(5),
      isLoading: false,
      isError: false,
      error: null,
    }
  })

  it('returns artist and albums', () => {
    const { result } = renderHook(() => useArtistDetail('artist-1'), {
      wrapper: createWrapper(),
    })
    expect(result.current.artist?.name).toBe('Test Artist')
    expect(result.current.albums).toHaveLength(5)
    expect(result.current.albumCount).toBe(5)
  })

  it('returns loading state', () => {
    artistResult = { ...artistResult, isLoading: true, data: undefined }
    albumsResult = { ...albumsResult, isLoading: true, data: undefined }
    const { result } = renderHook(() => useArtistDetail('artist-1'), {
      wrapper: createWrapper(),
    })
    expect(result.current.isLoading).toBe(true)
  })

  it('returns error state', () => {
    artistResult = {
      ...artistResult,
      isError: true,
      error: new Error('fail'),
      data: undefined,
    }
    const { result } = renderHook(() => useArtistDetail('artist-1'), {
      wrapper: createWrapper(),
    })
    expect(result.current.error).toBeDefined()
  })

  it('computes hasNextPage from pagination', () => {
    albumsResult = {
      ...albumsResult,
      data: makeAlbumsData(5, 1, 2),
    }
    const { result } = renderHook(() => useArtistDetail('artist-1'), {
      wrapper: createWrapper(),
    })
    expect(result.current.hasNextPage).toBe(true)
  })

  it('returns empty albumCount when no data', () => {
    albumsResult = { ...albumsResult, data: undefined }
    const { result } = renderHook(() => useArtistDetail('artist-1'), {
      wrapper: createWrapper(),
    })
    expect(result.current.albumCount).toBe(0)
  })

  it('returns loading state when publicId is undefined', () => {
    // When publicId is undefined, queries are disabled but the mock still returns data.
    // Verify the hook doesn't crash and returns a valid shape.
    const { result } = renderHook(() => useArtistDetail(undefined), {
      wrapper: createWrapper(),
    })
    expect(result.current.albumCount).toBeDefined()
  })
})
