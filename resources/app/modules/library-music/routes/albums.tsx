import { useState, useCallback } from 'react';
import styles from './albums.module.scss';
import { AlbumDetail } from '@/app/modules/library-music/components/album-detail/album-detail.tsx';
import { CoverGrid } from '@/app/modules/library-music/components/cover-grid';
import { Album } from '@/app/modules/library-music/components/album';
import { Box, ContextMenu, Dialog, Flex, Skeleton } from '@radix-ui/themes';
import { usePathParam } from '@/app/hooks/use-path-param.ts';
import { LibraryParams } from '@/app/modules/library-music/routes/_routes.tsx';
import { motion } from 'motion/react';
import { useDisclosure } from '@/app/hooks/use-disclosure.ts';
import { AlbumEditor } from '@/app/modules/library-music/components/album-editor/album-editor.tsx';
import { AlbumResource, AlbumUpdateRequest } from '@/app/libs/api-client/gen/models';
import { useAlbumsIndex, useAlbumsUpdate } from '@/app/libs/api-client/gen/endpoints/album/album.ts';
import { useMetadataSync } from '@/app/libs/api-client/gen/endpoints/metadata-sync/metadata-sync.ts';
import { useAppDispatch } from '@/app/store/hooks';
import { createNotification } from '@/app/store/notifications/notifications-slice';

function AlbumContextMenu({ album, librarySlug }: { album: AlbumResource, librarySlug: string }) {
  const [showEditor, editorHandlers] = useDisclosure(false);
  const dispatch = useAppDispatch();

  // Update mutation with default cache invalidation
  const updateMutation = useAlbumsUpdate({
    mutation: {
      onSuccess: () => {
        dispatch(createNotification({
          title: 'Success',
          message: 'Album updated successfully!',
          type: 'success',
          toast: true,
        }));
        editorHandlers.close();
      },
      onError: (error: any) => {
        dispatch(createNotification({
          title: 'Error',
          message: error.response?.data?.message || 'Failed to update album',
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
        // TODO: Refresh the albums list
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
        album_public_ids: [album.publicId],
        force_update: true,
      }
    });
  }, [syncMutation, album]);

  const handleAlbumSubmit = useCallback(async (data: AlbumUpdateRequest) => {
    // Submit via React Query mutation (after Precognition validation passes)
    updateMutation.mutate({
      library: librarySlug,
      album: album.publicId,
      data: data,
    });
  }, [updateMutation, librarySlug, album.publicId]);

  const handleMetadataApplied = useCallback(() => {
    dispatch(createNotification({
      title: 'Success',
      message: 'Metadata applied successfully!',
      type: 'success',
      toast: true,
    }));
    // TODO: Refresh the albums list
  }, [dispatch]);

  return (
    <>
      <ContextMenu.Content>
        <ContextMenu.Item>Play</ContextMenu.Item>
        <ContextMenu.Item onClick={() => editorHandlers.toggle()}>Edit</ContextMenu.Item>
        <ContextMenu.Separator/>
        <ContextMenu.Item color="red">Delete</ContextMenu.Item>
      </ContextMenu.Content>


      <Dialog.Root open={showEditor} onOpenChange={editorHandlers.toggle}>
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
          <Dialog.Title style={{ padding: '24px 24px 0 24px' }}>Edit Album</Dialog.Title>
          <Dialog.Description style={{ padding: '0 24px 24px 24px' }}>
            Make changes to the album information.
          </Dialog.Description>

          <div style={{ padding: '0 24px 24px 24px' }}>
            <AlbumEditor
              album={album}
              onSubmit={(data) => handleAlbumSubmit(data)}
              librarySlug={librarySlug}
              onSync={handleSync}
              onMetadataApplied={handleMetadataApplied}
            />
          </div>
        </Dialog.Content>
      </Dialog.Root>

    </>
  );
}

export default function Albums() {
  const { library: libraryParam } = usePathParam<LibraryParams>();
  const [showAlbumDetail, setShowAlbumDetail] = useState<string | null>(null);
  const { data, isLoading } = useAlbumsIndex(libraryParam, {
    relations: 'artists,cover',
  });

  return (
    <Flex justify="between" align="stretch" className={styles.albumsLayout}>
      <Box m="3" className={styles.grid}>
        <CoverGrid>
          {isLoading && <AlbumsSkeleton/>}
          {data?.data && (
            <>
              {data.data.map((album) => (
                <div className={styles.album} key={album.publicId}>
                  <ContextMenu.Root>
                    <ContextMenu.Trigger>
                      <Album
                        title={album.title}
                        primaryArtist={album?.artists?.map(x => x.name).join(',') ?? 'Unknown'}
                        imgSrc={album?.cover?.url ?? undefined}
                        onClick={() => setShowAlbumDetail(album.publicId)}
                      />
                    </ContextMenu.Trigger>

                    <AlbumContextMenu album={album} librarySlug={libraryParam}/>
                  </ContextMenu.Root>
                </div>
              ))}
            </>
          )}
        </CoverGrid>
      </Box>


      <Box
        display="block"
        position="sticky"
        right="8px"
        top="8px"
        minHeight="300px"
        minWidth="300px"
        className={styles.sidebar}
        style={{ alignSelf: 'flex-start' }} // Add this for flex containers
      >
        {showAlbumDetail && (
          <motion.div
            key={showAlbumDetail}
            layout
            initial={{ opacity: 0, scale: 0.3 }}
            animate={{ opacity: 1, scale: 1 }}
            transition={{
              duration: 0.3,
              ease: [0, 0.71, 0.2, 1.01],
            }}
          >
            <AlbumDetail albumSlug={showAlbumDetail}/>
          </motion.div>
        )}

      </Box>
    </Flex>
  );
}

function AlbumsSkeleton() {
  const generateItems = 24;

  const skeletons = [];

  for (let i = 0; i < generateItems; i++) {
    skeletons.push(<Skeleton key={i} height="220px" width="200px"/>);
  }

  return (
    <>
      {skeletons}
    </>
  );
}
