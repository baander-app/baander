import { useState, useMemo, Fragment } from 'react';
import { Button, Flex, Box, Badge, Dialog, IconButton, Tabs } from '@radix-ui/themes';
import { useGenresIndex } from '@/app/libs/api-client/gen/endpoints/genre/genre';
import { LockClosedIcon, LockOpen1Icon } from '@radix-ui/react-icons';
import { BrowseTab } from '@/app/modules/dashboard/music/components/browse-tab/browse-tab';
import { FormField, FormFieldConfig, useFormEditor } from '@/app/ui/form';
import { ALBUM_TYPE_OPTIONS, COUNTRY_OPTIONS, LANGUAGE_OPTIONS } from '@/app/constants/metadata';
import { albumFieldConfig, albumFormSections } from './album-editor.config';
import { AlbumEditorProps, AlbumFormData } from './album-editor.types';
import styles from './album-editor.module.scss';
import { GenresIndex200 } from '@/app/libs/api-client/gen/models';

/**
 * Album Editor - Config-driven form with Precognition validation
 *
 * Features:
 * - 11 editable metadata fields with real-time validation
 * - 4 grouped sections for better UX
 * - Field locking to prevent metadata sync overwrites
 * - Metadata browsing from MusicBrainz/Discogs
 * - Config-driven for easy maintenance and reuse
 */
export function AlbumEditor({
  album,
  librarySlug,
  onSubmit,
  onCancel,
  onSync,
  onMetadataApplied
}: AlbumEditorProps) {
  const [showBrowseDialog, setShowBrowseDialog] = useState(false);
  const [activeTab, setActiveTab] = useState<string>('basic-info');

  // Form editor with Precognition validation and field locking
  const { form, lockMode, setLockMode, toggleFieldLock, isFieldLocked, submit } =
    useFormEditor<AlbumFormData>({
      method: 'put',
      url: route('api.albums.update', { library: librarySlug, album: album?.publicId }),
      initialData: {
        title: album?.title || '',
        type: album?.type || '',
        year: album?.year || undefined,
        disambiguation: album?.disambiguation || '',
        mbid: album?.mbid || '',
        discogsId: album?.discogsId || undefined,
        spotifyId: album?.spotifyId || '',
        label: album?.label || '',
        catalogNumber: album?.catalogNumber || '',
        barcode: album?.barcode || '',
        country: album?.country || '',
        language: album?.language || '',
        annotation: album?.annotation || '',
        genres: album?.genres?.map(g => g.name) || [],
      },
      initialLockedFields: album?.lockedFields as (keyof AlbumFormData)[],
      onSubmit: async (data) => {
        await onSubmit(data);
      },
    });

  // Fetch genres for multiselect
  const { data: genresData } = useGenresIndex({
    librarySlug: librarySlug || '',
    limit: 100,
  });

  const genreOptions = useMemo(
    () => (genresData as GenresIndex200)?.data?.map(g => ({ id: g.id, name: g.name })) || [],
    [genresData]
  );

  // Build field config with options at runtime
  const fieldConfigWithOptions = useMemo(() => {
    return albumFieldConfig.map((config): FormFieldConfig<AlbumFormData> => {
      switch (config.name) {
        case 'type':
          return { ...config, options: [...ALBUM_TYPE_OPTIONS] };
        case 'country':
          return { ...config, options: [...COUNTRY_OPTIONS] };
        case 'language':
          return { ...config, options: [...LANGUAGE_OPTIONS] };
        case 'genres':
          return {
            ...config,
            options: genreOptions,
          };
        default:
          return config;
      }
    });
  }, [genreOptions]);

  return (
    <Box>
      <Flex direction="column" gap="4">
        {/* Header with lock mode toggle */}
        <Flex justify="between" align="center">
          <Box />
          <Flex gap="3" align="center">
            {lockMode && (
              <Badge color="amber" variant="soft">
                Lock Mode
              </Badge>
            )}
            <IconButton
              variant={lockMode ? "solid" : "outline"}
              color={lockMode ? "blue" : "gray"}
              onClick={() => setLockMode(!lockMode)}
              title={lockMode ? "Disable lock mode" : "Enable lock mode"}
            >
              {lockMode ? <LockClosedIcon /> : <LockOpen1Icon />}
            </IconButton>
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

        {/* Form */}
        <form onSubmit={(e) => {
          e.preventDefault();
          submit();
        }} className={styles.form}>
          <Flex direction="column" gap="4">
            {/* Tabs */}
            <Tabs.Root value={activeTab} onValueChange={setActiveTab}>
              <Tabs.List>
                {albumFormSections.map(section => (
                  <Tabs.Trigger key={section.title} value={section.title.toLowerCase().replace(/\s+/g, '-')}>
                    {section.title}
                  </Tabs.Trigger>
                ))}
              </Tabs.List>

              {/* Tab Content */}
              <Box mt="4">
                {albumFormSections.map(section => {
                  const tabValue = section.title.toLowerCase().replace(/\s+/g, '-');
                  return (
                    <Tabs.Content
                      key={section.title}
                      value={tabValue}
                    >
                      {activeTab === tabValue && (
                        <Flex direction="column" gap="3">
                          {section.fields.map((fieldName) => {
                            const config = fieldConfigWithOptions.find((c) => c.name === fieldName);
                            if (!config) return null;

                            const fieldValue = form.data[fieldName];
                            const handleChange = (value: unknown) => {
                              form.setData(fieldName, value);
                            };

                            return (
                              <Fragment key={String(fieldName)}>
                                <FormField
                                  config={config}
                                  value={fieldValue}
                                  onChange={handleChange}
                                  errors={form.errors}
                                  lockMode={lockMode}
                                  isFieldLocked={isFieldLocked}
                                  onToggleLock={toggleFieldLock}
                                />
                              </Fragment>
                            );
                          })}
                        </Flex>
                      )}
                    </Tabs.Content>
                  );
                })}
              </Box>
            </Tabs.Root>

            {/* Form Actions */}
            <Flex gap="3" mt="4" justify="end">
              {onCancel && (
                <Button type="button" variant="soft" onClick={onCancel}>
                  Cancel
                </Button>
              )}
              <Button type="submit" disabled={form.processing}>
                {form.processing ? 'Saving...' : 'Save'}
              </Button>
            </Flex>
          </Flex>
        </form>

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
              Browse Metadata for "{album?.title}"
            </Dialog.Title>
            <Dialog.Description style={{ padding: '0 24px 24px 24px' }}>
              Search and apply metadata from MusicBrainz and Discogs
            </Dialog.Description>

            {album && librarySlug && (
              <BrowseTab
                entityType="album"
                entityId={album.publicId}
                entityName={album.title}
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
