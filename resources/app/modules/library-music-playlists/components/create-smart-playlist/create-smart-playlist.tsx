import { Flex, Switch, Text, TextField } from '@radix-ui/themes';
import { useForm, Controller } from 'react-hook-form';
import { useState } from 'react';
import { SmartPlaylistRuleEditor } from '../smart-playlist-rule-editor/smart-playlist-rule-editor';
import styles from './create-smart-playlist.module.scss';
import { usePlaylistSmartCreate } from '@/libs/api-client/gen/endpoints/playlist/playlist.ts';

interface SmartPlaylistForm {
  name: string;
  description: string;
  is_public: boolean;
  rules: Array<{
    operator?: 'and' | 'or';
    rules: Array<{
      field: string;
      operator: string;
      value: string;
      maxValue?: string;
    }>;
  }>;
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
      rules: [{
        operator: 'and',
        rules: [{ field: 'genre', operator: 'is', value: '', maxValue: '' }]
      }],
    },
  });

  // Watch the rules field to validate it has at least one rule
  const rules = watch('rules.0.rules');

  const [error, setError] = useState<string | null>(null);

  const mutation = usePlaylistSmartCreate({
    mutation: {
      onSuccess: () => {
        console.log('Smart playlist created successfully');
        window.location.href = '/library/playlists';
      },
      onError: (err: any) => {
        console.error('Error creating smart playlist:', err);
        if (err.status === 422 && err.body?.errors) {
          // Handle validation errors
          const validationErrors = err.body.errors;
          const errorMessages = Object.values(validationErrors).flat();
          setError(errorMessages.join(', '));
        } else {
          setError(err.body?.message || 'Failed to create smart playlist');
        }
      }
    }
  });

  const onSubmit = async (data: SmartPlaylistForm) => {
    console.log('Form submitted with data:', data);
    try {
      setError(null);

      // Validate that at least one rule is defined
      if (data.rules.length === 0 || data.rules[0].rules.length === 0) {
        setError('At least one rule is required');
        console.log('Validation failed: At least one rule is required');
        return;
      }

      console.log('About to make API call with data:', data);

      // Remove maxValue from rules before sending to API
      const apiData = {
        ...data,
        rules: data.rules.map(group => ({
          operator: group.operator,
          rules: group.rules.map(rule => ({
            field: rule.field,
            operator: rule.operator,
            value: rule.value
          }))
        }))
      };

      console.log('Sending API data:', apiData);

      // Use the mutation instead of the old API call
      mutation.mutate({ data: apiData });

    } catch (err: any) {
      console.error('Error in form submission:', err);
      setError('An unexpected error occurred');
    }
  };

  return (
    <div className={styles.container}>
      <form
        onSubmit={(e) => {
          console.log('Form onSubmit event triggered');
          try {
            handleSubmit(onSubmit)(e);
          } catch (error) {
            console.error('Error in form submission:', error);
            // Prevent default form submission if there's an error
            e.preventDefault();
          }
        }}
      >
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
                name="rules"
                errors={errors.rules}
              />
              {errors.rules && (
                <Text color="red" size="1" className={styles.error}>
                  {errors.rules.message}
                </Text>
              )}
              {(!rules || rules.length === 0) && (
                <Text color="red" size="1" className={styles.error}>
                  At least one rule is required
                </Text>
              )}
            </div>
          </Flex>

          <Flex justify="end" mt="4" className={styles.submitButton}>
            <button
              type="submit"
              className={styles.submitButtonHtml}
              disabled={isSubmitting || mutation.isPending}
            >
              {isSubmitting || mutation.isPending ? 'Creating...' : 'Create Smart Playlist'}
            </button>
          </Flex>
        </Flex>
      </form>
    </div>
  );
}