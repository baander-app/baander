import { FormField, FormFieldConfig, useFormEditor } from '@/app/ui/form';
import { useAppDispatch } from '@/app/store/hooks';
import { createNotification } from '@/app/store/notifications/notifications-slice';
import { Controller, useForm } from 'react-hook-form';
import { SmartPlaylistRuleEditor, SmartPlaylistFormData } from '../smart-playlist-rule-editor/smart-playlist-rule-editor';
import { Button, Flex, Switch, Text, TextField } from '@radix-ui/themes';
import { usePlaylistSmartCreate } from '@/app/libs/api-client/gen/endpoints/playlist/playlist';
import { useCallback } from 'react';

interface SmartPlaylistBasicForm {
  name: string;
  description: string | null;
  isPublic: boolean;
}

export function CreateSmartPlaylist() {
  const dispatch = useAppDispatch();
  const mutation = usePlaylistSmartCreate();

  // Form for basic fields using Precognition
  const { form, submit } = useFormEditor<SmartPlaylistBasicForm>({
    method: 'post',
    url: '/api/playlists/smart',
    initialData: {
      name: '',
      description: null,
      isPublic: false,
    },
    onSubmit: async (data) => {
      // Combine with rules from react-hook-form
      const rulesForm = getValues();
      const apiData = {
        name: data.name,
        description: data.description,
        isPublic: data.isPublic,
        rules: rulesForm.rules,
      };

      mutation.mutate(
        { data: apiData },
        {
          onSuccess: () => {
            dispatch(
              createNotification({
                title: 'Success',
                message: 'Smart playlist created successfully!',
                type: 'success',
                toast: true,
              })
            );
            // Close dialog
            (document.querySelector('[data-state="open"] button[data-radix-themes="true"]') as HTMLElement)?.click();
            // Reset form
            reset();
          },
          onError: (error: any) => {
            dispatch(
              createNotification({
                title: 'Error',
                message: error.response?.data?.message || 'Failed to create smart playlist',
                type: 'error',
                toast: true,
              })
            );
          },
        }
      );
    },
  });

  // Form for rules using react-hook-form (SmartPlaylistRuleEditor requires it)
  const { control, watch, getValues, reset } = useForm<SmartPlaylistFormData>({
    defaultValues: {
      rules: [{
        operator: 'and',
        rules: [{ field: 'genre', operator: 'is', value: '', maxValue: '' }]
      }],
    },
  });

  const rules = watch('rules.0.rules');

  const handleSubmit = useCallback(async (e: React.FormEvent) => {
    e.preventDefault();

    // Validate rules
    if (!rules || rules.length === 0 || (rules.length === 1 && !rules[0].value)) {
      dispatch(
        createNotification({
          title: 'Error',
          message: 'At least one rule is required',
          type: 'error',
          toast: true,
        })
      );
      return;
    }

    // Submit via Precognition
    await submit();
  }, [rules, submit, dispatch]);

  const basicFieldConfigs: FormFieldConfig<SmartPlaylistBasicForm>[] = [
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
    <form onSubmit={handleSubmit}>
      <Flex direction="column" gap="4">
        {basicFieldConfigs.map((config) => (
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

        <Flex direction="column" gap="2">
          <Text size="2" weight="bold">
            Rules
          </Text>
          <Controller
            name="rules"
            control={control}
            render={({ field }) => (
              <SmartPlaylistRuleEditor
                control={control}
                name="rules"
              />
            )}
          />
        </Flex>

        <Flex justify="end" gap="2">
          <Button
            type="button"
            variant="soft"
            onClick={() => {
              (document.querySelector('[data-state="open"] button[data-radix-themes="true"]') as HTMLElement)?.click();
              reset();
            }}
          >
            Cancel
          </Button>
          <Button type="submit" disabled={mutation.isPending || form.processing}>
            {mutation.isPending || form.processing ? 'Creating...' : 'Create Smart Playlist'}
          </Button>
        </Flex>
      </Flex>
    </form>
  );
}
