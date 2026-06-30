import { useMemo, useState, useEffect, useRef } from 'react'
import {
  useGetActivityHistory,
  type GetActivityHistoryParams,
} from '@/shared/api-client/gen/endpoints'
import type { ActivityEntry } from '../types/activity'
import { getTimePeriodLabel, PERIOD_ORDER, type TimePeriod } from '@/shared/utils/format-relative-time'

// eslint-disable-next-line @typescript-eslint/no-explicit-any
function asString(val: any): string {
  return val ?? ''
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
function asNumber(val: any): number | undefined {
  return typeof val === 'number' ? val : undefined
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
  error: unknown
  loadMore: () => void
  hasMore: boolean
  refetch: () => void
}

export function useActivityViewModel({
  limit = 50,
}: UseActivityViewModelOptions = {}): UseActivityViewModelReturn {
  const [offset, setOffset] = useState(0)
  const [accumulated, setAccumulated] = useState<ActivityEntry[]>([])
  const mountedRef = useRef(true)

  // Reset accumulated entries on mount
  useEffect(() => {
    mountedRef.current = true
    setAccumulated([])
    return () => {
      mountedRef.current = false
    }
  }, [])

  const params: GetActivityHistoryParams = useMemo(
    () => ({ limit, offset }),
    [limit, offset],
  )

  const { data, isLoading, error, refetch } = useGetActivityHistory(params)

  // Parse raw entries from API response
  const rawEntries: ActivityEntry[] = useMemo(() => {
    const response = data as Record<string, unknown> | undefined
    const raw = Array.isArray(response?.data) ? (response?.data as unknown[]) : []
    return raw.map((item) => {
      const entry = item as Record<string, unknown>
      return {
        uuid: asString(entry.uuid),
        publicId: asString(entry.publicId),
        userId: asString(entry.userId),
        activityType: asString(entry.activityType),
        songId: asString(entry.songId) || null,
        albumId: asString(entry.albumId) || null,
        artistId: asString(entry.artistId) || null,
        movieId: asString(entry.movieId) || null,
        playCount: asNumber(entry.playCount) ?? 0,
        love: entry.love === true,
        lastPlayedAt: asString(entry.lastPlayedAt) || null,
        lastPlatform: asString(entry.lastPlatform) || null,
        lastPlayer: asString(entry.lastPlayer) || null,
        createdAt: asString(entry.createdAt),
        songTitle: asString(entry.songTitle) || null,
        artistName: asString(entry.artistName) || null,
        albumName: asString(entry.albumName) || null,
      } satisfies ActivityEntry
    })
  }, [data])

  // Accumulate entries when new data arrives
  useEffect(() => {
    if (rawEntries.length > 0) {
      setAccumulated((prev) => {
        if (prev.length === 0) return rawEntries
        // Avoid duplicates: only append entries whose uuid isn't already present
        const existingIds = new Set(prev.map((e) => e.uuid))
        const newItems = rawEntries.filter((e) => !existingIds.has(e.uuid))
        return [...prev, ...newItems]
      })
    }
  }, [rawEntries])

  const entries = accumulated

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

  const totalReceived = entries.length
  const hasMore = totalReceived >= limit

  const loadMore = () => {
    setOffset((prev) => prev + limit)
  }

  return {
    groups,
    isLoading,
    error,
    loadMore,
    hasMore,
    refetch,
  }
}

