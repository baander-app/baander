import { usePathParam } from '@/hooks/use-path-param';
import { LibraryParams } from '@/modules/library-music/routes/_routes';
import { SongTable } from '@/components/song-table/song-table';
import { useCallback, useRef, useMemo } from 'react';
import styles from './song-list.module.scss';
import { useSongsIndexInfinite } from '@/libs/api-client/gen/endpoints/song/song.ts';

export function SongList() {
  const { library: libraryParam } = usePathParam<LibraryParams>();
  const isFetchingRef = useRef(false);

  const {
    data: songData,
    fetchNextPage,
    hasNextPage,
    isFetchingNextPage,
  } = useSongsIndexInfinite(libraryParam,{
    relations: 'album,artists,album.cover,songs.genres',
  });

  const allSongs = songData ? songData.pages.flatMap((page) => page.data) : [];

  // Calculate estimated total count for smooth scrollbar
  const estimatedTotalCount = useMemo(() => {
    if (!songData?.pages?.length) return 0;

    const firstPage = songData.pages[0];
    const itemsPerPage = firstPage?.data?.length || 20; // fallback to 20

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

    console.log('Triggering fetch next page', { hasNextPage, isFetchingNextPage });

    isFetchingRef.current = true;
    fetchNextPage().finally(() => {
      isFetchingRef.current = false;
    });
  }, [fetchNextPage, hasNextPage, isFetchingNextPage]);

  return (
    <SongTable
      songs={allSongs}
      estimatedTotalCount={estimatedTotalCount}
      onScrollToBottom={handleScrollToBottom}
      className={styles.songListTable}
    />
  );
}