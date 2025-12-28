import { useCallback } from 'react';
import { useDisclosure } from '@/app/hooks/use-disclosure.ts';
import { usePathParam } from '@/app/hooks/use-path-param.ts';
import { LibraryParams } from '@/app/modules/library-music/routes/_routes.tsx';
import { ArtistBigCircle } from '@/app/modules/library-music/components/artwork/artist-big-circle/artist-big-circle.tsx';
import { ArtistEditor } from '@/app/modules/library-music/components/artist-editor/artist-editor';
import { CoverGrid } from '@/app/modules/library-music/components/cover-grid';
import { ArtistResource } from '@/app/libs/api-client/gen/models';
import { useArtistsIndex, useArtistsUpdate } from '@/app/libs/api-client/gen/endpoints/artist/artist.ts';
import { useMetadataSync } from '@/app/libs/api-client/gen/endpoints/metadata-sync/metadata-sync.ts';
import { Container, ContextMenu, Dialog } from '@radix-ui/themes';
import { useAppDispatch } from '@/app/store/hooks';
import { createNotification } from '@/app/store/notifications/notifications-slice';

import styles from './artists.module.scss';

function ArtistContextMenu({ artist, librarySlug }: { artist: ArtistResource, librarySlug: string }) {
  const [showEditor, editorHandlers] = useDisclosure(false);
  const dispatch = useAppDispatch();

  // Update mutation with default cache invalidation
  const updateMutation = useArtistsUpdate({
    mutation: {
      onSuccess: () => {
        dispatch(createNotification({
          title: 'Success',
          message: 'Artist updated successfully!',
          type: 'success',
          toast: true,
        }));
        editorHandlers.close();
      },
      onError: (error: any) => {
        dispatch(createNotification({
          title: 'Error',
          message: error.response?.data?.message || 'Failed to update artist',
          type: 'error',
          toast: true,
        }));
      },
    },
  });

  // Sync mutation
  const syncMutation = useMetadataSync({
    mutation: {
      onSuccess: () => {
        dispatch(createNotification({
          title: 'Success',
          message: 'Metadata synced successfully!',
          type: 'success',
          toast: true,
        }));
      },
      onError: (error: any) => {
        dispatch(createNotification({
          title: 'Error',
          message: error.response?.data?.message || 'Failed to sync metadata',
          type: 'error',
          toast: true,
        }));
      }
    }
  });

  const handleSync = useCallback(() => {
    syncMutation.mutate({
      data: {
        artist_public_ids: [artist.publicId],
        force_update: true,
      }
    });
  }, [syncMutation, artist]);

  const handleArtistSubmit = useCallback(async (data: any) => {
    // Submit via React Query mutation (after Precognition validation passes)
    updateMutation.mutate({
      library: librarySlug,
      artist: artist.publicId,
      data: data,
    });
  }, [updateMutation, librarySlug, artist.publicId]);

  const handleMetadataApplied = useCallback(() => {
    dispatch(createNotification({
      title: 'Success',
      message: 'Metadata applied successfully!',
      type: 'success',
      toast: true,
    }));
    // TODO: Refresh the artists list
  }, [dispatch]);

  return (
    <>
      <ContextMenu.Content>
        <ContextMenu.Item onClick={() => editorHandlers.open()}>Edit</ContextMenu.Item>
        <ContextMenu.Separator/>
        <ContextMenu.Item color="red">Delete</ContextMenu.Item>
      </ContextMenu.Content>

      {showEditor && (
        <Dialog.Root open={showEditor} onOpenChange={(open) => !open && editorHandlers.close()}>
          <Dialog.Content style={{
            backgroundColor: 'var(--color-background)',
            border: '1px solid var(--gray-6)',
            borderRadius: '8px',
            boxShadow: '0 4px 12px rgba(0, 0, 0, 0.15)',
            padding: '0',
            maxWidth: '650px',
            width: '100%',
            maxHeight: '80vh',
            overflow: 'auto',
          }}>
            <Dialog.Title style={{ padding: '24px 24px 0 24px' }}>Edit Artist</Dialog.Title>
            <Dialog.Description style={{ padding: '0 24px 24px 24px' }}>
              Make changes to the artist information.
            </Dialog.Description>

            <div style={{ padding: '0 24px 24px 24px' }}>
              <ArtistEditor
                artist={artist}
                onSubmit={handleArtistSubmit}
                onCancel={() => editorHandlers.close()}
                onSync={handleSync}
                librarySlug={librarySlug}
                onMetadataApplied={handleMetadataApplied}
              />
            </div>
          </Dialog.Content>
        </Dialog.Root>
      )}
    </>
  );
}

export default function Artists() {
  const { library: libraryParam } = usePathParam<LibraryParams>();
  const {data: artistsData} = useArtistsIndex(libraryParam)

  return (
    <Container className={styles.artistsLayout}>
      <CoverGrid style={{ gap: '32px' }}>
        {artistsData?.data.map((artist) => (
          <ContextMenu.Root key={artist.publicId}>
            <ContextMenu.Trigger>
              <div style={{ display: 'inline-block', cursor: 'context-menu' }}>
                <ArtistBigCircle artist={artist} />
              </div>
            </ContextMenu.Trigger>

            <ArtistContextMenu artist={artist} librarySlug={libraryParam}/>
          </ContextMenu.Root>
        ))}
      </CoverGrid>
    </Container>
  );
}
