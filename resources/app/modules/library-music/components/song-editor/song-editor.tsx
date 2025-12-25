import { useState, useEffect } from 'react';
import { Button, TextField, Flex, Box, Select, Badge, IconButton, Dialog } from '@radix-ui/themes';
import { useForm, Controller } from 'react-hook-form';
import { LockClosedIcon, LockOpen1Icon } from '@radix-ui/react-icons';
import styles from './song-editor.module.scss';
import { Form } from 'radix-ui';
import { useGenresIndex } from '@/app/libs/api-client/gen/endpoints/genre/genre.ts';
import { GenresIndex200, SongResource } from '@/app/libs/api-client/gen/models';
import { BrowseTab } from '@/app/modules/dashboard/music/components/browse-tab/browse-tab';

interface GenreResource {
  id: number;
  name: string;
  slug: string;
}

interface SongEditorProps {
  song?: SongResource & { genres?: GenreResource[] };
  librarySlug: string;
  onSubmit: (data: SongFormData) => void;
  onCancel?: () => void;
  onSync?: () => void;
  onMetadataApplied?: () => void;
}

interface SongFormData {
  title: string;
  track_number?: number;
  disc_number?: number;
  year?: number;
  mbid?: string;
  discogs_id?: number;
  genres: string[];
  lockedFields?: string[];
}

// Simple multi-select for genres
type MultiSelectProps = {
  placeholder: string;
  value: string[];
  onChange: (value: string[]) => void;
  options: { id: number; name: string }[];
  disabled?: boolean;
};

function MultiSelect({ placeholder, value, onChange, options, disabled }: MultiSelectProps) {
  const [selectedItems, setSelectedItems] = useState<{ id: number; name: string }[]>([]);

  // Initialize selected items from value prop
  useEffect(() => {
    const selected = options.filter(item => value.includes(item.name));
    setSelectedItems(selected);
  }, [value, options]);

  const handleSelectChange = (selectedValue: string) => {
    const selectedOption = options.find(option => option.name === selectedValue);
    if (selectedOption && !selectedItems.some(item => item.id === selectedOption.id)) {
      const newSelectedItems = [...selectedItems, selectedOption];
      setSelectedItems(newSelectedItems);
      onChange(newSelectedItems.map(item => item.name));
    }
  };

  const handleRemoveItem = (id: number) => {
    const newSelectedItems = selectedItems.filter(item => item.id !== id);
    setSelectedItems(newSelectedItems);
    onChange(newSelectedItems.map(item => item.name));
  };

  const availableOptions = options.filter(option =>
    !selectedItems.some(selected => selected.id === option.id)
  );

  return (
    <Box width="100%">
      <Flex direction="column" gap="2">
        {selectedItems.length > 0 && (
          <Flex wrap="wrap" gap="1">
            {selectedItems.map(item => (
              <Badge key={item.id} variant="soft" className={styles.selectedBadge}>
                {item.name}
                <Button
                  size="1"
                  variant="ghost"
                  onClick={() => handleRemoveItem(item.id)}
                  className={styles.removeButton}
                  disabled={disabled}
                >
                  ×
                </Button>
              </Badge>
            ))}
          </Flex>
        )}

        <Select.Root onValueChange={handleSelectChange} value="" disabled={disabled}>
          <Select.Trigger placeholder={placeholder} />
          <Select.Content>
            {availableOptions.map(option => (
              <Select.Item key={option.id} value={option.name} style={{ cursor: 'pointer' }}>
                {option.name}
              </Select.Item>
            ))}
          </Select.Content>
        </Select.Root>
      </Flex>
    </Box>
  );
}

export function SongEditor({ song, librarySlug, onSubmit, onCancel, onSync, onMetadataApplied }: SongEditorProps) {
  const [lockMode, setLockMode] = useState(false);
  const [lockedFields, setLockedFields] = useState<Set<keyof SongFormData>>(new Set());
  const [showBrowseDialog, setShowBrowseDialog] = useState(false);

  const { register, handleSubmit, control, formState: { errors } } = useForm<SongFormData>();

  // Fetch genres
  const { data: genresData } = useGenresIndex({
    librarySlug: librarySlug || '',
    limit: 100,
  })

  // Format the data for the multiselect component
  const genreOptions = (genresData as GenresIndex200)?.data?.map(genre => ({
    id: genre.id,
    name: genre.name,
  })) || [];

  const handleFormSubmit = (data: SongFormData) => {
    onSubmit(data);
  };

  const toggleFieldLock = (fieldName: keyof SongFormData) => {
    const newLockedFields = new Set(lockedFields);
    if (newLockedFields.has(fieldName)) {
      newLockedFields.delete(fieldName);
    } else {
      newLockedFields.add(fieldName);
    }
    setLockedFields(newLockedFields);
  };

  const isFieldLocked = (fieldName: keyof SongFormData): boolean => {
    return lockMode && lockedFields.has(fieldName);
  };

  return (
    <Box className={styles.container}>
      <Flex justify="end" mb="4">
        <IconButton
          variant={lockMode ? "solid" : "outline"}
          color={lockMode ? "blue" : "gray"}
          onClick={() => setLockMode(!lockMode)}
          title={lockMode ? "Disable lock mode" : "Enable lock mode"}
        >
          {lockMode ? <LockClosedIcon /> : <LockOpen1Icon />}
        </IconButton>
      </Flex>

      {/* Browse and Sync buttons */}
      <Flex gap="3" mb="4">
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
          <Form.Field name="title">
            <Flex align="center" gap="2">
              <Form.Label>Title</Form.Label>
              {lockMode && (
                <Button
                  size="1"
                  variant="ghost"
                  onClick={() => toggleFieldLock('title')}
                  className={styles.lockButton}
                >
                  {isFieldLocked('title') ? <LockClosedIcon /> : <LockOpen1Icon />}
                </Button>
              )}
            </Flex>
            <Form.Control asChild>
              <TextField.Root
                {...register('title', { required: 'Title is required' })}
                placeholder="Song title"
                disabled={isFieldLocked('title')}
                className={isFieldLocked('title') ? styles.disabledField : ''}
              />
            </Form.Control>
            {errors.title && (
              <Form.Message>{errors.title.message}</Form.Message>
            )}
          </Form.Field>

          <Form.Field name="track_number">
            <Flex align="center" gap="2">
              <Form.Label>Track Number</Form.Label>
              {lockMode && (
                <Button
                  size="1"
                  variant="ghost"
                  onClick={() => toggleFieldLock('track_number')}
                  className={styles.lockButton}
                >
                  {isFieldLocked('track_number') ? <LockClosedIcon /> : <LockOpen1Icon />}
                </Button>
              )}
            </Flex>
            <Form.Control asChild>
              <TextField.Root
                type="number"
                {...register('track_number', {
                  min: { value: 0, message: 'Track number must be positive' },
                  max: { value: 9999, message: 'Track number is too large' }
                })}
                placeholder="Track number"
                disabled={isFieldLocked('track_number')}
                className={isFieldLocked('track_number') ? styles.disabledField : ''}
              />
            </Form.Control>
            {errors.track_number && (
              <Form.Message>{errors.track_number.message}</Form.Message>
            )}
          </Form.Field>

          <Form.Field name="disc_number">
            <Flex align="center" gap="2">
              <Form.Label>Disc Number</Form.Label>
              {lockMode && (
                <Button
                  size="1"
                  variant="ghost"
                  onClick={() => toggleFieldLock('disc_number')}
                  className={styles.lockButton}
                >
                  {isFieldLocked('disc_number') ? <LockClosedIcon /> : <LockOpen1Icon />}
                </Button>
              )}
            </Flex>
            <Form.Control asChild>
              <TextField.Root
                type="number"
                {...register('disc_number', {
                  min: { value: 0, message: 'Disc number must be positive' },
                  max: { value: 9999, message: 'Disc number is too large' }
                })}
                placeholder="Disc number"
                disabled={isFieldLocked('disc_number')}
                className={isFieldLocked('disc_number') ? styles.disabledField : ''}
              />
            </Form.Control>
            {errors.disc_number && (
              <Form.Message>{errors.disc_number.message}</Form.Message>
            )}
          </Form.Field>

          <Form.Field name="year">
            <Flex align="center" gap="2">
              <Form.Label>Year</Form.Label>
              {lockMode && (
                <Button
                  size="1"
                  variant="ghost"
                  onClick={() => toggleFieldLock('year')}
                  className={styles.lockButton}
                >
                  {isFieldLocked('year') ? <LockClosedIcon /> : <LockOpen1Icon />}
                </Button>
              )}
            </Flex>
            <Form.Control asChild>
              <TextField.Root
                type="number"
                {...register('year', {
                  min: { value: 0, message: 'Year must be positive' },
                  max: { value: 9999, message: 'Year must be less than 10000' }
                })}
                placeholder="Release year"
                disabled={isFieldLocked('year')}
                className={isFieldLocked('year') ? styles.disabledField : ''}
              />
            </Form.Control>
            {errors.year && (
              <Form.Message>{errors.year.message}</Form.Message>
            )}
          </Form.Field>

          <Form.Field name="mbid">
            <Flex align="center" gap="2">
              <Form.Label>MusicBrainz ID</Form.Label>
              {lockMode && (
                <Button
                  size="1"
                  variant="ghost"
                  onClick={() => toggleFieldLock('mbid')}
                  className={styles.lockButton}
                >
                  {isFieldLocked('mbid') ? <LockClosedIcon /> : <LockOpen1Icon />}
                </Button>
              )}
            </Flex>
            <Form.Control asChild>
              <TextField.Root
                {...register('mbid')}
                placeholder="MusicBrainz ID"
                disabled={isFieldLocked('mbid')}
                className={isFieldLocked('mbid') ? styles.disabledField : ''}
              />
            </Form.Control>
            {errors.mbid && (
              <Form.Message>{errors.mbid.message}</Form.Message>
            )}
          </Form.Field>

          <Form.Field name="discogs_id">
            <Flex align="center" gap="2">
              <Form.Label>Discogs ID</Form.Label>
              {lockMode && (
                <Button
                  size="1"
                  variant="ghost"
                  onClick={() => toggleFieldLock('discogs_id')}
                  className={styles.lockButton}
                >
                  {isFieldLocked('discogs_id') ? <LockClosedIcon /> : <LockOpen1Icon />}
                </Button>
              )}
            </Flex>
            <Form.Control asChild>
              <TextField.Root
                type="number"
                {...register('discogs_id', {
                  min: { value: 0, message: 'Discogs ID must be positive' },
                  max: { value: 999999999999, message: 'Discogs ID is too large' }
                })}
                placeholder="Discogs ID"
                disabled={isFieldLocked('discogs_id')}
                className={isFieldLocked('discogs_id') ? styles.disabledField : ''}
              />
            </Form.Control>
            {errors.discogs_id && (
              <Form.Message>{errors.discogs_id.message}</Form.Message>
            )}
          </Form.Field>

          <Form.Field name="genres">
            <Flex align="center" gap="2">
              <Form.Label>Genres</Form.Label>
              {lockMode && (
                <Button
                  size="1"
                  variant="ghost"
                  onClick={() => toggleFieldLock('genres')}
                  className={styles.lockButton}
                >
                  {isFieldLocked('genres') ? <LockClosedIcon /> : <LockOpen1Icon />}
                </Button>
              )}
            </Flex>
            <Controller
              name="genres"
              control={control}
              render={({ field }) => (
                <MultiSelect
                  placeholder="Select genres"
                  value={field.value}
                  onChange={field.onChange}
                  options={genreOptions}
                  disabled={isFieldLocked('genres')}
                />
              )}
            />
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
            Browse Metadata for "{song?.title}"
          </Dialog.Title>
          <Dialog.Description style={{ padding: '0 24px 24px 24px' }}>
            Search and apply metadata from MusicBrainz and Discogs
          </Dialog.Description>

          {song && librarySlug && (
            <BrowseTab
              entityType="song"
              entityId={song.publicId}
              entityName={song.title}
              onMetadataApplied={() => {
                setShowBrowseDialog(false);
                onMetadataApplied?.();
              }}
            />
          )}
        </Dialog.Content>
      </Dialog.Root>
    </Box>
  );
}
