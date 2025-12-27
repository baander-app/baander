import { useAppDispatch } from '@/app/store/hooks';
import { createNotification } from '@/app/store/notifications/notifications-slice';
import { ContextMenu, Flex, Text } from '@radix-ui/themes';
import { memo, useCallback } from 'react';
import { Iconify } from '@/app/ui/icons/iconify';
import { usePlaylistAddSong, usePlaylistIndex } from '@/app/libs/api-client/gen/endpoints/playlist/playlist.ts';

interface AddToPlaylistMenuProps {
  songPublicId: string;
  librarySlug: string;
  onSuccess?: () => void;
}

export const AddToPlaylistMenu = memo(({ songPublicId, librarySlug: _librarySlug, onSuccess }: AddToPlaylistMenuProps) => {
  const dispatch = useAppDispatch();
  const { data: playlistsData, isLoading } = usePlaylistIndex();
  const addMutation = usePlaylistAddSong();

  const handleAddToPlaylist = useCallback((playlistPublicId: string, playlistName: string) => {
    addMutation.mutate(
      {
        playlist: playlistPublicId,
        song: songPublicId,
      },
      {
        onSuccess: () => {
          dispatch(
            createNotification({
              title: 'Success',
              message: `Added to ${playlistName}`,
              type: 'success',
              toast: true,
            })
          );
          onSuccess?.();
        },
        onError: (error: any) => {
          dispatch(
            createNotification({
              title: 'Error',
              message: error.response?.data?.message || 'Failed to add to playlist',
              type: 'error',
              toast: true,
            })
          );
        },
      }
    );
  }, [addMutation, dispatch, songPublicId, onSuccess]);

  if (isLoading) {
    return (
      <ContextMenu.Content>
        <Flex align="center" gap="2" p="2">
          <Text size="1">Loading playlists...</Text>
        </Flex>
      </ContextMenu.Content>
    );
  }

  const playlists = playlistsData?.data ?? [];

  if (playlists.length === 0) {
    return (
      <ContextMenu.Content>
        <Flex align="center" gap="2" p="2">
          <Iconify icon="ph:playlist" width={16} height={16} />
          <Text size="1">No playlists yet</Text>
        </Flex>
      </ContextMenu.Content>
    );
  }

  return (
    <ContextMenu.Sub>
      <ContextMenu.SubTrigger>
        <Flex align="center" gap="2">
          <Iconify icon="ph:playlist-plus" width={16} height={16} />
          Add to Playlist
        </Flex>
      </ContextMenu.SubTrigger>
      <ContextMenu.SubContent>
        {playlists.map((playlist) => (
          <ContextMenu.Item
            key={playlist.publicId}
            onClick={() => handleAddToPlaylist(playlist.publicId, playlist.name)}
          >
            <Flex align="center" gap="2">
              {playlist.isSmart === "1" && (
                <Iconify icon="carbon:rule" width={14} height={14} style={{ color: 'var(--accent-9)' }} />
              )}
              <Text>{playlist.name}</Text>
            </Flex>
          </ContextMenu.Item>
        ))}
      </ContextMenu.SubContent>
    </ContextMenu.Sub>
  );
});

AddToPlaylistMenu.displayName = 'AddToPlaylistMenu';
