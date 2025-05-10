import React, { useCallback, useState }                        from 'react';
import { TableVirtuoso }                                       from 'react-virtuoso';
import { Box, ContextMenu, Dialog, Text }                      from '@radix-ui/themes';
import { SongResource }                                        from '@/api-client/requests';
import {
  SongDetail,
}                                                              from '@/modules/library-music/components/song-detail/song-detail';
import { usePathParam }                                        from '@/hooks/use-path-param';
import { LibraryParams }                                       from '@/modules/library-music/routes/_routes';
import { useSongServiceGetApiLibrariesByLibrarySongsInfinite } from '@/api-client/queries/infiniteQueries';
import { Iconify }                                             from '@/ui/icons/iconify';
import { useAppDispatch }                                      from '@/store/hooks';
import { setQueueAndSong }                                     from '@/store/music/music-player-slice';
import styles                                                  from './song-list.module.scss';

interface ScrollerProps {
  style: React.CSSProperties;

  [key: string]: any;
}

const Scroller = React.forwardRef<HTMLDivElement, ScrollerProps>(({ style, ...props }, ref) => {
  // an alternative option to assign the ref is
  // <div ref={(r) => ref.current = r}>
  return <div className={styles.scrollbar} style={{ ...style }} ref={ref} {...props} />;
});

const TableComponents = {
  Scroller,
};

export function SongList() {
  const { library: libraryParam } = usePathParam<LibraryParams>();
  const dispatch = useAppDispatch();
  const { data: songData, fetchNextPage, hasNextPage } = useSongServiceGetApiLibrariesByLibrarySongsInfinite({
    library: libraryParam,
    relations: 'album,album.cover,songs.genres',
  });
  const [activeIndex, setActiveIndex] = useState(0);
  const [openedSong, setOpenedSong] = useState<SongResource>();
  const [open, setOpen] = useState(false);

  const onSongClick = useCallback((index: number) => {
    setActiveIndex(index);
    if (songData) {
      const newQueue = songData.pages.flatMap((page) => page.data).slice(index);
      dispatch(setQueueAndSong({
        queue: newQueue,
        playPublicId: newQueue[0].public_id,
      }));
    }
  }, [dispatch, songData]);

  const setOpenSong = (e: React.MouseEvent<HTMLDivElement>, song: SongResource) => {
    e.stopPropagation();
    setOpenedSong(song);
    setOpen(true);
  };

  const renderTableRow = useCallback((index: number, data: SongResource) => (
    <React.Fragment key={data.public_id}>
      <td
        className={styles.listItem}
        style={{ backgroundColor: activeIndex === index ? '#ccc' : 'unset' }}
        onClick={() => onSongClick(index)}
      >
        <ContextMenu.Root>
          <ContextMenu.Trigger>
            <Box p="0">
              <Text size="2">{data.title}</Text>
            </Box>
          </ContextMenu.Trigger>
          <ContextMenu.Content size="1">
            <ContextMenu.Item onClick={() => onSongClick(index)}>Play</ContextMenu.Item>
            <ContextMenu.Item onClick={(e) => setOpenSong(e, data)}>View info</ContextMenu.Item>
          </ContextMenu.Content>
        </ContextMenu.Root>
      </td>

      <td
        className={styles.listItem}
        style={{ backgroundColor: activeIndex === index ? '#ccc' : 'unset' }}
        onClick={() => onSongClick(index)}
      >
        {data.lyricsExist ? <Iconify icon="arcticons:quicklyric"/> : ''}
        <Text size="2">{data.lyricsExist}</Text>
      </td>

      <td
        className={styles.listItem}
        style={{ backgroundColor: activeIndex === index ? '#ccc' : 'unset' }}
        onClick={() => onSongClick(index)}
      >
        <Text size="2">{data?.album?.title}</Text>
      </td>

      <td
        className={styles.listItem}
        style={{ backgroundColor: activeIndex === index ? '#ccc' : 'unset' }}
        onClick={() => onSongClick(index)}
      >{data.album?.year}</td>

      <td
        className={styles.listItem}
        style={{ backgroundColor: activeIndex === index ? '#ccc' : 'unset' }}
        onClick={() => onSongClick(index)}
      >{data.durationHuman}</td>

      <td
        className={styles.listItem}
        style={{ backgroundColor: activeIndex === index ? '#ccc' : 'unset' }}
        onClick={() => onSongClick(index)}
      >{data.track}</td>
    </React.Fragment>
  ), [activeIndex, onSongClick]);

  return (
    <>
      <TableVirtuoso
        className={styles.scrollList}
        components={TableComponents}
        data={songData?.pages.flatMap((page) => page.data)}
        totalCount={songData?.pages[0]?.meta?.total}
        endReached={() => {
          hasNextPage && fetchNextPage();
        }}
        fixedHeaderContent={() => (
          <tr>
            <th>Title</th>
            <th>Lyrics</th>
            <th>Album</th>
            <th>Year</th>
            <th>Length</th>
            <th>Track</th>
          </tr>
        )}
        itemContent={renderTableRow}
        style={{ width: '100%' }}
      />

      <Dialog.Root open={open} onOpenChange={setOpen}>
        <Dialog.Content>
          {openedSong && <SongDetail publicId={openedSong.public_id}/>}
        </Dialog.Content>
      </Dialog.Root>
    </>
  );
}
