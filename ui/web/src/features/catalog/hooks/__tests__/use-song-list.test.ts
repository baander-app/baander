import { createElement, type ReactNode } from 'react'
import { act, renderHook, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { GetSongIndexParams } from '@/shared/api-client/gen/endpoints'
import type { SortState } from '../../components/ListHeader'
import type { CursorPaginatedResponse } from '../../types/api'
import { useSongList } from '../use-song-list'

const { mockGetSongIndex } = vi.hoisted(() => ({
  mockGetSongIndex: vi.fn<(params: GetSongIndexParams, options: RequestInit) => Promise<unknown>>(),
}))

vi.mock('@/shared/api-client/gen/endpoints', () => ({
  getSongIndex: mockGetSongIndex,
  getGetSongIndexQueryKey: (params: GetSongIndexParams) => ['/api/songs/', params],
}))

const sort: SortState = { field: null, direction: null }

function page(ids: string[], nextCursor: string | null, total: number): CursorPaginatedResponse<Record<string, unknown>> {
  return {
    data: ids.map((publicId) => ({ publicId, title: publicId })),
    meta: {
      next_cursor: nextCursor,
      prev_cursor: null,
      has_next_page: nextCursor !== null,
      has_previous_page: false,
      total,
      stale_cursor: false,
      per_page: 2,
    },
  }
}

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((done) => { resolve = done })
  return { promise, resolve }
}

function wrapper() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })
  return function Wrapper({ children }: { children: ReactNode }) {
    return createElement(QueryClientProvider, { client }, children)
  }
}

describe('useSongList pagination', () => {
  beforeEach(() => { vi.resetAllMocks() })

  it('uses cursor metadata to append globally indexed pages and stops at the end', async () => {
    mockGetSongIndex.mockResolvedValueOnce(page(['song-1', 'song-2'], 'cursor-2', 3))
      .mockResolvedValueOnce(page(['song-3'], null, 3))
    const { result } = renderHook(() => useSongList({ sort, pageSize: 2 }), { wrapper: wrapper() })
    await waitFor(() => expect(result.current.hasNextPage).toBe(true))
    expect(result.current.total).toBe(3)
    act(() => result.current.fetchMore())
    await waitFor(() => expect(result.current.hasNextPage).toBe(false))
    expect(mockGetSongIndex).toHaveBeenLastCalledWith({ limit: 2, cursor: 'cursor-2' }, { signal: expect.any(AbortSignal) })
    expect(result.current.songs.map((song) => [song.publicId, song.index])).toEqual([
      ['song-1', 1], ['song-2', 2], ['song-3', 3],
    ])
    act(() => result.current.fetchMore())
    expect(mockGetSongIndex).toHaveBeenCalledTimes(2)
  })

  it('reads totals for empty pages and honors a false next-page flag with a cursor', async () => {
    const response = page([], 'unused-cursor', 8)
    response.meta.has_next_page = false
    mockGetSongIndex.mockResolvedValue(response)
    const { result } = renderHook(() => useSongList({ sort }), { wrapper: wrapper() })
    await waitFor(() => expect(result.current.total).toBe(8))
    expect(result.current.songs).toEqual([])
    expect(result.current.hasNextPage).toBe(false)
    act(() => result.current.fetchMore())
    expect(mockGetSongIndex).toHaveBeenCalledOnce()
  })

  it('deduplicates repeated fetchMore callbacks before the loading rerender', async () => {
    const next = deferred<unknown>()
    mockGetSongIndex.mockResolvedValueOnce(page(['song-1'], 'cursor-2', 2)).mockReturnValueOnce(next.promise)
    const { result } = renderHook(() => useSongList({ sort }), { wrapper: wrapper() })
    await waitFor(() => expect(result.current.hasNextPage).toBe(true))
    act(() => { result.current.fetchMore(); result.current.fetchMore() })
    expect(mockGetSongIndex).toHaveBeenCalledTimes(2)
    await act(async () => next.resolve(page(['song-2'], null, 2)))
    await waitFor(() => expect(result.current.songs).toHaveLength(2))
  })

  it('cancels a pending next page on sort change and cannot mix old rows into the new sort', async () => {
    const next = deferred<unknown>()
    mockGetSongIndex.mockResolvedValueOnce(page(['old'], 'cursor-old', 2))
      .mockReturnValueOnce(next.promise).mockResolvedValueOnce(page(['new'], null, 1))
    const { result, rerender } = renderHook(({ currentSort }) => useSongList({ sort: currentSort }), {
      initialProps: { currentSort: sort }, wrapper: wrapper(),
    })
    await waitFor(() => expect(result.current.hasNextPage).toBe(true))
    act(() => result.current.fetchMore())
    const signal = mockGetSongIndex.mock.calls[1][1].signal
    rerender({ currentSort: { field: 'title', direction: 'asc' } })
    expect(signal?.aborted).toBe(true)
    await waitFor(() => expect(result.current.songs.map((song) => song.publicId)).toEqual(['new']))
    await act(async () => next.resolve(page(['old-next'], null, 2)))
    expect(result.current.songs.map((song) => song.publicId)).toEqual(['new'])
    expect(mockGetSongIndex.mock.calls[2][0]).toEqual({ limit: 100, sort: 'title', order: 'asc' })
  })

  it('retains previous pages after a next-page failure and retries the same cursor', async () => {
    mockGetSongIndex.mockResolvedValueOnce(page(['first'], 'cursor-next', 2))
      .mockRejectedValueOnce(new Error('temporarily unavailable')).mockResolvedValueOnce(page(['second'], null, 2))
    const { result } = renderHook(() => useSongList({ sort }), { wrapper: wrapper() })
    await waitFor(() => expect(result.current.hasNextPage).toBe(true))
    act(() => expect(result.current.fetchMore()).toBeUndefined())
    await waitFor(() => expect(result.current.isFetchMoreError).toBe(true))
    expect(result.current.songs.map((song) => song.publicId)).toEqual(['first'])
    expect(result.current.total).toBe(2)
    act(() => result.current.fetchMore())
    await waitFor(() => expect(result.current.songs.map((song) => song.publicId)).toEqual(['first', 'second']))
    expect(result.current.isFetchMoreError).toBe(false)
    expect(mockGetSongIndex.mock.calls[2][0].cursor).toBe('cursor-next')
  })

  it('exposes an initial error and recovers through retry', async () => {
    mockGetSongIndex.mockRejectedValueOnce(new Error('unavailable')).mockResolvedValueOnce(page(['recovered'], null, 1))
    const { result } = renderHook(() => useSongList({ sort }), { wrapper: wrapper() })
    await waitFor(() => expect(result.current.isError).toBe(true))
    expect(result.current.isFetchMoreError).toBe(false)
    act(() => expect(result.current.retry()).toBeUndefined())
    await waitFor(() => expect(result.current.songs.map((song) => song.publicId)).toEqual(['recovered']))
    expect(result.current.isError).toBe(false)
  })

  it('aborts pending page requests on unmount', async () => {
    const next = deferred<unknown>()
    mockGetSongIndex.mockResolvedValueOnce(page(['first'], 'cursor-next', 2)).mockReturnValueOnce(next.promise)
    const { result, unmount } = renderHook(() => useSongList({ sort }), { wrapper: wrapper() })
    await waitFor(() => expect(result.current.hasNextPage).toBe(true))
    act(() => result.current.fetchMore())
    const signal = mockGetSongIndex.mock.calls[1][1].signal
    unmount()
    expect(signal?.aborted).toBe(true)
    await act(async () => next.resolve(page(['late'], null, 2)))
  })

  it('preserves accumulated pages when an equivalent sort object is rerendered', async () => {
    mockGetSongIndex.mockResolvedValueOnce(page(['first'], 'cursor-next', 2)).mockResolvedValueOnce(page(['second'], null, 2))
    const { result, rerender } = renderHook(({ currentSort }) => useSongList({ sort: currentSort }), {
      initialProps: { currentSort: sort }, wrapper: wrapper(),
    })
    await waitFor(() => expect(result.current.hasNextPage).toBe(true))
    act(() => result.current.fetchMore())
    await waitFor(() => expect(result.current.songs).toHaveLength(2))
    rerender({ currentSort: { ...sort } })
    expect(result.current.songs).toHaveLength(2)
    expect(mockGetSongIndex).toHaveBeenCalledTimes(2)
  })

  it('starts a separate query when page size changes', async () => {
    mockGetSongIndex.mockResolvedValueOnce(page(['small'], null, 1)).mockResolvedValueOnce(page(['large'], null, 1))
    const { result, rerender } = renderHook(({ pageSize }) => useSongList({ sort, pageSize }), {
      initialProps: { pageSize: 2 }, wrapper: wrapper(),
    })
    await waitFor(() => expect(result.current.songs[0]?.publicId).toBe('small'))
    rerender({ pageSize: 50 })
    await waitFor(() => expect(result.current.songs[0]?.publicId).toBe('large'))
    expect(mockGetSongIndex.mock.calls[1][0]).toEqual({ limit: 50 })
  })

  it('sends the API sort field and order and keeps them on next-page requests', async () => {
    mockGetSongIndex.mockResolvedValueOnce(page(['newest'], 'cursor-added', 2))
      .mockResolvedValueOnce(page(['oldest'], null, 2))
    const { result } = renderHook(() => useSongList({ sort: { field: 'added', direction: 'desc' } }), { wrapper: wrapper() })
    await waitFor(() => expect(result.current.hasNextPage).toBe(true))
    act(() => result.current.fetchMore())
    await waitFor(() => expect(result.current.songs).toHaveLength(2))

    expect(mockGetSongIndex.mock.calls[0][0]).toEqual({ limit: 100, sort: 'added', order: 'desc' })
    expect(mockGetSongIndex.mock.calls[1][0]).toEqual({ limit: 100, sort: 'added', order: 'desc', cursor: 'cursor-added' })
  })

  it('omits sort parameters until both the field and the direction are set', async () => {
    mockGetSongIndex.mockResolvedValue(page(['song'], null, 1))
    renderHook(() => useSongList({ sort: { field: 'artist', direction: null } }), { wrapper: wrapper() })

    await waitFor(() => expect(mockGetSongIndex).toHaveBeenCalledOnce())
    expect(mockGetSongIndex.mock.calls[0][0]).toEqual({ limit: 100 })
  })
})
