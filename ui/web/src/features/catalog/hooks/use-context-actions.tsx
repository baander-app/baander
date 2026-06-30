import { useCallback, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { usePlayerStore, type Track } from '@/features/player/stores/player-store'
import { AddToPlaylistDialog } from '@/features/playlist/components/AddToPlaylistDialog'

export interface SongContextMenuData {
  publicId: string
  title: string
  artistName?: string
  albumName?: string
  duration?: number
  albumId?: string
  artistId?: string
}

export interface AlbumContextMenuData {
  publicId: string
  title: string
  artistName?: string
  artistPublicId?: string
}

export interface ArtistContextMenuData {
  publicId: string
  name: string
}

export function useContextActions() {
  const playTrack = usePlayerStore((s) => s.playTrack)
  const insertAfterCurrent = usePlayerStore((s) => s.insertAfterCurrent)
  const addToQueue = usePlayerStore((s) => s.addToQueue)
  const navigate = useNavigate()

  const [playlistDialogOpen, setPlaylistDialogOpen] = useState(false)
  const [playlistSongId, setPlaylistSongId] = useState<string | null>(null)

  const playSong = useCallback(
    (song: SongContextMenuData, queue?: Track[]) => {
      const track: Track = {
        publicId: song.publicId,
        title: song.title,
        artistName: song.artistName,
        albumName: song.albumName,
        albumPublicId: song.albumId,
        duration: song.duration,
      }
      playTrack(track, queue)
    },
    [playTrack],
  )

  const playNext = useCallback(
    (tracks: Track[]) => {
      insertAfterCurrent(tracks)
    },
    [insertAfterCurrent],
  )

  const playLast = useCallback(
    (track: Track) => {
      addToQueue(track)
    },
    [addToQueue],
  )

  const goToAlbum = useCallback(
    (albumId: string) => {
      navigate(`/albums/${albumId}`)
    },
    [navigate],
  )

  const goToArtist = useCallback(
    (artistId: string) => {
      navigate(`/artists/${artistId}`)
    },
    [navigate],
  )

  const addToPlaylist = useCallback(
    (songIds: string[]) => {
      // For now, opens dialog for the first song
      const songId = songIds[0]
      if (!songId) return
      setPlaylistSongId(songId)
      setPlaylistDialogOpen(true)
    },
    [],
  )

  const toggleLove = useCallback(
    (_songId: string) => {
      // No-op placeholder — backend endpoint exists, will be wired later
    },
    [],
  )

  const goToDuplicates = useCallback(
    (albumPublicId: string) => {
      navigate(`/admin/library?tab=duplicates&album=${albumPublicId}`)
    },
    [navigate],
  )

  const playlistDialog =
    playlistSongId !== null ? (
      <AddToPlaylistDialog
        open={playlistDialogOpen}
        onOpenChange={setPlaylistDialogOpen}
        songId={playlistSongId}
      />
    ) : null

  return {
    playSong,
    playNext,
    playLast,
    goToAlbum,
    goToArtist,
    addToPlaylist,
    toggleLove,
    goToDuplicates,
    playlistDialog,
  }
}
