import { Fragment, useMemo, useState } from 'react';
import { Badge, Box, Button, Dialog, Flex, IconButton, Tabs } from '@radix-ui/themes';
import { useGenresIndex } from '@/app/libs/api-client/gen/endpoints/genre/genre';
import { LockClosedIcon, LockOpen1Icon } from '@radix-ui/react-icons';
import { BrowseTab } from '@/app/modules/dashboard/music/components/browse-tab/browse-tab';
import { FormField, FormFieldConfig, useFormEditor } from '@/app/ui/form';
import { songFieldConfig, songFormSections } from './song-editor.config';
import { SongEditorProps, SongFormData } from './song-editor.types';
import styles from './song-editor.module.scss';
import { useSongsShow } from '@/app/libs/api-client/gen/endpoints/song/song.ts';

/**
 * Song Editor - Config-driven form with Precognition validation
 *
 * Features:
 * - 13 editable metadata fields with real-time validation
 * - 2 grouped sections for better UX
 * - Field locking to prevent metadata sync overwrites
 * - Metadata browsing from MusicBrainz/Discogs
 * - Config-driven for easy maintenance and reuse
 */
export function SongEditor({
                             song,
                             librarySlug,
                             onSubmit,
                             onCancel,
                             onSync,
                             onMetadataApplied,
                           }: SongEditorProps) {
  const [showBrowseDialog, setShowBrowseDialog] = useState(false);
  const [activeTab, setActiveTab] = useState<string>('details');

  const canQuery = Boolean(song?.librarySlug && song?.publicId);
  const {data} = useSongsShow(song?.librarySlug!, song?.publicId!, {
    relations: 'album.cover,genres',
  }, {
    query: {
      enabled: canQuery,
    },
  });

  const {form, lockMode, setLockMode, toggleFieldLock, isFieldLocked, submit} =
    useFormEditor<SongFormData>({
      method: 'put',
      url: route('api.songs.update', {library: librarySlug, song: song?.publicId}),
      initialData: {
        title: data?.title || '',
        track: data?.track || undefined,
        disc: data?.disc || undefined,
        year: data?.year || undefined,
        explicit: data?.explicit || false,
        lyrics: data?.lyrics || '',
        comment: song?.comment || '',
        path: song?.path || '',
        mbid: song?.mbid || '',
        discogsId: data?.discogsId || undefined,
        spotifyId: data?.spotifyId || '',
        genres: song?.genres?.map(g => g.name) || [],
      },
      initialLockedFields: data?.lockedFields as (keyof SongFormData)[],
      onSubmit: async (submitted) => {
        await onSubmit(submitted);
      },
    });

  // Fetch genres for multiselect
  const {data: genresData, isSuccess} = useGenresIndex({
    librarySlug: librarySlug || '',
    limit: 100,
  });

  const genreOptions = useMemo(
    () => isSuccess && genresData && genresData.data.map(g => ({id: g.id, name: g.name})) || [],
    [isSuccess, genresData],
  );

  // Build field config with options at runtime
  const fieldConfigWithOptions = useMemo(() => {
    return songFieldConfig.map((config): FormFieldConfig<SongFormData> => {
      if (config.name === 'genres') {
        return {
          ...config,
          options: genreOptions,
        };
      }
      return config;
    });
  }, [genreOptions]);

  return (
    <Box>
      <Flex direction="column" gap="4">
        {/* Header with lock mode toggle */}
        <Flex justify="between" align="center">
          <Box/>
          <Flex gap="3" align="center">
            {lockMode && (
              <Badge color="amber" variant="soft">
                Lock Mode
              </Badge>
            )}
            <IconButton
              variant={lockMode ? 'solid' : 'outline'}
              color={lockMode ? 'blue' : 'gray'}
              onClick={() => setLockMode(!lockMode)}
              title={lockMode ? 'Disable lock mode' : 'Enable lock mode'}
            >
              {lockMode ? <LockClosedIcon/> : <LockOpen1Icon/>}
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
                {songFormSections.map(section => (
                  <Tabs.Trigger key={section.title} value={section.title.toLowerCase().replace(/\s+/g, '-')}>
                    {section.title}
                  </Tabs.Trigger>
                ))}
              </Tabs.List>

              {/* Tab Content */}
              <Box mt="4">
                {songFormSections.map(section => {
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
            <Dialog.Title style={{padding: '24px 24px 0 24px'}}>
              Browse Metadata for "{data?.title}"
            </Dialog.Title>
            <Dialog.Description style={{padding: '0 24px 24px 24px'}}>
              Search and apply metadata from MusicBrainz and Discogs
            </Dialog.Description>

            {data && librarySlug && (
              <BrowseTab
                entityType="song"
                entityId={data.publicId}
                entityName={data.title}
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
