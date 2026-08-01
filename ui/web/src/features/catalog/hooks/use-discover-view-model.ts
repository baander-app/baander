import { useMemo, useCallback } from 'react'
import { useGetRecommendationIndex } from '@/shared/api-client/gen/endpoints'
import type { Recommendation, RecommendationCluster } from '../types/recommendation'

interface ApiRecommendation {
  id?: string
  name?: string
  source_type?: string
  source_id?: string
  target_type?: string
  target_id?: string
  score?: number
  position?: number
  user_id?: string | null
  created_at?: string
  updated_at?: string
  // Enriched fields from backend
  sourceName?: string | null
  targetTitle?: string | null
  targetArtistName?: string | null
  coverImageUrl?: string | null
}

interface UseDiscoverViewModelReturn {
  clusters: RecommendationCluster[]
  isLoading: boolean
  error: unknown
  refresh: () => void
}

export function useDiscoverViewModel(): UseDiscoverViewModelReturn {
  const { data, isLoading, error, refetch } = useGetRecommendationIndex({ limit: 200 })

  const clusters = useMemo(() => {
    const response = data as { data?: ApiRecommendation[] } | undefined
    const items = response?.data
    if (!items?.length) return []

    const grouped = new Map<string, Recommendation[]>()

    for (const raw of items) {
      if (!raw.source_type || !raw.source_id) continue
      const rec: Recommendation = {
        id: raw.id ?? '',
        name: raw.name ?? '',
        source_type: raw.source_type,
        source_id: raw.source_id,
        target_type: raw.target_type ?? '',
        target_id: raw.target_id ?? '',
        score: raw.score ?? 0,
        position: raw.position ?? 0,
        user_id: raw.user_id ?? null,
        created_at: raw.created_at ?? '',
        updated_at: raw.updated_at ?? '',
        // Enriched fields
        sourceName: raw.sourceName ?? null,
        targetTitle: raw.targetTitle ?? null,
        targetArtistName: raw.targetArtistName ?? null,
        coverImageUrl: raw.coverImageUrl ?? null,
      }

      const key = `${raw.source_type}:${raw.source_id}`
      const group = grouped.get(key)
      if (group) {
        group.push(rec)
      } else {
        grouped.set(key, [rec])
      }
    }

    const result: RecommendationCluster[] = []
    for (const [key, recs] of grouped) {
      const [sourceType, sourceId] = key.split(':')
      const sorted = recs.sort((a, b) => a.position - b.position)
      // Use enriched sourceName from the API, fallback to sourceId
      const sourceName = recs[0]?.sourceName ?? sourceId
      result.push({
        sourceId,
        sourceType,
        sourceName,
        items: sorted,
      })
    }

    return result
  }, [data])

  const refresh = useCallback(() => {
    refetch()
  }, [refetch])

  return { clusters, isLoading, error, refresh }
}
