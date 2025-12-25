import { useState, useEffect } from 'react';
import { Button, TextField, Flex, Box, Badge, TextArea, Dialog } from '@radix-ui/themes';
import { useForm } from 'react-hook-form';
import styles from './artist-editor.module.scss';
import { Form } from 'radix-ui';
import { ArtistResource } from '@/app/libs/api-client/gen/models';
import { LockClosedIcon } from '@radix-ui/react-icons';
import { BrowseTab } from '@/app/modules/dashboard/music/components/browse-tab/browse-tab';

interface ArtistEditorProps {
  artist?: ArtistResource;
  onSubmit: (data: ArtistFormData) => void;
  onCancel?: () => void;
  onSync?: () => void;
  librarySlug?: string;
  onMetadataApplied?: () => void;
}

interface ArtistFormData {
  name: string;
  mbid?: string;
  discogsId?: number;
  spotifyId?: string;
  biography?: string;
  disambiguation?: string;
  lockedFields?: string[];
}

export function ArtistEditor({ artist, onSubmit, onCancel, onSync, librarySlug, onMetadataApplied }: ArtistEditorProps) {
  const [lockMode, setLockMode] = useState(false);
  const [lockedFields, setLockedFields] = useState<string[]>([]);
  const [showBrowseDialog, setShowBrowseDialog] = useState(false);

  const { register, handleSubmit, formState: { errors } } = useForm<ArtistFormData>();

  // Initialize locked fields from artist
  useEffect(() => {
    if (artist?.lockedFields) {
      setLockedFields(artist.lockedFields as string[]);
    }
  }, [artist]);

  const handleFieldClick = (fieldName: string) => {
    if (!lockMode) return;

    setLockedFields(prev => {
      if (prev.includes(fieldName)) {
        return prev.filter(f => f !== fieldName);
      } else {
        return [...prev, fieldName];
      }
    });
  };

  const isFieldLocked = (fieldName: string) => {
    return lockedFields.includes(fieldName);
  };

  const handleFormSubmit = (data: ArtistFormData) => {
    onSubmit({ ...data, lockedFields });
  };

  return (
    <Box>
      <Flex direction="column" gap="4">
        <Flex justify="between" align="center">
          <Box />
          <Flex gap="3" align="center">
            {lockMode && (
              <Badge color="amber" variant="soft">
                Lock Mode
              </Badge>
            )}
            <Button
              variant={lockMode ? "solid" : "soft"}
              size="1"
              onClick={() => setLockMode(!lockMode)}
            >
              <LockClosedIcon />
              {lockMode ? 'Lock Mode ON' : 'Lock Mode OFF'}
            </Button>
          </Flex>
        </Flex>

        {/* Browse and Sync buttons */}
        <Flex gap="3">
          <Button
            type="button"
            variant="outline"
            onClick={() => setShowBrowseDialog(true)}
          >
            Browse Metadata
          </Button>
          {onSync && (
            <Button
              type="button"
              variant="outline"
              onClick={onSync}
            >
              Sync Metadata
            </Button>
          )}
        </Flex>

        <Form.Root onSubmit={handleSubmit(handleFormSubmit)} className={styles.form}>
          <Flex direction="column" gap="4">
            <Form.Field name="name">
              <Form.Label>Name</Form.Label>
              <Form.Control asChild>
                <TextField.Root
                  {...register('name', { required: 'Name is required' })}
                  placeholder="Artist name"
                  disabled={lockMode && isFieldLocked('name')}
                  className={lockMode && isFieldLocked('name') ? styles.lockedField : ''}
                  onClick={() => handleFieldClick('name')}
                />
              </Form.Control>
              {errors.name && (
                <Form.Message>{errors.name.message}</Form.Message>
              )}
            </Form.Field>

            <Form.Field name="mbid">
              <Form.Label>MusicBrainz ID</Form.Label>
              <Form.Control asChild>
                <TextField.Root
                  {...register('mbid')}
                  placeholder="MusicBrainz ID"
                  disabled={lockMode && isFieldLocked('mbid')}
                  className={lockMode && isFieldLocked('mbid') ? styles.lockedField : ''}
                  onClick={() => handleFieldClick('mbid')}
                />
              </Form.Control>
              {errors.mbid && (
                <Form.Message>{errors.mbid.message}</Form.Message>
              )}
            </Form.Field>

            <Form.Field name="discogsId">
              <Form.Label>Discogs ID</Form.Label>
              <Form.Control asChild>
                <TextField.Root
                  type="number"
                  {...register('discogsId', {
                    min: { value: 0, message: 'Discogs ID must be positive' },
                    max: { value: 999999999999, message: 'Discogs ID is too large' }
                  })}
                  placeholder="Discogs ID"
                  disabled={lockMode && isFieldLocked('discogsId')}
                  className={lockMode && isFieldLocked('discogsId') ? styles.lockedField : ''}
                  onClick={() => handleFieldClick('discogsId')}
                />
              </Form.Control>
              {errors.discogsId && (
                <Form.Message>{errors.discogsId.message}</Form.Message>
              )}
            </Form.Field>

            <Form.Field name="spotifyId">
              <Form.Label>Spotify ID</Form.Label>
              <Form.Control asChild>
                <TextField.Root
                  {...register('spotifyId')}
                  placeholder="Spotify ID"
                  disabled={lockMode && isFieldLocked('spotifyId')}
                  className={lockMode && isFieldLocked('spotifyId') ? styles.lockedField : ''}
                  onClick={() => handleFieldClick('spotifyId')}
                />
              </Form.Control>
              {errors.spotifyId && (
                <Form.Message>{errors.spotifyId.message}</Form.Message>
              )}
            </Form.Field>

            <Form.Field name="disambiguation">
              <Form.Label>Disambiguation</Form.Label>
              <Form.Control asChild>
                <TextField.Root
                  {...register('disambiguation')}
                  placeholder="Disambiguation comment to distinguish similar artists"
                  disabled={lockMode && isFieldLocked('disambiguation')}
                  className={lockMode && isFieldLocked('disambiguation') ? styles.lockedField : ''}
                  onClick={() => handleFieldClick('disambiguation')}
                />
              </Form.Control>
              {errors.disambiguation && (
                <Form.Message>{errors.disambiguation.message}</Form.Message>
              )}
            </Form.Field>

            <Form.Field name="biography">
              <Form.Label>Biography</Form.Label>
              <Form.Control asChild>
                <TextArea
                  {...register('biography')}
                  placeholder="Artist biography or annotation"
                  disabled={lockMode && isFieldLocked('biography')}
                  className={lockMode && isFieldLocked('biography') ? styles.lockedField : ''}
                  onClick={() => handleFieldClick('biography')}
                  rows={6}
                />
              </Form.Control>
              {errors.biography && (
                <Form.Message>{errors.biography.message}</Form.Message>
              )}
            </Form.Field>

            <Flex gap="3" mt="4" justify="end">
              {onCancel && (
                <Button type="button" variant="soft" onClick={onCancel}>
                  Cancel
                </Button>
              )}
              <Button type="submit">
                Save
              </Button>
            </Flex>
          </Flex>
        </Form.Root>

        {/* Browse Metadata Dialog */}
        <Dialog.Root open={showBrowseDialog} onOpenChange={setShowBrowseDialog}>
          <Dialog.Content style={{
            backgroundColor: 'var(--color-background)',
            border: '1px solid var(--gray-6)',
            borderRadius: '8px',
            boxShadow: '0 4px 12px rgba(0, 0, 0, 0.15)',
            padding: '0',
            maxWidth: '900px',
            width: '100%',
            maxHeight: '80vh',
            overflow: 'auto',
          }}>
            <Dialog.Title style={{ padding: '24px 24px 0 24px' }}>
              Browse Metadata for "{artist?.name}"
            </Dialog.Title>
            <Dialog.Description style={{ padding: '0 24px 24px 24px' }}>
              Search and apply metadata from MusicBrainz and Discogs
            </Dialog.Description>

            {artist && librarySlug && (
              <BrowseTab
                entityType="artist"
                entityId={artist.publicId}
                entityName={artist.name}
                onMetadataApplied={() => {
                  setShowBrowseDialog(false);
                  onMetadataApplied?.();
                }}
              />
            )}
          </Dialog.Content>
        </Dialog.Root>
      </Flex>
    </Box>
  );
}
