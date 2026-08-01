import { describe, it, expect, vi, beforeEach } from 'vitest'
import { renderHook } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { useActivityViewModel } from '../use-activity-view-model'

const mockUseGetActivityHistory = vi.fn()
vi.mock('@/shared/api-client/gen/endpoints', () => ({
  useGetActivityHistory: (...args: any[]) => mockUseGetActivityHistory(...args),
}))

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  }
}

const now = new Date()
const oneHourAgo = new Date(now.getTime() - 3600_000).toISOString()
const oneDayAgo = new Date(now.getTime() - 86400_000).toISOString()
const threeDaysAgo = new Date(now.getTime() - 3 * 86400_000).toISOString()

function makeEntry(overrides: Record<string, unknown> = {}) {
  return {
    uuid: 'u1',
    publicId: 'p1',
    userId: 'user1',
    activityType: 'play',
    songId: 'song1',
    albumId: null,
    artistId: null,
    movieId: null,
    playCount: 1,
    love: false,
    lastPlayedAt: oneHourAgo,
    lastPlatform: 'web',
    lastPlayer: null,
    createdAt: oneHourAgo,
    ...overrides,
  }
}

describe('useActivityViewModel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('returns loading state', () => {
    mockUseGetActivityHistory.mockReturnValue({ data: null, isLoading: true, error: null, refetch: vi.fn() })
    const { result } = renderHook(() => useActivityViewModel(), { wrapper: createWrapper() })
    expect(result.current.isLoading).toBe(true)
    expect(result.current.groups).toEqual([])
  })

  it('groups entries by time period', () => {
    const entries = [
      makeEntry({ publicId: 'p1', lastPlayedAt: oneHourAgo, createdAt: oneHourAgo }),
      makeEntry({ publicId: 'p2', lastPlayedAt: oneDayAgo, createdAt: oneDayAgo }),
      makeEntry({ publicId: 'p3', lastPlayedAt: threeDaysAgo, createdAt: threeDaysAgo }),
    ]

    mockUseGetActivityHistory.mockReturnValue({
      data: { data: entries },
      isLoading: false,
      error: null,
      refetch: vi.fn(),
    })

    const { result } = renderHook(() => useActivityViewModel(), { wrapper: createWrapper() })

    const labels = result.current.groups.map((g) => g.label)
    // p1 is Today, p2 is Yesterday, p3 is This Week (or older depending on day)
    expect(labels.length).toBeGreaterThanOrEqual(2)
    expect(labels).toContain('Today')
    expect(labels).toContain('Yesterday')
  })

  it('returns empty groups when no data', () => {
    mockUseGetActivityHistory.mockReturnValue({
      data: { data: [] },
      isLoading: false,
      error: null,
      refetch: vi.fn(),
    })

    const { result } = renderHook(() => useActivityViewModel(), { wrapper: createWrapper() })
    expect(result.current.groups).toEqual([])
  })

  it('groups are ordered by PERIOD_ORDER', () => {
    const entries = [
      makeEntry({ publicId: 'p2', lastPlayedAt: oneDayAgo, createdAt: oneDayAgo }),
      makeEntry({ publicId: 'p1', lastPlayedAt: oneHourAgo, createdAt: oneHourAgo }),
    ]

    mockUseGetActivityHistory.mockReturnValue({
      data: { data: entries },
      isLoading: false,
      error: null,
      refetch: vi.fn(),
    })

    const { result } = renderHook(() => useActivityViewModel(), { wrapper: createWrapper() })

    const labels = result.current.groups.map((g) => g.label)
    // "Today" should come before "Yesterday"
    const todayIdx = labels.indexOf('Today')
    const yesterdayIdx = labels.indexOf('Yesterday')
    expect(todayIdx).toBeLessThan(yesterdayIdx)
  })

  it('parses optional song/artist/album name fields', () => {
    const entries = [
      makeEntry({
        publicId: 'p1',
        songTitle: 'My Song',
        artistName: 'My Artist',
        albumName: 'My Album',
      }),
    ]

    mockUseGetActivityHistory.mockReturnValue({
      data: { data: entries },
      isLoading: false,
      error: null,
      refetch: vi.fn(),
    })

    const { result } = renderHook(() => useActivityViewModel(), { wrapper: createWrapper() })

    expect(result.current.groups[0].items[0].songTitle).toBe('My Song')
    expect(result.current.groups[0].items[0].artistName).toBe('My Artist')
    expect(result.current.groups[0].items[0].albumName).toBe('My Album')
  })
})
