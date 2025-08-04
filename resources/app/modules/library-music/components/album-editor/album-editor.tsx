import { useState, useEffect } from 'react';
import { Button, TextField, Flex, Box, Select, Badge } from '@radix-ui/themes';
import { useForm, Controller } from 'react-hook-form';
import styles from './album-editor.module.scss';
import { Form } from 'radix-ui';
import { useGenresIndex } from '@/libs/api-client/gen/endpoints/genre/genre.ts';
import { AlbumResource } from '@/libs/api-client/gen/models';

interface AlbumEditorProps {
  album?: AlbumResource;
  librarySlug: string;
  onSubmit: (data: AlbumFormData) => void;
  onCancel?: () => void;
}

interface AlbumFormData {
  title: string;
  year?: number;
  mbid?: string;
  discogsId?: number;
  genres: string[];
}

// Simple multi-select for genres
type MultiSelectProps = {
  placeholder: string;
  value: string[];
  onChange: (value: string[]) => void;
  options: { id: number; name: string }[];
};

function MultiSelect({ placeholder, value, onChange, options }: MultiSelectProps) {
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
                >
                  ×
                </Button>
              </Badge>
            ))}
          </Flex>
        )}

        <Select.Root onValueChange={handleSelectChange} value="">
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

export function AlbumEditor({ album, librarySlug, onSubmit, onCancel }: AlbumEditorProps) {
  const { register, handleSubmit, control, formState: { errors } } = useForm<AlbumFormData>({
    defaultValues: {
      title: album?.title || '',
      year: album?.year || undefined,
      mbid: album?.mbid || '',
      discogsId: album?.discogsId || undefined,
      genres: album?.genres?.map(g => g.name) || [],
    }
  });

  // Fetch genres
  const { data: genresData } = useGenresIndex({
    librarySlug: librarySlug || '',
    limit: 100,
  });

  // Format the data for the multiselect component
  const genreOptions = genresData?.data?.map(genre => ({
    id: genre.id,
    name: genre.name,
  })) || [];

  const handleFormSubmit = (data: AlbumFormData) => {
    onSubmit(data);
  };

  return (
    <Form.Root onSubmit={handleSubmit(handleFormSubmit)} className={styles.form}>
      <Flex direction="column" gap="4">
        <Form.Field name="title">
          <Form.Label>Title</Form.Label>
          <Form.Control asChild>
            <TextField.Root
              {...register('title', { required: 'Title is required' })}
              placeholder="Album title"
            />
          </Form.Control>
          {errors.title && (
            <Form.Message>{errors.title.message}</Form.Message>
          )}
        </Form.Field>

        <Form.Field name="year">
          <Form.Label>Year</Form.Label>
          <Form.Control asChild>
            <TextField.Root
              type="number"
              {...register('year', {
                min: { value: 0, message: 'Year must be positive' },
                max: { value: 9999, message: 'Year must be less than 10000' }
              })}
              placeholder="Release year"
            />
          </Form.Control>
          {errors.year && (
            <Form.Message>{errors.year.message}</Form.Message>
          )}
        </Form.Field>

        <Form.Field name="mbid">
          <Form.Label>MusicBrainz ID</Form.Label>
          <Form.Control asChild>
            <TextField.Root
              {...register('mbid')}
              placeholder="MusicBrainz ID"
            />
          </Form.Control>
          {errors.mbid && (
            <Form.Message>{errors.mbid.message}</Form.Message>
          )}
        </Form.Field>

        <Form.Field name="discogs_id">
          <Form.Label>Discogs ID</Form.Label>
          <Form.Control asChild>
            <TextField.Root
              type="number"
              {...register('discogs_id', {
                min: { value: 0, message: 'Discogs ID must be positive' },
                max: { value: 999999999999, message: 'Discogs ID is too large' }
              })}
              placeholder="Discogs ID"
            />
          </Form.Control>
          {errors.discogs_id && (
            <Form.Message>{errors.discogs_id.message}</Form.Message>
          )}
        </Form.Field>

        <Form.Field name="genres">
          <Form.Label>Genres</Form.Label>
          <Controller
            name="genres"
            control={control}
            render={({ field }) => (
              <MultiSelect
                placeholder="Select genres"
                value={field.value}
                onChange={field.onChange}
                options={genreOptions}
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
  );
}