import { useState, useCallback } from 'react'
import { useGetArtistShow, useGetAlbumIndex } from '@/shared/api-client/gen/endpoints'
import { asAlbums, extractPaginatedMeta } from '../utils/api-adapters'
import type { AlbumSummary } from '../types'

const DEFAULT_PER_PAGE = 24

interface UseArtistDetailOptions {
  perPage?: number
}

interface UseArtistDetailReturn {
  artist: Record<string, unknown> | undefined
  albums: AlbumSummary[]
  albumCount: number
  isLoading: boolean
  error: unknown
  loadMore: () => void
  hasNextPage: boolean
}

export function useArtistDetail(
  publicId: string | undefined,
  { perPage = DEFAULT_PER_PAGE }: UseArtistDetailOptions = {},
): UseArtistDetailReturn {
  const [page, setPage] = useState(1)

  const {
    data: artistData,
    isLoading: artistLoading,
    error: artistError,
  } = useGetArtistShow(publicId ?? '', {
    query: { enabled: !!publicId },
  })

  // Unwrap customInstance spread: { data: { ... }, status, headers }
  const rawArtist = artistData as Record<string, unknown> | undefined
  const artist = (rawArtist?.data ?? rawArtist) as Record<string, unknown> | undefined

  const {
    data: albumsData,
    isLoading: albumsLoading,
    error: albumsError,
  } = useGetAlbumIndex(
    {
      artistId: publicId,
      page,
      limit: perPage,
    },
    { query: { enabled: !!publicId } },
  )

  const albums = asAlbums(albumsData)
  const meta = extractPaginatedMeta(albumsData)
  const albumCount = meta.total
  const hasNextPage = meta.currentPage < meta.lastPage

  const loadMore = useCallback(() => {
    setPage((p) => p + 1)
  }, [])

  return {
    artist,
    albums,
    albumCount,
    isLoading: artistLoading || albumsLoading,
    error: artistError ?? albumsError,
    loadMore,
    hasNextPage,
  }
}
