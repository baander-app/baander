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
    return (data?.data ?? []).flatMap((item): ForYouSong[] => {
      const song = item.song
      if (typeof song?.public_id !== 'string' || !song.public_id) return []
      return [{
        publicId: song.public_id,
        title: typeof song.title === 'string' ? song.title : 'Unknown',
        albumInternalId: typeof song.album_id === 'string' ? song.album_id : '',
        duration: typeof song.length === 'number' ? song.length : null,
        explanation: item.explanation ?? 'Recommended for you',
        totalScore: item.total_score ?? 0,
      }]
    })
  }, [data])

  return { songs, isLoading, error }
}
