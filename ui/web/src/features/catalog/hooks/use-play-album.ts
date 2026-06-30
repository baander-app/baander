import { useCallback } from 'react'
import { getAlbumShow, type SongResource } from '@/shared/api-client/gen/endpoints'
import { usePlayerStore, type Track } from '@/features/player/stores/player-store'
import { createLogger } from '@/shared/lib/logger'

const logger = createLogger('use-play-album')

/**
 * Convert API SongResource[] to player Track[].
 * Shared by AlbumGridItem, AlbumListItem, and any component that needs
 * to play an album without having songs pre-loaded.
 */
export function songResourcesToTracks(
  songs: SongResource[],
  albumTitle: string,
  albumPublicId: string,
): Track[] {
  return songs.map((s) => ({
    publicId: s.publicId,
    title: s.title,
    artistName: s.artistName ?? undefined,
    albumName: albumTitle,
    albumPublicId,
    duration: s.length ?? undefined,
  }))
}

/**
 * Hook that returns a `playAlbum` callback.
 * On double-click, fetches album songs from the API and starts playback.
 */
export function usePlayAlbum() {
  const playTrack = usePlayerStore((s) => s.playTrack)

  const playAlbum = useCallback(
    async (albumPublicId: string, albumTitle: string) => {
      try {
        const response = await getAlbumShow(albumPublicId)
        const songs = response.data?.data?.songs
        if (!songs || songs.length === 0) {
          logger.warn('No songs found for album', albumPublicId)
          return
        }
        const tracks = songResourcesToTracks(songs, albumTitle, albumPublicId)
        playTrack(tracks[0], tracks)
      } catch (err) {
        logger.warn('Failed to fetch album tracks:', err)
      }
    },
    [playTrack],
  )

  return { playAlbum }
}
