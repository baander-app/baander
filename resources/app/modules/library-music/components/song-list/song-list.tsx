import { usePathParam } from '@/app/hooks/use-path-param';
import { LibraryParams } from '@/app/modules/library-music/routes/_routes';
import { SongTable } from '@/app/components/song-table/song-table';
import { useCallback, useRef, useMemo, useState } from 'react';
import styles from './song-list.module.scss';
import { useSongsIndexInfinite } from '@/app/libs/api-client/gen/endpoints/song/song.ts';
import { useMetadataSync } from '@/app/libs/api-client/gen/endpoints/metadata-sync/metadata-sync.ts';
import { addPagination } from '@/app/hooks/use-paginated-infinite-query.ts';
import { Dialog } from '@radix-ui/themes';
import { SongEditor } from '@/app/modules/library-music/components/song-editor/song-editor';
import { SongResource } from '@/app/libs/api-client/gen/models';
import { useAppDispatch } from '@/app/store/hooks';
import { createNotification } from '@/app/store/notifications/notifications-slice';

export function SongList() {
  const { library: libraryParam } = usePathParam<LibraryParams>();
  const dispatch = useAppDispatch();
  const isFetchingRef = useRef(false);
  const [selectedSong, setSelectedSong] = useState<SongResource | null>(null);
  const [showEditorDialog, setShowEditorDialog] = useState(false);

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
        // TODO: Refresh the songs list
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

  const {
    data: songData,
    fetchNextPage,
    hasNextPage,
    isFetchingNextPage,
  } = useSongsIndexInfinite(
    libraryParam,
    {
    relations: 'album,artists,album.cover,songs.genres',
  },
    addPagination()
  );

  const allSongs = songData ? songData.pages.flatMap((page) => page.data) : [];

  // Calculate estimated total count for smooth scrollbar
  const estimatedTotalCount = useMemo(() => {
    if (!songData?.pages?.length) return 0;

    const firstPage = songData.pages[0];

    // Try to get total from the API response metadata
    const total = (firstPage as any)?.total || (firstPage as any)?.meta?.total;

    if (total && typeof total === 'number') {
      return total;
    }

    // Fallback: estimate based on loaded pages
    if (hasNextPage) {
      // If there are more pages, estimate total as loaded pages * 3
      return allSongs.length * 3;
    }

    // If no more pages, return actual count
    return allSongs.length;
  }, [songData, allSongs.length, hasNextPage]);

  const handleScrollToBottom = useCallback(() => {
    // Prevent multiple simultaneous fetches
    if (isFetchingRef.current || isFetchingNextPage || !hasNextPage) {
      return;
    }

    isFetchingRef.current = true;
    fetchNextPage().finally(() => {
      isFetchingRef.current = false;
    });
  }, [fetchNextPage, hasNextPage, isFetchingNextPage]);

  const handleEdit = useCallback((song: SongResource) => {
    setSelectedSong(song);
    setShowEditorDialog(true);
  }, []);

  const handleSync = useCallback((song: SongResource | null) => {
    if (song) {
      syncMutation.mutate({
        data: {
          song_public_ids: [song.publicId],
          force_update: true,
        }
      });
    }
  }, [syncMutation]);

  const handleMetadataApplied = useCallback(() => {
    dispatch(createNotification({
      title: 'Success',
      message: 'Metadata applied successfully!',
      type: 'success',
      toast: true,
    }));
    // TODO: Refresh the songs list
  }, [dispatch]);

  const handleSongSubmit = useCallback(async (data: any) => {
    if (!selectedSong) return;

    try {
      await fetch(`/api/libraries/${libraryParam}/songs/${selectedSong.publicId}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
      });
      setShowEditorDialog(false);
      dispatch(createNotification({
        title: 'Success',
        message: 'Song updated successfully!',
        type: 'success',
        toast: true,
      }));
      // TODO: Refresh the songs list
    } catch (error: any) {
      dispatch(createNotification({
        title: 'Error',
        message: error.response?.data?.message || 'Failed to update song',
        type: 'error',
        toast: true,
      }));
    }
  }, [selectedSong, libraryParam, dispatch]);

  return (
    <>
      <SongTable
        songs={allSongs}
        estimatedTotalCount={estimatedTotalCount}
        onScrollToBottom={handleScrollToBottom}
        className={styles.songListTable}
        contextMenuActions={{
          onEdit: handleEdit,
        }}
      />

      {/* Song Editor Dialog */}
      <Dialog.Root open={showEditorDialog} onOpenChange={setShowEditorDialog}>
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
          <Dialog.Title style={{ padding: '24px 24px 0 24px' }}>
            Edit Song
          </Dialog.Title>
          <Dialog.Description style={{ padding: '0 24px 24px 24px' }}>
            Make changes to the song information.
          </Dialog.Description>

          {selectedSong && (
            <div style={{ padding: '0 24px 24px 24px' }}>
              <SongEditor
                song={selectedSong}
                librarySlug={libraryParam}
                onSubmit={handleSongSubmit}
                onCancel={() => setShowEditorDialog(false)}
                onSync={() => handleSync(selectedSong)}
                onMetadataApplied={handleMetadataApplied}
              />
            </div>
          )}
        </Dialog.Content>
      </Dialog.Root>
    </>
  );
}
