import { useState, useCallback } from 'react'
import type { PaginatedResponse, GetAlbumIndexParams } from '@/shared/api-client/gen/endpoints'
import { useGetAlbumIndex } from '@/shared/api-client/gen/endpoints'
import { asAlbumsFromItems } from '../utils/api-adapters'
import type { AlbumSummary } from '../types'

const DEFAULT_PER_PAGE = 24

interface UseGridViewModelOptions {
  params?: Omit<GetAlbumIndexParams, 'page'>
  perPage?: number
}

interface UseGridViewModelReturn {
  albums: AlbumSummary[]
  isLoading: boolean
  error: unknown
  loadMore: () => void
  hasNextPage: boolean
  currentPage: number
  refetch: () => void
}

export function useGridViewModel({
  params,
  perPage = DEFAULT_PER_PAGE,
}: UseGridViewModelOptions = {}): UseGridViewModelReturn {
  const [page, setPage] = useState(1)

  const { data, isLoading, error, refetch } = useGetAlbumIndex({
    ...params,
    page,
    limit: perPage,
  })

  const response = data as unknown as PaginatedResponse | undefined
  const albums = response ? asAlbumsFromItems(response.data) : []
  const hasNextPage = response ? response.currentPage < response.lastPage : false

  const loadMore = useCallback(() => {
    setPage((p) => p + 1)
  }, [])

  return {
    albums,
    isLoading,
    error,
    loadMore,
    hasNextPage,
    currentPage: page,
    refetch,
  }
}
