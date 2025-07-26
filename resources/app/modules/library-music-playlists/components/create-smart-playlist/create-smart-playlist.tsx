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
  const [isSubmittingManual, setIsSubmittingManual] = useState<boolean>(false);

  const onSubmit = async (data: SmartPlaylistForm) => {
    console.log('Form submitted with data:', data);
    try {
      setError(null);
      setIsSubmittingManual(true); // Set manual submitting state to true

      // Validate that at least one rule is defined
      if (data.rules.length === 0 || data.rules[0].rules.length === 0) {
        setError('At least one rule is required');
        console.log('Validation failed: At least one rule is required');
        setIsSubmittingManual(false); // Reset manual submitting state
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

      // Make the API call with the transformed data
      await PlaylistService.postApiPlaylistsSmart({
        requestBody: apiData,
      });

      console.log('API call successful');

      // Handle success - redirect or show success message
      window.location.href = '/library/playlists';
    } catch (err: any) {
      console.error('Error in API call:', err);
      if (err.status === 422 && err.body?.errors) {
        // Handle validation errors
        const validationErrors = err.body.errors;
        const errorMessages = Object.values(validationErrors).flat();
        setError(errorMessages.join(', '));
      } else {
        setError(err.body?.message || 'Failed to create smart playlist');
      }
      // Reset manual submitting state
      setIsSubmittingManual(false);
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
            {/* Using a regular HTML button instead of Radix UI Button */}
            <button 
              type="submit"
              className={styles.submitButtonHtml}
              onClick={(e) => {
                console.log('Submit button clicked');

                // As a fallback, manually trigger form submission if the regular process fails
                if (!isSubmitting && !isSubmittingManual) {
                  e.preventDefault(); // Prevent default form submission
                  console.log('Manually triggering form submission');

                  // Get the current form values
                  const formValues = {
                    name: watch('name'),
                    description: watch('description'),
                    is_public: watch('is_public'),
                    rules: watch('rules')
                  };

                  console.log('Manual submission with values:', formValues);

                  // Call onSubmit directly
                  onSubmit(formValues);
                }
              }}
              disabled={isSubmitting || isSubmittingManual}
            >
              {isSubmitting || isSubmittingManual ? 'Creating...' : 'Create Smart Playlist'}
            </button>
          </Flex>
        </Flex>
      </form>
    </div>
  );
}
