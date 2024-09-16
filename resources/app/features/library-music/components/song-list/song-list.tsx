import React, { useCallback, useState } from 'react';
import { TableVirtuoso } from 'react-virtuoso';
import { Modal, Table, Text } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { ContextMenuContent, useContextMenu } from 'mantine-contextmenu';
import { TableProps } from '@mantine/core/lib/components/Table/Table';
import { SongResource } from '@/api-client/requests';
import { SongDetail } from '@/features/library-music/components/song-detail/song-detail.tsx';
import styles from './song-list.module.scss';
import { useMusicSource } from '@/providers/music-source-provider';
import { usePathParam } from '@/hooks/use-path-param.ts';
import { LibraryParams } from '@/features/library-music/routes/_routes.tsx';
import { useSongServiceSongsIndexInfinite } from '@/api-client/queries/infiniteQueries.ts';

export function SongList() {
  const { library: libraryParam } = usePathParam<LibraryParams>()

  const { data: songData, fetchNextPage, hasNextPage } = useSongServiceSongsIndexInfinite({ library: libraryParam, relations: 'album,album.cover' });
  const [activeIndex, setActiveIndex] = useState(0);

  const { showContextMenu } = useContextMenu();
  const { setSong } = useMusicSource();

  const [openedSong, setOpenedSong] = useState<SongResource>();
  const [opened, { open, close }] = useDisclosure(false);

  const getContextMenuTemplate = useCallback((data: SongResource) => {
    const contextMenuTemplate: ContextMenuContent = [
      {
        key: 'info',
        onClick: () => {
          setOpenedSong(data);
          open();
        },
      },
      {
        key: 'divider',
      },
      {
        key: 'delete',
        color: 'red',
        onClick: () => {

        },
      },
    ];

    return contextMenuTemplate;
  }, [setOpenedSong, open]);

  const onSongClick = useCallback((index: number, song: SongResource) => {
    setActiveIndex(index);

    setSong(song);
  }, [setActiveIndex, setSong]);

  return (
    <>
      <TableVirtuoso
        className={styles.scrollList}
        data={songData?.pages.flatMap(page => page.data)}
        totalCount={songData?.pages[0].total}
        // @ts-ignore
        components={TableComponents}
        useWindowScroll={true}
        endReached={() => {
          hasNextPage && fetchNextPage();
        }}
        fixedHeaderContent={() => (
          <Table.Tr>
            <Table.Td w="50%">Title</Table.Td>
            <Table.Td w="10%">Album</Table.Td>
            <Table.Td w="5%">Year</Table.Td>
            <Table.Td w="5%">Length</Table.Td>
            <Table.Td w="5%">Track</Table.Td>
          </Table.Tr>
        )}
        // @ts-ignore
        itemContent={(index, data: SongResource) => {
          return (
            <React.Fragment
              key={data.public_id}
            >
              <Table.Td
                className={styles.listItem}
                style={{ backgroundColor: activeIndex === index ? '#ccc' : 'unset' }}
                onClick={() => onSongClick(index, data)}
                onContextMenu={showContextMenu(getContextMenuTemplate(data))}
              >
                <Text size="sm">{data.title}</Text>
              </Table.Td>

              <Table.Td
                className={styles.listItem}
                style={{ backgroundColor: activeIndex === index ? '#ccc' : 'unset' }}
                onClick={() => onSongClick(index, data)}
              >
                <Text size="sm">{
                  data?.album?.title
                }</Text>
              </Table.Td>

              <Table.Td
                className={styles.listItem}
                style={{ backgroundColor: activeIndex === index ? '#ccc' : 'unset' }}
                onClick={() => onSongClick(index, data)}
                onContextMenu={showContextMenu(getContextMenuTemplate(data))}
              >{data.album?.year}</Table.Td>

              <Table.Td
                className={styles.listItem}
                style={{ backgroundColor: activeIndex === index ? '#ccc' : 'unset' }}
                onClick={() => onSongClick(index, data)}
                onContextMenu={showContextMenu(getContextMenuTemplate(data))}
              >{data.durationHuman}</Table.Td>

              <Table.Td
                className={styles.listItem}
                style={{ backgroundColor: activeIndex === index ? '#ccc' : 'unset' }}
                onClick={() => onSongClick(index, data)}
                onContextMenu={showContextMenu(getContextMenuTemplate(data))}
              >{data.track}</Table.Td>
            </React.Fragment>
          );
        }}
      />

      <Modal
        title="Song details"
        size="auto"
        opened={opened}
        onClose={close}
      >
        {openedSong && (
          <SongDetail publicId={openedSong.public_id}/>
        )}
      </Modal>
    </>
  )
    ;
}


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