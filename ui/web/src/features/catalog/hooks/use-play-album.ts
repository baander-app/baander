import { captureOwnership, isCurrentOwnership } from '@/shared/live/ownership'
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
    available: s.available === true,
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
      const owner = captureOwnership()
      try {
        const response = await getAlbumShow(albumPublicId)
        if (!isCurrentOwnership(owner)) return
        const songs = response.data?.songs
        if (!songs || songs.length === 0) {
          logger.warn('No songs found for album', albumPublicId)
          return
        }
        const tracks = songResourcesToTracks(songs, albumTitle, albumPublicId).filter((track) => track.available === true)
        if (tracks.length > 0) playTrack(tracks[0], tracks)
      } catch (err) {
        logger.warn('Failed to fetch album tracks:', err)
      }
    },
    [playTrack],
  )

  return { playAlbum }
}
