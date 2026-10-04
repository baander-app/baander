import { useState, useCallback, useRef, useMemo, useEffect } from 'react'
import {
  useGetSongIndex,
  getSongIndex,
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
  const resp = data as Record<string, unknown> | undefined
  return Array.isArray(resp?.data) ? (resp!.data as Record<string, unknown>[]) : []
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
  fetchMore: () => void
}

export function useSongList({ sort, pageSize = 100 }: UseSongListOptions): UseSongListResult {
  const [accumulated, setAccumulated] = useState<ListSongData[]>([])
  const [total, setTotal] = useState(0)
  const [isFetchingMore, setIsFetchingMore] = useState(false)
  const nextCursorRef = useRef<string | null>(null)
  const hasNextPageRef = useRef(false)

  const params: GetSongIndexParams = useMemo(
    () => ({
      limit: pageSize,
      ...(sort.field && sort.direction ? { sort: sort.field, order: sort.direction } : {}),
    }),
    [sort, pageSize],
  )

  const { data: firstPageData, isLoading } = useGetSongIndex(params)

  // Clear accumulated songs the moment sort/page changes, so songs from the previous sort
  // can never mix with the incoming page if fetchMore fires during the refetch window.
  // The firstPageData effect below repopulates once the new page arrives.
  useEffect(() => {
    setAccumulated([])
    setTotal(0)
    nextCursorRef.current = null
    hasNextPageRef.current = false
  }, [params])

  // When first page loads or sort changes, reset accumulated songs
  useEffect(() => {
    if (!firstPageData) return
    const raw = parseResponseData(firstPageData)

    const songs = raw.map((s, i) => parseSong(s, i + 1))
    setAccumulated(songs)

    const meta = extractCursorMeta(firstPageData)
    setTotal(meta.total)
    nextCursorRef.current = meta.next_cursor
    hasNextPageRef.current = meta.has_next_page
  }, [firstPageData])

  const fetchMore = useCallback(async () => {
    if (!hasNextPageRef.current || isFetchingMore || !nextCursorRef.current) return

    setIsFetchingMore(true)
    try {
      const nextParams: GetSongIndexParams = {
        ...params,
        cursor: nextCursorRef.current,
      }
      const response = await getSongIndex(nextParams)
      const meta = extractCursorMeta(response)
      const rawData = parseResponseData(response)

      setAccumulated((prev) => {
        const offset = prev.length
        const newSongs = rawData.map((s, i) => parseSong(s, offset + i + 1))
        return [...prev, ...newSongs]
      })

      setTotal(meta.total)
      nextCursorRef.current = meta.next_cursor
      hasNextPageRef.current = meta.has_next_page
    } finally {
      setIsFetchingMore(false)
    }
  }, [params, isFetchingMore])

  const hasNextPage = hasNextPageRef.current

  return {
    songs: accumulated,
    total,
    isLoading,
    isFetchingMore,
    hasNextPage,
    fetchMore,
  }
}
