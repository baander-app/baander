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
import { usePlayArtist } from '../../hooks/use-play-artist'
import { useContextPanelStore } from '@/features/layout/stores/context-panel-store'
import { useShortcutDisplay } from '@/shared/hooks/use-shortcut-display'

interface ArtistContextMenuProps {
  artist: ArtistContextMenuData
  children: React.ReactNode
}

export function ArtistContextMenu({ artist, children }: ArtistContextMenuProps) {
  const actions = useContextActions()
  const infoKeys = useShortcutDisplay('panel.info')
  const { playArtist } = usePlayArtist()
  const setSelectedItem = useContextPanelStore((s) => s.setSelectedItem)
  const setActiveTab = useContextPanelStore((s) => s.setActiveTab)

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent>
          <ContextMenuItem onClick={() => playArtist(artist.publicId, artist.name, { shuffle: true })}>
            Shuffle All Songs
          </ContextMenuItem>
          <ContextMenuItem onClick={() => playArtist(artist.publicId, artist.name)}>
            Play All Albums
          </ContextMenuItem>

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
