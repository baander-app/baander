import { useEffect, useState } from 'react'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import type { RecentItem } from '@/features/layout/components/SidebarRecentItems'
import { createLogger } from '@/shared/lib/logger'

const logger = createLogger('RecentItems')

export interface UseRecentItemsOptions {
  limit?: number
  mediaType?: string
}

export interface UseRecentItemsResult {
  items: RecentItem[]
  isLoading: boolean
}

interface RecentItemsState extends UseRecentItemsResult {
  request: string
}

export function useRecentItems(options: UseRecentItemsOptions = {}): UseRecentItemsResult {
  const { limit = 5, mediaType } = options
  const request = JSON.stringify([limit, mediaType ?? null])
  const [state, setState] = useState<RecentItemsState>(() => ({
    request,
    items: [],
    isLoading: true,
  }))

  if (state.request !== request) {
    setState({
      request,
      items: [],
      isLoading: true,
    })
  }

  useEffect(() => {
    const controller = new AbortController()
    const params = new URLSearchParams()
    params.set('limit', String(limit))
    if (mediaType) {
      params.set('mediaType', mediaType)
    }

    AXIOS_INSTANCE.get(`/api/user/recent?${params}`, { signal: controller.signal })
      .then((res) => {
        if (controller.signal.aborted) return

        const items = ((res.data?.data ?? []) as RecentActivityItem[]).map(mapToRecentItem)
        setState({
          request,
          items,
          isLoading: false,
        })
      })
      .catch((error) => {
        if (controller.signal.aborted) return

        logger.warn('Failed to load recent items:', error)
        setState({
          request,
          items: [],
          isLoading: false,
        })
      })
    return () => {
      controller.abort()
    }
  }, [limit, mediaType, request])

  return state.request === request
    ? { items: state.items, isLoading: state.isLoading }
    : { items: [], isLoading: true }
}

/** Raw shape of a `/api/user/recent` response item (music + movies).
 *  Music fields mirror the orval-generated RecentItemResource; movie fields are
 *  returned by the same endpoint for non-song activity. All fields optional/nullable
 *  because the backend omits keys that don't apply to a given activity type. */
interface RecentActivityItem {
  publicId?: string | null
  songTitle?: string | null
  songPublicId?: string | null
  albumTitle?: string | null
  albumPublicId?: string | null
  artistName?: string | null
  coverImage?: { url?: string } | null
  lastPlayedAt?: string | null
  movieId?: string | null
  movieTitle?: string | null
  moviePublicId?: string | null
  directorName?: string | null
  posterImage?: { url?: string } | null
}

function mapToRecentItem(apiItem: RecentActivityItem): RecentItem {
  const isMovie = apiItem.movieId != null
  return {
    id: apiItem.publicId ?? '',
    title: apiItem.songTitle ?? apiItem.albumTitle ?? apiItem.movieTitle ?? 'Unknown',
    subtitle: apiItem.artistName ?? apiItem.directorName ?? '',
    timestamp: formatRelativeTime(apiItem.lastPlayedAt),
    thumbnailUrl: apiItem.coverImage?.url ?? apiItem.posterImage?.url ?? '',
    mediaType: isMovie ? 'movies' : 'music',
    publicId: (isMovie ? apiItem.moviePublicId : apiItem.publicId) ?? undefined,
  }
}

function formatRelativeTime(isoDate: string | null | undefined): string {
  if (!isoDate) return ''
  const now = Date.now()
  const then = new Date(isoDate).getTime()
  if (isNaN(then)) return ''
  const diffMs = now - then
  if (diffMs < 0) return 'just now'
  const diffMin = Math.floor(diffMs / 60_000)
  if (diffMin < 1) return 'just now'
  if (diffMin < 60) return `${diffMin}m ago`
  const diffHr = Math.floor(diffMin / 60)
  if (diffHr < 24) return `${diffHr}h ago`
  const diffDay = Math.floor(diffHr / 24)
  if (diffDay < 7) return `${diffDay}d ago`
  return new Date(isoDate).toLocaleDateString()
}
