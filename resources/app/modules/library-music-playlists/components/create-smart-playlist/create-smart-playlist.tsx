import { Button, Flex, Switch, Text, TextField } from '@radix-ui/themes';
import { useForm, Controller } from 'react-hook-form';
import { useState } from 'react';
import { PlaylistService } from '../../../../api-client/requests/services.gen';
import { SmartPlaylistRuleEditor } from '../smart-playlist-rule-editor/smart-playlist-rule-editor';
import styles from './create-smart-playlist.module.scss';

interface SmartPlaylistForm {
  name: string;
  description: string;
  is_public: boolean;
  ruleGroups: Array<Array<{
    field: string;
    operator: string;
    value: string;
    maxValue?: string;
  }>>;
}


export function CreateSmartPlaylist() {
  const {
    register,
    handleSubmit,
    control,
    formState: { errors, isSubmitting },
    watch,
  } = useForm<SmartPlaylistForm>({
    defaultValues: {
      name: '',
      description: '',
      is_public: false,
      ruleGroups: [[{ field: 'genre', operator: 'is', value: '', maxValue: '' }]],
    },
  });

  // Watch the ruleGroups field to validate it has at least one rule
  const ruleGroups = watch('ruleGroups.0');

  const [error, setError] = useState<string | null>(null);

  const onSubmit = async (data: SmartPlaylistForm) => {
    try {
      setError(null);

      // Validate that at least one rule is defined
      if (data.ruleGroups.length === 0 || data.ruleGroups[0].length === 0) {
        setError('At least one rule is required');
        return;
      }

      // Transform the data to match the API's expected format
      // Since we only have one group now, we'll just use that
      const apiData = {
        ...data,
        rules: [data.ruleGroups[0]] // The API expects 'rules' as an array with a single group
      };

      await PlaylistService.postApiPlaylistsSmart({
        requestBody: apiData,
      });

      // Handle success - redirect or show success message
      window.location.href = '/library/playlists';
    } catch (err: any) {
      if (err.status === 422 && err.body?.errors) {
        // Handle validation errors
        const validationErrors = err.body.errors;
        const errorMessages = Object.values(validationErrors).flat();
        setError(errorMessages.join(', '));
      } else {
        setError(err.body?.message || 'Failed to create smart playlist');
      }
    }
  };

  return (
    <div className={styles.container}>
      <form onSubmit={handleSubmit(onSubmit)}>
        <Flex direction="column" gap="4">
          {error && (
            <Text color="red" size="2" className={styles.error}>
              {error}
            </Text>
          )}

          <Flex direction="column" gap="3">
            <div className={styles.formGroup}>
              <label className={styles.label}>
                <Text as="div" size="2" mb="1" weight="bold" className={styles.title}>
                  Name
                </Text>
                <TextField.Root
                  data-1p-ignore
                  placeholder="Playlist name"
                  className={styles.input}
                  {...register('name', { 
                    required: 'Name is required',
                    maxLength: { value: 255, message: 'Name must be at most 255 characters' }
                  })}
                />
                {errors.name && (
                  <Text color="red" size="1" className={styles.error}>
                    {errors.name.message}
                  </Text>
                )}
              </label>
            </div>

            <div className={styles.formGroup}>
              <label className={styles.label}>
                <Text as="div" size="2" mb="1" weight="bold" className={styles.title}>
                  Description
                </Text>
                <TextField.Root
                  data-1p-ignore
                  placeholder="Optional description"
                  className={styles.input}
                  {...register('description')}
                />
              </label>
            </div>

            <div className={styles.formGroup}>
              <label className={styles.label}>
                <Flex align="center" gap="2">
                  <Text as="div" size="2" weight="bold" className={styles.title}>
                    Public
                  </Text>
                  <Controller
                    name="is_public"
                    control={control}
                    render={({ field }) => (
                      <Switch
                        checked={field.value}
                        onCheckedChange={field.onChange}
                      />
                    )}
                  />
                </Flex>
              </label>
            </div>

            <div className={styles.formGroup}>
              <Text as="div" size="2" mb="1" weight="bold" className={styles.title}>
                Rules
              </Text>
              <SmartPlaylistRuleEditor 
                control={control} 
                name="ruleGroups" 
                errors={errors.ruleGroups}
              />
              {errors.ruleGroups && (
                <Text color="red" size="1" className={styles.error}>
                  {errors.ruleGroups.message}
                </Text>
              )}
              {(!ruleGroups || ruleGroups.length === 0) && (
                <Text color="red" size="1" className={styles.error}>
                  At least one rule is required
                </Text>
              )}
            </div>
          </Flex>

          <Flex justify="end" mt="4" className={styles.submitButton}>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? 'Creating...' : 'Create Smart Playlist'}
            </Button>
          </Flex>
        </Flex>
      </form>
    </div>
  );
}
