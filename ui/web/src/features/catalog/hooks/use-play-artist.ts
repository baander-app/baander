import { useCallback } from 'react'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { usePlayerStore, type Track } from '@/features/player/stores/player-store'
import { createLogger } from '@/shared/lib/logger'

const logger = createLogger('use-play-artist')

/**
 * Hook that returns a `playArtist` callback.
 * On double-click, fetches the artist's songs from the API and starts playback.
 */
export function usePlayArtist() {
  const playTrack = usePlayerStore((s) => s.playTrack)

  const playArtist = useCallback(
    async (artistPublicId: string, artistName: string) => {
      try {
        const res = await AXIOS_INSTANCE.get('/api/songs', {
          params: { artistId: artistPublicId, limit: 1000 },
        })
        const body = res.data as Record<string, unknown>
        const items = (Array.isArray(body?.data) ? body.data : []) as Record<string, unknown>[]
        if (items.length === 0) {
          logger.warn('No songs found for artist', artistPublicId)
          return
        }

        const tracks: Track[] = items.map((s) => ({
          publicId: String(s.publicId ?? ''),
          title: String(s.title ?? ''),
          artistName: artistName,
          albumName: s.albumName ? String(s.albumName) : undefined,
          albumPublicId: typeof s.albumId === 'string' ? s.albumId : undefined,
          duration: typeof s.length === 'number' ? s.length : undefined,
        }))

        playTrack(tracks[0], tracks)
      } catch (err) {
        logger.warn('Failed to fetch artist songs:', err)
      }
    },
    [playTrack],
  )

  return { playArtist }
}
