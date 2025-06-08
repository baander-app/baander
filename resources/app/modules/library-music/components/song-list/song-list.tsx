import { usePathParam } from '@/hooks/use-path-param';
import { LibraryParams } from '@/modules/library-music/routes/_routes';
import { useSongServiceGetApiLibrariesByLibrarySongsInfinite } from '@/api-client/queries/infiniteQueries';
import { SongTable } from '@/components/song-table/song-table';
import styles from './song-list.module.scss';

export function SongList() {
  const { library: libraryParam } = usePathParam<LibraryParams>();
  const {
    data: songData,
    fetchNextPage,
    hasNextPage,
    isFetchingNextPage,
  } = useSongServiceGetApiLibrariesByLibrarySongsInfinite({
    library: libraryParam,
    relations: 'album,artists,album.cover,songs.genres',
  });

  const allSongs = songData ? songData.pages.flatMap((page) => page.data) : [];

  return (
    <SongTable
      songs={allSongs}
      onFetchNextPage={fetchNextPage}
      hasNextPage={hasNextPage}
      isFetchingNextPage={isFetchingNextPage}
      className={styles.songListTable}
    />
  );
}
