import { useAppDispatch } from '@/app/store/hooks';
import { createNotification } from '@/app/store/notifications/notifications-slice';
import { PlaylistResource } from '@/app/libs/api-client/gen/models';
import { Button, Callout, Flex, Text, TextField } from '@radix-ui/themes';
import { useCallback } from 'react';
import { usePlaylistUpdate } from '@/app/libs/api-client/gen/endpoints/playlist/playlist.ts';

interface PlaylistEditorProps {
  playlist: PlaylistResource;
  onSuccess?: () => void;
}

export function PlaylistEditor({ playlist, onSuccess }: PlaylistEditorProps) {
  const dispatch = useAppDispatch();
  const updateMutation = usePlaylistUpdate();

  const handleSubmit = useCallback((event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    const formData = new FormData(event.currentTarget);
    const data = {
      name: formData.get('name') as string,
      description: formData.get('description') as string | null,
      isPublic: formData.get('isPublic') === 'true',
    };

    updateMutation.mutate(
      {
        playlist: playlist.publicId,
        data,
      },
      {
        onSuccess: () => {
          dispatch(
            createNotification({
              title: 'Success',
              message: 'Playlist updated successfully!',
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
              message: error.response?.data?.message || 'Failed to update playlist',
              type: 'error',
              toast: true,
            })
          );
        },
      }
    );
  }, [updateMutation, dispatch, onSuccess, playlist.publicId]);

  return (
    <form onSubmit={handleSubmit}>
      <Flex direction="column" gap="4">
        <Flex direction="column" gap="2">
          <Text as="label" size="2" weight="bold">
            Name
          </Text>
          <TextField.Root
            name="name"
            defaultValue={playlist.name}
            required
            placeholder="Playlist name"
          />
        </Flex>

        <Flex direction="column" gap="2">
          <Text as="label" size="2" weight="bold">
            Description
          </Text>
          <TextField.Root
            name="description"
            defaultValue={playlist.description ?? ''}
            placeholder="Optional description"
          />
        </Flex>

        <Flex gap="2" align="center">
          <Text as="label" size="2" weight="bold">
            Public
          </Text>
          <input
            type="checkbox"
            name="isPublic"
            defaultChecked={playlist.isPublic === "1"}
            value="true"
            style={{ width: '16px', height: '16px' }}
          />
          <Text size="1" color="gray">
            Anyone with the link can view this playlist
          </Text>
        </Flex>

        {updateMutation.error && (
          <Callout.Root color="red">
            <Callout.Text>
              {updateMutation.error.response?.data?.message || 'Failed to update playlist'}
            </Callout.Text>
          </Callout.Root>
        )}

        <Flex gap="2" mt="2">
          <Button type="submit" disabled={updateMutation.isPending}>
            {updateMutation.isPending ? 'Saving...' : 'Save Changes'}
          </Button>
        </Flex>
      </Flex>
    </form>
  );
}
