import { useCallback, useMemo } from 'react'
import { useInfiniteQuery } from '@tanstack/react-query'
import {
  getSongIndex,
  getGetSongIndexQueryKey,
  type GetSongIndexParams,
} from '@/shared/api-client/gen/endpoints'
import type { ListSongData } from '../components/ListRow'
import type { SortState } from '../components/ListHeader'
import { extractCursorMeta } from '../utils/api-adapters'

function parseSong(raw: Record<string, unknown>, index: number): ListSongData {
  return {
    publicId: String(raw.publicId ?? ''),
    title: String(raw.title ?? ''),
    artistName: typeof raw.artistName === 'string' ? raw.artistName : undefined,
    albumName: typeof raw.albumName === 'string' ? raw.albumName : undefined,
    year: typeof raw.year === 'number' ? raw.year : undefined,
    genre: typeof raw.genre === 'string' ? raw.genre : undefined,
    duration: typeof (raw.length ?? raw.duration) === 'number' ? (raw.length ?? raw.duration) as number : undefined,
    bitrate: typeof raw.bitrate === 'number' ? raw.bitrate : undefined,
    format: typeof raw.format === 'string' ? raw.format : undefined,
    createdAt: typeof raw.createdAt === 'string' ? raw.createdAt : undefined,
    albumId: typeof raw.albumId === 'string' ? raw.albumId : undefined,
    albumPublicId: typeof raw.albumId === 'string' ? raw.albumId : undefined,
    artistId: typeof raw.artistId === 'string' ? raw.artistId : undefined,
    index,
  }
}

function parseResponseData(data: unknown): Record<string, unknown>[] {
  if (typeof data !== 'object' || data === null || !('data' in data) || !Array.isArray(data.data)) {
    return []
  }
  return data.data.filter((song): song is Record<string, unknown> =>
    typeof song === 'object' && song !== null && !Array.isArray(song),
  )
}

export interface UseSongListOptions {
  sort: SortState
  pageSize?: number
}

export interface UseSongListResult {
  songs: ListSongData[]
  total: number
  isLoading: boolean
  isFetchingMore: boolean
  hasNextPage: boolean
  isError: boolean
  isFetchMoreError: boolean
  retry: () => void
  fetchMore: () => void
}

export function useSongList({ sort, pageSize = 100 }: UseSongListOptions): UseSongListResult {
  const params: GetSongIndexParams = {
    limit: pageSize,
    ...(sort.field && sort.direction ? { sort: sort.field, order: sort.direction } : {}),
  }
  const query = useInfiniteQuery({
    queryKey: [...getGetSongIndexQueryKey(params), 'infinite'],
    initialPageParam: undefined,
    queryFn: async ({ pageParam, signal }: { pageParam: string | undefined; signal: AbortSignal }) => {
      const response: unknown = await getSongIndex(
        { ...params, ...(pageParam ? { cursor: pageParam } : {}) },
        { signal },
      )
      return { songs: parseResponseData(response), meta: extractCursorMeta(response) }
    },
    getNextPageParam: (lastPage) =>
      lastPage.meta.has_next_page ? lastPage.meta.next_cursor ?? undefined : undefined,
  })

  const songs = useMemo(
    () => query.data?.pages.flatMap((page) => page.songs).map((song, index) => parseSong(song, index + 1)) ?? [],
    [query.data],
  )
  const { fetchNextPage, refetch, hasNextPage, isFetching } = query
  const fetchMore = useCallback(() => {
    if (!hasNextPage || isFetching) return
    void fetchNextPage({ cancelRefetch: false }).catch(() => undefined)
  }, [fetchNextPage, hasNextPage, isFetching])
  const retry = useCallback(() => {
    void refetch({ cancelRefetch: false }).catch(() => undefined)
  }, [refetch])

  return {
    songs,
    total: query.data?.pages.at(-1)?.meta.total ?? 0,
    isLoading: query.isLoading,
    isFetchingMore: query.isFetchingNextPage,
    hasNextPage,
    isError: query.isError,
    isFetchMoreError: query.isFetchNextPageError,
    fetchMore,
    retry,
  }
}
