import { ContextMenu } from '@radix-ui/themes';
import { usePlaylistServiceDeleteApiPlaylistsByPlaylist } from '@/api-client/queries';

export interface PlaylistLayoutContextMenuProps {
  id: string;
}

export function PlaylistLayoutContextMenu({id}: PlaylistLayoutContextMenuProps) {
  const deleteMutation = usePlaylistServiceDeleteApiPlaylistsByPlaylist({

  });

  const handleDelete = () => {
    deleteMutation.mutate({
      playlist: id,

    })
  }

  return (
    <ContextMenu.Content>
      <ContextMenu.Item>Play</ContextMenu.Item>
      <ContextMenu.Item>Shuffle</ContextMenu.Item>
      <ContextMenu.Item>Play Next</ContextMenu.Item>
      <ContextMenu.Item>Play Later</ContextMenu.Item>
      <ContextMenu.Separator />
      <ContextMenu.Item>Duplicate</ContextMenu.Item>
      <ContextMenu.Separator />
      <ContextMenu.Item onClick={() => handleDelete()}>Delete</ContextMenu.Item>
    </ContextMenu.Content>
  )
}