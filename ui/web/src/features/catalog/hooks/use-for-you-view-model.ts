import { useMemo } from 'react'
import { useGetRecommendationForYou } from '@/shared/api-client/gen/endpoints'

export interface ForYouSong {
  publicId: string
  title: string
  /** Internal UUID — NOT a public ID. Cannot be used for album navigation or cover art. */
  albumInternalId: string
  duration: number | null
  explanation: string
  totalScore: number
}

export function useForYouViewModel(limit = 12) {
  const { data, isLoading, error } = useGetRecommendationForYou(
    { limit },
    { query: { staleTime: 5 * 60 * 1000 } }
  )

  const songs = useMemo(() => {
    const response = data as Record<string, unknown> | undefined
    const items = Array.isArray(response?.data) ? (response.data as any[]) : []
    return items
      .filter((item) => item.song?.public_id)
      .map((item): ForYouSong => ({
        publicId: item.song.public_id,
        title: item.song.title ?? 'Unknown',
        albumInternalId: item.song.album_id ?? '',
        duration: item.song.length ?? null,
        explanation: item.explanation ?? 'Recommended for you',
        totalScore: item.total_score ?? 0,
      }))
  }, [data])

  return { songs, isLoading, error }
}
