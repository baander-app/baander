import { act, renderHook, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { CursorPaginatedResponse } from '../../types/api'
import { useSongList } from '../use-song-list'

const { mockUseGetSongIndex, mockGetSongIndex } = vi.hoisted(() => ({
  mockUseGetSongIndex: vi.fn(),
  mockGetSongIndex: vi.fn(),
}))

vi.mock('@/shared/api-client/gen/endpoints', () => ({
  useGetSongIndex: mockUseGetSongIndex,
  getSongIndex: mockGetSongIndex,
}))

const sort = { field: null, direction: null }

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

describe('useSongList pagination', () => {
  beforeEach(() => {
    vi.resetAllMocks()
  })

  it('uses nested totals and cursors to append pages, then stops at the final page', async () => {
    mockUseGetSongIndex.mockReturnValue({ data: page(['song-1', 'song-2'], 'cursor-2', 3), isLoading: false })
    mockGetSongIndex.mockResolvedValue(page(['song-3'], null, 3))
    const { result } = renderHook(() => useSongList({ sort, pageSize: 2 }))

    await waitFor(() => expect(result.current.hasNextPage).toBe(true))
    expect(result.current.total).toBe(3)
    expect(result.current.songs.map((song) => song.publicId)).toEqual(['song-1', 'song-2'])

    await act(async () => result.current.fetchMore())

    expect(mockGetSongIndex).toHaveBeenCalledWith({ limit: 2, cursor: 'cursor-2' })
    expect(result.current.songs.map((song) => [song.publicId, song.index])).toEqual([
      ['song-1', 1], ['song-2', 2], ['song-3', 3],
    ])
    expect(result.current.hasNextPage).toBe(false)
    await act(async () => result.current.fetchMore())
    expect(mockGetSongIndex).toHaveBeenCalledOnce()
  })

  it('reads metadata even when the initial page is empty', async () => {
    mockUseGetSongIndex.mockReturnValue({ data: page([], null, 8), isLoading: false })
    const { result } = renderHook(() => useSongList({ sort }))

    await waitFor(() => expect(result.current.total).toBe(8))
    expect(result.current.songs).toEqual([])
    expect(result.current.hasNextPage).toBe(false)
  })
})
