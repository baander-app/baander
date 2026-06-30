import { useState, Fragment } from 'react'
import {
  ContextMenu,
  ContextMenuTrigger,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuSub,
  ContextMenuSubTrigger,
  ContextMenuSubContent,
  ContextMenuShortcut,
} from '@/shared/components/ui/context-menu'
import { usePlayerStore, type Track } from '@/features/player/stores/player-store'
import { mediator } from '@/shared/lib/mediator/bus'
import { PLAYER_ACTIONS } from '@/features/player/player-actions'
import { useContextActions, type SongContextMenuData } from '../../hooks/use-context-actions'
import { useGetPlaylistIndex, usePostPlaylistAddSong } from '@/shared/api-client/gen/endpoints'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { LyricsDialog } from '../LyricsDialog'
import { useContextPanelStore } from '@/features/layout/stores/context-panel-store'
import { useShortcutDisplay } from '@/shared/hooks/use-shortcut-display'

interface SongContextMenuProps {
  song: SongContextMenuData
  children: React.ReactNode
}

export function SongContextMenu({ song, children }: SongContextMenuProps) {
  const actions = useContextActions()
  const playPauseKeys = useShortcutDisplay('transport.play-pause')
  const lyricsKeys = useShortcutDisplay('panel.lyrics')
  const infoKeys = useShortcutDisplay('panel.info')
  const { data: playlistsData } = useGetPlaylistIndex()
  const { mutateAsync: addSongToPlaylist } = usePostPlaylistAddSong()
  const queryClient = useQueryClient()
  const playlists = (playlistsData as Record<string, unknown> | undefined)?.data as Array<{ publicId: string; name: string }> ?? []
  const insertAfterCurrent = usePlayerStore((s) => s.insertAfterCurrent)
  const addToQueue = usePlayerStore((s) => s.addToQueue)
  const [lyricsOpen, setLyricsOpen] = useState(false)
  const setSelectedItem = useContextPanelStore((s) => s.setSelectedItem)
  const setActiveTab = useContextPanelStore((s) => s.setActiveTab)

  const track: Track = {
    publicId: song.publicId,
    title: song.title,
    artistName: song.artistName,
    albumName: song.albumName,
    duration: song.duration,
  }

  const handlePlayNext = () => {
    insertAfterCurrent([track])
  }

  const handlePlayLast = () => {
    addToQueue(track)
  }

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent>
          <ContextMenuItem onClick={() => actions.playSong(song)}>
            Play
            {playPauseKeys && (
              <ContextMenuShortcut>{playPauseKeys.join('')}</ContextMenuShortcut>
            )}
          </ContextMenuItem>
          <ContextMenuItem onClick={handlePlayNext}>Play Next</ContextMenuItem>
          <ContextMenuItem onClick={handlePlayLast}>Play Last</ContextMenuItem>

          <ContextMenuSeparator />

          {song.albumId && (
            <ContextMenuItem onClick={() => actions.goToAlbum(song.albumId!)}>
              Go to Album
            </ContextMenuItem>
          )}
          {song.artistId && (
            <ContextMenuItem onClick={() => actions.goToArtist(song.artistId!)}>
              Go to Artist
            </ContextMenuItem>
          )}

          <ContextMenuSeparator />

          <ContextMenuSub>
            <ContextMenuSubTrigger>Add to Playlist</ContextMenuSubTrigger>
            <ContextMenuSubContent>
              {playlists.map((playlist) => (
                <ContextMenuItem
                  key={playlist.publicId}
                  onClick={() => {
                    addSongToPlaylist({
                      publicId: playlist.publicId,
                      data: { songId: song.publicId }
                    }, {
                      onSuccess: () => queryClient.invalidateQueries({ queryKey: ['playlists'] }),
                      onError: () => toast.error('Failed to add song to playlist'),
                    })
                  }}
                >
                  {playlist.name}
                </ContextMenuItem>
              ))}
              {playlists.length > 0 && <ContextMenuSeparator />}
              <ContextMenuItem onClick={() => actions.addToPlaylist([song.publicId])}>
                Choose Playlist…
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>

          <ContextMenuItem onClick={() => {
            const track = {
              publicId: song.publicId,
              title: song.title,
              artistName: song.artistName,
              albumName: song.albumName,
              duration: song.duration,
            }
            mediator.dispatch(PLAYER_ACTIONS.QUEUE_ADD, { track }, 'catalog')
          }}>
            Add to Queue
          </ContextMenuItem>

          <ContextMenuSeparator />

          <ContextMenuItem onClick={() => actions.toggleLove(song.publicId)}>
            Love
          </ContextMenuItem>

          <ContextMenuItem onClick={() => setLyricsOpen(true)}>
            Lyrics
            {lyricsKeys && (
              <ContextMenuShortcut>{lyricsKeys.join('')}</ContextMenuShortcut>
            )}
          </ContextMenuItem>

          <ContextMenuSeparator />

          <ContextMenuItem onClick={() => {
            setSelectedItem({ type: 'song', publicId: song.publicId })
            setActiveTab('info')
          }}>
            Get Info
            {infoKeys && (
              <ContextMenuShortcut>{infoKeys.join('')}</ContextMenuShortcut>
            )}
          </ContextMenuItem>
        </ContextMenuContent>
      </ContextMenu>
      {actions.playlistDialog}
      <LyricsDialog
        open={lyricsOpen}
        onOpenChange={setLyricsOpen}
        songPublicId={song.publicId}
        songTitle={song.title}
        artistName={song.artistName}
      />
    </>
  )
}
