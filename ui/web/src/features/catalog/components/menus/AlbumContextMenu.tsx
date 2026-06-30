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
import { useContextActions, type AlbumContextMenuData } from '../../hooks/use-context-actions'
import { useContextPanelStore } from '@/features/layout/stores/context-panel-store'
import { useAdminCheck } from '@/features/auth/hooks/use-admin-check'
import { useShortcutDisplay } from '@/shared/hooks/use-shortcut-display'
import { getAlbumShow } from '@/shared/api-client/gen/endpoints'
import { songResourcesToTracks } from '../../hooks/use-play-album'

interface AlbumContextMenuProps {
  album: AlbumContextMenuData
  /** Optional album tracks for queue operations. If not provided, Play All/Shuffle only set the single album entry. */
  tracks?: Track[]
  children: React.ReactNode
}

export function AlbumContextMenu({ album, tracks, children }: AlbumContextMenuProps) {
  const actions = useContextActions()
  const infoKeys = useShortcutDisplay('panel.info')
  const playTrack = usePlayerStore((s) => s.playTrack)
  const insertAfterCurrent = usePlayerStore((s) => s.insertAfterCurrent)
  const addToQueue = usePlayerStore((s) => s.addToQueue)
  const setSelectedItem = useContextPanelStore((s) => s.setSelectedItem)
  const setActiveTab = useContextPanelStore((s) => s.setActiveTab)

  const { isAdmin } = useAdminCheck()

  const handleFetchTracks = async (): Promise<Track[]> => {
    if (tracks && tracks.length > 0) return tracks
    try {
      const response = await getAlbumShow(album.publicId)
      const songs = response.data?.data?.songs
      if (!songs || songs.length === 0) return []
      return songResourcesToTracks(songs, album.title, album.publicId)
    } catch {
      return []
    }
  }

  const handlePlayAll = async () => {
    const fetched = await handleFetchTracks()
    if (fetched.length > 0) playTrack(fetched[0], fetched)
  }

  const handleShuffleAll = async () => {
    const fetched = await handleFetchTracks()
    if (fetched.length > 0) {
      const shuffled = [...fetched].sort(() => Math.random() - 0.5)
      playTrack(shuffled[0], shuffled)
    }
  }

  const handlePlayNext = async () => {
    const fetched = await handleFetchTracks()
    if (fetched.length > 0) insertAfterCurrent(fetched)
  }

  const handlePlayLast = async () => {
    const fetched = await handleFetchTracks()
    if (fetched.length > 0) {
      for (const t of fetched) addToQueue(t)
    }
  }

  const handleAddToPlaylist = async () => {
    const fetched = await handleFetchTracks()
    if (fetched.length > 0) actions.addToPlaylist(fetched.map((t) => t.publicId))
  }

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent>
          <ContextMenuItem onClick={handlePlayAll}>Play All</ContextMenuItem>
          <ContextMenuItem onClick={handleShuffleAll}>Shuffle All</ContextMenuItem>
          <ContextMenuItem onClick={handlePlayNext}>Play Next</ContextMenuItem>
          <ContextMenuItem onClick={handlePlayLast}>Play Last</ContextMenuItem>

          <ContextMenuSeparator />

          {album.artistPublicId && (
            <ContextMenuItem onClick={() => actions.goToArtist(album.artistPublicId!)}>
              Go to Artist
            </ContextMenuItem>
          )}

          <ContextMenuSeparator />

          <ContextMenuSub>
            <ContextMenuSubTrigger>Add to Playlist</ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem onClick={handleAddToPlaylist}>
                Choose Playlist…
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>

          <ContextMenuSeparator />

          <ContextMenuItem onClick={() => {
            setSelectedItem({ type: 'album', publicId: album.publicId })
            setActiveTab('info')
          }}>
            Get Info
            {infoKeys && (
              <ContextMenuShortcut>{infoKeys.join('')}</ContextMenuShortcut>
            )}
          </ContextMenuItem>

          {isAdmin && (
            <>
              <ContextMenuSeparator />

              <ContextMenuItem onClick={() => actions.goToDuplicates?.(album.publicId)}>
                Find Duplicates…
              </ContextMenuItem>
            </>
          )}
        </ContextMenuContent>
      </ContextMenu>
      {actions.playlistDialog}
    </>
  )
}
