import { captureOwnership, isCurrentOwnership } from '@/shared/live/ownership'
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
import { useContextActions, type ArtistContextMenuData } from '../../hooks/use-context-actions'
import { usePlayerStore, type Track } from '@/features/player/stores/player-store'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { useContextPanelStore } from '@/features/layout/stores/context-panel-store'
import { useShortcutDisplay } from '@/shared/hooks/use-shortcut-display'

interface ArtistContextMenuProps {
  artist: ArtistContextMenuData
  children: React.ReactNode
}

export function ArtistContextMenu({ artist, children }: ArtistContextMenuProps) {
  const actions = useContextActions()
  const infoKeys = useShortcutDisplay('panel.info')
  const playTrack = usePlayerStore((s) => s.playTrack)
  const setSelectedItem = useContextPanelStore((s) => s.setSelectedItem)
  const setActiveTab = useContextPanelStore((s) => s.setActiveTab)

  const handleShuffleAll = async () => {
    const owner = captureOwnership()
    try {
      const res = await AXIOS_INSTANCE.get('/api/songs', {
        params: { artistId: artist.publicId, limit: 1000 },
      })
      if (!isCurrentOwnership(owner)) return
      const body = res.data as Record<string, unknown>
      const items = (Array.isArray(body?.data) ? body.data : []) as Record<string, unknown>[]
      if (items.length === 0) return

      const tracks: Track[] = items.filter((s) => s.available === true).map((s) => ({
        publicId: String(s.publicId ?? ''),
        available: s.available === true,
        title: String(s.title ?? ''),
        artistName: artist.name,
        albumName: s.albumName ? String(s.albumName) : undefined,
        albumPublicId: typeof s.albumId === 'string' ? s.albumId : undefined,
        duration: typeof s.length === 'number' ? s.length : undefined,
      }))

      const shuffled = [...tracks].sort(() => Math.random() - 0.5)
      if (shuffled.length > 0) playTrack(shuffled[0], shuffled)
    } catch {
      // Silently fail — no user-facing error needed for context menu action
    }
  }

  const handlePlayAll = async () => {
    const owner = captureOwnership()
    try {
      const res = await AXIOS_INSTANCE.get('/api/songs', {
        params: { artistId: artist.publicId, limit: 1000 },
      })
      if (!isCurrentOwnership(owner)) return
      const body = res.data as Record<string, unknown>
      const items = (Array.isArray(body?.data) ? body.data : []) as Record<string, unknown>[]
      if (items.length === 0) return

      const tracks: Track[] = items.filter((s) => s.available === true).map((s) => ({
        publicId: String(s.publicId ?? ''),
        available: s.available === true,
        title: String(s.title ?? ''),
        artistName: artist.name,
        albumName: s.albumName ? String(s.albumName) : undefined,
        albumPublicId: typeof s.albumId === 'string' ? s.albumId : undefined,
        duration: typeof s.length === 'number' ? s.length : undefined,
      }))

      if (tracks.length > 0) playTrack(tracks[0], tracks)
    } catch {
      // Silently fail — no user-facing error needed for context menu action
    }
  }

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent>
          <ContextMenuItem onClick={handleShuffleAll}>Shuffle All Songs</ContextMenuItem>
          <ContextMenuItem onClick={handlePlayAll}>Play All Albums</ContextMenuItem>

          <ContextMenuSeparator />

          <ContextMenuSub>
            <ContextMenuSubTrigger>Add to Playlist</ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem>Choose Playlist…</ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>

          <ContextMenuSeparator />

          <ContextMenuItem onClick={() => {
            setSelectedItem({ type: 'artist', publicId: artist.publicId })
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
    </>
  )
}
