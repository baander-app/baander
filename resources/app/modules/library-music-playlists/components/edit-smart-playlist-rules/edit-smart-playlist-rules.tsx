import { useAppDispatch } from '@/app/store/hooks';
import { createNotification } from '@/app/store/notifications/notifications-slice';
import { Controller, useForm } from 'react-hook-form';
import { SmartPlaylistRuleEditor, SmartPlaylistFormData } from '../smart-playlist-rule-editor/smart-playlist-rule-editor';
import { Button, Flex, Text } from '@radix-ui/themes';
import { PlaylistResource } from '@/app/libs/api-client/gen/models';
import { usePlaylistSmartUpdate } from '@/app/libs/api-client/gen/endpoints/playlist/playlist';
import { useCallback, useEffect } from 'react';

interface EditSmartPlaylistRulesProps {
  playlist: PlaylistResource;
  onSuccess?: () => void;
  onCancel?: () => void;
}

export function EditSmartPlaylistRules({ playlist, onSuccess, onCancel }: EditSmartPlaylistRulesProps) {
  const dispatch = useAppDispatch();
  const updateMutation = usePlaylistSmartUpdate();

  // Form for rules using react-hook-form (SmartPlaylistRuleEditor requires it)
  const { control, reset, handleSubmit } = useForm<SmartPlaylistFormData>({
    defaultValues: {
      rules: [{
        operator: 'and',
        rules: [{ field: 'genre', operator: 'is', value: '', maxValue: '' }]
      }],
    },
  });

  // Load existing rules when playlist changes
  useEffect(() => {
    if (playlist?.smartRules) {
      try {
        const parsedRules = typeof playlist.smartRules === 'string'
          ? JSON.parse(playlist.smartRules)
          : playlist.smartRules;

        reset({
          rules: parsedRules || [{
            operator: 'and',
            rules: [{ field: 'genre', operator: 'is', value: '', maxValue: '' }]
          }]
        });
      } catch (error) {
        console.error('Failed to parse playlist rules:', error);
        reset({
          rules: [{
            operator: 'and',
            rules: [{ field: 'genre', operator: 'is', value: '', maxValue: '' }]
          }]
        });
      }
    }
  }, [playlist, reset]);

  const onSubmit = useCallback(async (data: SmartPlaylistFormData) => {
    // Validate rules
    if (!data.rules || data.rules.length === 0) {
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

    const hasEmptyRules = data.rules.some(group =>
      !group.rules || group.rules.length === 0 || group.rules.some(rule => !rule.value)
    );

    if (hasEmptyRules) {
      dispatch(
        createNotification({
          title: 'Error',
          message: 'Please fill in all rule values',
          type: 'error',
          toast: true,
        })
      );
      return;
    }

    updateMutation.mutate(
      {
        playlist: playlist.publicId,
        data: {
          rules: data.rules as any,
        },
      },
      {
        onSuccess: () => {
          dispatch(
            createNotification({
              title: 'Success',
              message: 'Smart playlist rules updated successfully!',
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
              message: error.response?.data?.message || 'Failed to update smart playlist rules',
              type: 'error',
              toast: true,
            })
          );
        },
      }
    );
  }, [updateMutation, dispatch, playlist, onSuccess]);

  return (
    <form onSubmit={handleSubmit(onSubmit)}>
      <Flex direction="column" gap="4">
        <Flex direction="column" gap="2">
          <Text size="2" weight="bold">
            Rules
          </Text>
          <Text size="1" color="gray">
            Define rules to automatically populate this playlist
          </Text>
          <Controller
            name="rules"
            control={control}
            render={() => (
              <SmartPlaylistRuleEditor
                control={control}
                name="rules"
              />
            )}
          />
        </Flex>

        <Flex justify="end" gap="2" mt="4">
          {onCancel && (
            <Button
              type="button"
              variant="soft"
              onClick={onCancel}
            >
              Cancel
            </Button>
          )}
          <Button type="submit" disabled={updateMutation.isPending}>
            {updateMutation.isPending ? 'Saving...' : 'Save Rules'}
          </Button>
        </Flex>
      </Flex>
    </form>
  );
}
