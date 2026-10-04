import { act, renderHook } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { useGridViewModel } from '../use-grid-view-model'

const { mockUseGetAlbumIndex } = vi.hoisted(() => ({ mockUseGetAlbumIndex: vi.fn() }))
vi.mock('@/shared/api-client/gen/endpoints', () => ({ useGetAlbumIndex: mockUseGetAlbumIndex }))

describe('useGridViewModel pagination', () => {
  it('reads nested page metadata and requests the next page', () => {
    mockUseGetAlbumIndex.mockImplementation(({ page }: { page: number }) => ({
      data: {
        data: [{ publicId: `album-${page}`, title: `Album ${page}` }],
        meta: { current_page: page, last_page: 2, per_page: 24, total: 25 },
      },
      isLoading: false,
      refetch: vi.fn(),
    }))
    const { result } = renderHook(() => useGridViewModel())

    expect(result.current.hasNextPage).toBe(true)
    expect(result.current.albums[0].publicId).toBe('album-1')
    act(() => result.current.loadMore())
    expect(mockUseGetAlbumIndex).toHaveBeenLastCalledWith({ page: 2, limit: 24 })
    expect(result.current.albums[0].publicId).toBe('album-2')
    expect(result.current.hasNextPage).toBe(false)
  })
})
