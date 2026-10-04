import { useMemo } from 'react'
import { useInfiniteQuery } from '@tanstack/react-query'
import {
  getActivityHistory,
  getGetActivityHistoryQueryKey,
} from '@/shared/api-client/gen/endpoints'
import type { ActivityEntry } from '../types/activity'
import { getTimePeriodLabel, PERIOD_ORDER, type TimePeriod } from '@/shared/utils/format-relative-time'

function asString(val: unknown): string {
  return typeof val === 'string' ? val : ''
}

export interface ActivityGroup {
  label: TimePeriod
  items: ActivityEntry[]
}

interface UseActivityViewModelOptions {
  limit?: number
}

interface UseActivityViewModelReturn {
  groups: ActivityGroup[]
  isLoading: boolean
  isFetchingMore: boolean
  isFetchMoreError: boolean
  error: unknown
  loadMore: () => void
  hasMore: boolean
  refetch: () => void
}

export function useActivityViewModel({
  limit = 50,
}: UseActivityViewModelOptions = {}): UseActivityViewModelReturn {
  const {
    data, isLoading, error, refetch, fetchNextPage, hasNextPage,
    isFetchingNextPage, isFetchNextPageError,
  } = useInfiniteQuery({
    queryKey: [...getGetActivityHistoryQueryKey({ limit }), 'infinite'],
    initialPageParam: 0,
    queryFn: ({ pageParam, signal }) => getActivityHistory({ limit, offset: pageParam }, { signal }),
    getNextPageParam: (lastPage, _pages, lastOffset) =>
      (lastPage.data?.length ?? 0) >= limit ? lastOffset + limit : undefined,
  })

  const entries = useMemo(() => {
    const byUuid = new Map<string, ActivityEntry>()
    for (const page of data?.pages ?? []) {
      for (const entry of page.data ?? []) {
        byUuid.set(entry.uuid, {
          uuid: entry.uuid,
          publicId: entry.publicId,
          userId: entry.userId,
          activityType: entry.activityType,
          songId: entry.songId ?? null,
          albumId: entry.albumId ?? null,
          artistId: entry.artistId ?? null,
          movieId: entry.movieId ?? null,
          playCount: entry.playCount,
          love: entry.love,
          lastPlayedAt: entry.lastPlayedAt ?? null,
          lastPlatform: entry.lastPlatform ?? null,
          lastPlayer: entry.lastPlayer ?? null,
          createdAt: entry.createdAt,
          songTitle: 'songTitle' in entry ? asString(entry.songTitle) || null : null,
          artistName: 'artistName' in entry ? asString(entry.artistName) || null : null,
          albumName: 'albumName' in entry ? asString(entry.albumName) || null : null,
        })
      }
    }
    return [...byUuid.values()]
  }, [data])

  const groups: ActivityGroup[] = useMemo(() => {
    const map = new Map<TimePeriod, ActivityEntry[]>()

    for (const entry of entries) {
      const timestamp = entry.lastPlayedAt ?? entry.createdAt
      const label = getTimePeriodLabel(timestamp)
      const existing = map.get(label) ?? []
      existing.push(entry)
      map.set(label, existing)
    }

    return PERIOD_ORDER
      .filter((label) => map.has(label))
      .map((label) => ({ label, items: map.get(label)! }))
  }, [entries])

  const loadMore = () => {
    if (hasNextPage) void fetchNextPage({ cancelRefetch: false })
  }

  return {
    groups,
    isLoading,
    isFetchingMore: isFetchingNextPage,
    isFetchMoreError: isFetchNextPageError,
    error,
    loadMore,
    hasMore: hasNextPage,
    refetch,
  }
}

