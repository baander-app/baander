import { Button, Flex, Switch, Text, TextField } from '@radix-ui/themes';
import { useForm, Controller } from 'react-hook-form';
import { useState } from 'react';
import styles from './create-playlist.module.scss';

interface PlaylistForm {
  name: string;
  description: string;
  is_public: boolean;
}

export function CreatePlaylist() {
  const {
    register,
    handleSubmit,
    control,
    formState: { errors, isSubmitting },
  } = useForm<PlaylistForm>({
    defaultValues: {
      name: '',
      description: '',
      is_public: false,
    },
  });

  const [error, setError] = useState<string | null>(null);

  const onSubmit = async (data: PlaylistForm) => {
    try {
      setError(null);

      const response = await fetch('/api/playlists', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(data),
      });

      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.message || 'Failed to create playlist');
      }

      // Handle success - redirect or show success message
      window.location.href = '/library/playlists';
    } catch (err) {
      setError(err instanceof Error ? err.message : 'An unknown error occurred');
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
                  {...register('name', { required: 'Name is required' })}
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
          </Flex>

          <Flex justify="end" mt="4" className={styles.submitButton}>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? 'Creating...' : 'Create Playlist'}
            </Button>
          </Flex>
        </Flex>
      </form>
    </div>
  );
}
