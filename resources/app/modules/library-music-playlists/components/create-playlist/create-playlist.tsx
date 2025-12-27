import { FormField, FormFieldConfig, useFormEditor } from '@/app/ui/form';
import { useAppDispatch } from '@/app/store/hooks';
import { createNotification } from '@/app/store/notifications/notifications-slice';
import { usePlaylistStore } from '@/app/libs/api-client/gen/endpoints/playlist/playlist';
import { Button, Flex, Switch, Text } from '@radix-ui/themes';
import { Dialog } from '@radix-ui/themes';
import styles from './create-playlist.module.scss';

interface CreatePlaylistForm {
  name: string;
  description: string | null;
  isPublic: boolean;
}

export function CreatePlaylist() {
  const dispatch = useAppDispatch();
  const storeMutation = usePlaylistStore();

  const { form, submit } = useFormEditor<CreatePlaylistForm>({
    method: 'post',
    url: '/api/playlists',
    initialData: {
      name: '',
      description: null,
      isPublic: false,
    },
    onSubmit: async (data) => {
      storeMutation.mutate(
        {
          data: {
            name: data.name,
            description: data.description,
            isPublic: data.isPublic,
          },
        },
        {
          onSuccess: () => {
            dispatch(
              createNotification({
                title: 'Success',
                message: 'Playlist created successfully!',
                type: 'success',
                toast: true,
              })
            );
            // Close dialog by triggering a click on the close button
            (document.querySelector('[data-state="open"] button[data-radix-themes="true"]') as HTMLElement)?.click();
          },
          onError: (error: any) => {
            dispatch(
              createNotification({
                title: 'Error',
                message: error.response?.data?.message || 'Failed to create playlist',
                type: 'error',
                toast: true,
              })
            );
          },
        }
      );
    },
  });

  const fieldConfigs: FormFieldConfig<CreatePlaylistForm>[] = [
    {
      name: 'name',
      label: 'Name',
      type: 'text',
      placeholder: 'Playlist name',
    },
    {
      name: 'description',
      label: 'Description',
      type: 'textarea',
      placeholder: 'Optional description',
    },
    {
      name: 'isPublic',
      label: 'Public',
      type: 'checkbox',
      description: 'Anyone with the link can view this playlist',
    },
  ];

  return (
    <Flex direction="column" gap="4">
      {fieldConfigs.map((config) => (
        <FormField
          key={config.name}
          config={config}
          value={form.data[config.name]}
          onChange={(value) => form.setData(config.name, value)}
          errors={form.errors}
          lockMode={false}
          isFieldLocked={() => false}
          onToggleLock={() => {}}
        />
      ))}

      <Flex justify="end" gap="2">
        <Button
          type="button"
          variant="soft"
          onClick={() => (document.querySelector('[data-state="open"] button[data-radix-themes="true"]') as HTMLElement)?.click()}
        >
          Cancel
        </Button>
        <Button onClick={submit} disabled={storeMutation.isPending || form.processing}>
          {storeMutation.isPending || form.processing ? 'Creating...' : 'Create Playlist'}
        </Button>
      </Flex>
    </Flex>
  );
}

