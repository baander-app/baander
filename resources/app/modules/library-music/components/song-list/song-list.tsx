import React, { useCallback, useState } from 'react';
import { TableVirtuoso } from 'react-virtuoso';
import { Modal, Table, Text } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { ContextMenuContent, useContextMenu } from 'mantine-contextmenu';
import { SongResource } from '@/api-client/requests';
import { SongDetail } from '@/modules/library-music/components/song-detail/song-detail';
import { usePathParam } from '@/hooks/use-path-param';
import { LibraryParams } from '@/modules/library-music/routes/_routes';
import { useSongServiceSongsIndexInfinite } from '@/api-client/queries/infiniteQueries';
import { Iconify } from '@/ui/icons/iconify';
import { useAppDispatch } from '@/store/hooks';
import { setQueueAndSong } from '@/store/music/music-player-slice';
import styles from './song-list.module.scss';
import { TableProps } from '@mantine/core/lib/components/Table/Table';

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
  // Footer,
  Scroller,
  Table: (props: TableProps) => <Table {...props} style={{ borderCollapse: 'separate' }}/>,
  TableHead: Table.Thead,
  TableRow: Table.Tr,
  // @ts-ignore
  TableBody: React.forwardRef((props, ref) => <Table.Tbody {...props} ref={ref}/>),
};

export function SongList() {
  const { library: libraryParam } = usePathParam<LibraryParams>();
  const dispatch = useAppDispatch();
  const { data: songData, fetchNextPage, hasNextPage } = useSongServiceSongsIndexInfinite({
    library: libraryParam,
    relations: 'album,album.cover,songs.genres',
  });
  const [activeIndex, setActiveIndex] = useState(0);
  const { showContextMenu } = useContextMenu();
  const [openedSong, setOpenedSong] = useState<SongResource>();
  const [opened, { open, close }] = useDisclosure(false);

  const getContextMenuTemplate = useCallback((data: SongResource): ContextMenuContent => [
    {
      key: 'info',
      onClick: () => {
        setOpenedSong(data);
        open();
      },
    },
  ], [setOpenedSong, open]);

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

  const renderTableRow = useCallback((index: number, data: SongResource) => (
    <React.Fragment
      key={data.public_id}
    >
      <Table.Td
        className={styles.listItem}
        style={{ backgroundColor: activeIndex === index ? '#ccc' : 'unset' }}
        onClick={() => onSongClick(index)}
        onContextMenu={showContextMenu(getContextMenuTemplate(data))}
      >
        <Text size="sm">{data.title}</Text>
      </Table.Td>

      <Table.Td
        className={styles.listItem}
        style={{ backgroundColor: activeIndex === index ? '#ccc' : 'unset' }}
        onClick={() => onSongClick(index)}
        onContextMenu={showContextMenu(getContextMenuTemplate(data))}
      >
        {data.lyricsExist ? <Iconify icon="arcticons:quicklyric"/> : ''}
        <Text size="sm">{data.lyricsExist}</Text>
      </Table.Td>

      <Table.Td
        className={styles.listItem}
        style={{ backgroundColor: activeIndex === index ? '#ccc' : 'unset' }}
        onClick={() => onSongClick(index)}
      >
        <Text size="sm">{
          data?.album?.title
        }</Text>
      </Table.Td>

      <Table.Td
        className={styles.listItem}
        style={{ backgroundColor: activeIndex === index ? '#ccc' : 'unset' }}
        onClick={() => onSongClick(index)}
        onContextMenu={showContextMenu(getContextMenuTemplate(data))}
      >{data.album?.year}</Table.Td>

      <Table.Td
        className={styles.listItem}
        style={{ backgroundColor: activeIndex === index ? '#ccc' : 'unset' }}
        onClick={() => onSongClick(index)}
        onContextMenu={showContextMenu(getContextMenuTemplate(data))}
      >{data.durationHuman}</Table.Td>

      <Table.Td
        className={styles.listItem}
        style={{ backgroundColor: activeIndex === index ? '#ccc' : 'unset' }}
        onClick={() => onSongClick(index)}
        onContextMenu={showContextMenu(getContextMenuTemplate(data))}
      >{data.track}</Table.Td>
    </React.Fragment>
  ), [activeIndex, getContextMenuTemplate, onSongClick, showContextMenu]);

  return (
    <>
      <TableVirtuoso
        className={styles.scrollList}
        // @ts-ignore
        components={TableComponents}
        data={songData?.pages.flatMap((page) => page.data)}
        totalCount={songData?.pages[0].total}
        useWindowScroll={true}
        endReached={() => {
          hasNextPage && fetchNextPage();
        }}
        fixedHeaderContent={() => (
          <Table.Tr>
            <Table.Th w="50%">Title</Table.Th>
            <Table.Th w="2%">Lyrics</Table.Th>
            <Table.Th w="10%">Album</Table.Th>
            <Table.Th w="5%">Year</Table.Th>
            <Table.Th w="5%">Length</Table.Th>
            <Table.Th w="5%">Track</Table.Th>
          </Table.Tr>
        )}
        itemContent={renderTableRow}
      />

      <Modal opened={opened} onClose={close}>
        {openedSong && <SongDetail publicId={openedSong.public_id}/>}
      </Modal>
    </>
  );
}
