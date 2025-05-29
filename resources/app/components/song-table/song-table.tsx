import React, { useCallback, useMemo, useRef, useState } from 'react';
import { Dialog } from '@radix-ui/themes';
import { SongResource } from '@/api-client/requests';
import { SongDetail } from '@/modules/library-music/components/song-detail/song-detail';
import { Iconify } from '@/ui/icons/iconify';
import { useAppDispatch, useAppSelector } from '@/store/hooks';
import { setQueueAndSong } from '@/store/music/music-player-slice';
import {
  ColumnDef,
  flexRender,
  getCoreRowModel,
  getSortedRowModel,
  SortingState,
  useReactTable,
} from '@tanstack/react-table';
import { useVirtualizer } from '@tanstack/react-virtual';
import { SpeakerLoudIcon } from '@radix-ui/react-icons';
import styles from './song-table.module.scss';

export interface SongTableProps {
  songs: SongResource[];
  title?: string;
  description?: string;
  onFetchNextPage?: () => void;
  hasNextPage?: boolean;
  isFetchingNextPage?: boolean;
  className?: string;
}

export function SongTable({
  songs,
  title,
  description,
  onFetchNextPage,
  hasNextPage,
  isFetchingNextPage,
  className,
}: SongTableProps) {
  const dispatch = useAppDispatch();
  const [openedSong, setOpenedSong] = useState<SongResource>();
  const [open, setOpen] = useState(false);
  const [sorting, setSorting] = useState<SortingState>([]);

  const onSongClick = useCallback((publicId: string) => {
    if (songs) {
      const newQueue = [...songs];
      const index = newQueue.findIndex(x => x.public_id === publicId);
      newQueue.splice(0, 0, newQueue.splice(index, 1)[0]);
      dispatch(setQueueAndSong({
        queue: newQueue,
        playPublicId: newQueue[0].public_id,
      }));
    }
  }, [dispatch, songs]);

  // @ts-expect-error
  const setOpenSong = (e: React.MouseEvent<HTMLDivElement>, song: SongResource) => {
    e.stopPropagation();
    setOpenedSong(song);
    setOpen(true);
  };

  const columns = useMemo<Array<ColumnDef<SongResource>>>(
    () => [
      {
        'header': 'Title',
        cell: (info) => <SongTitleCell song={info.row.original}/>,
      },
      {
        'header': 'Lyrics',
        'accessorKey': 'lyricsExist',
        cell: (info) => info.getValue() ? <Iconify icon="arcticons:quicklyric"/> : null,
        size: 10,
      },
      {
        'header': 'Artist',
        accessorFn: (row) => row.artists?.map(x => x.name).join(', '),
      },
      {
        'header': 'Album',
        accessorFn: (row) => row.album?.title,
      },
      {
        'header': 'Duration',
        'accessorKey': 'durationHuman',
        size: 10,
      },
      {
        'header': 'Track',
        'accessorKey': 'track',
        size: 10,
      },
    ],
    [],
  );

  const parentRef = useRef<HTMLDivElement>(null);

  const table = useReactTable({
    data: songs,
    columns,
    state: {
      sorting,
    },
    onSortingChange: setSorting,
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: getSortedRowModel(),
    debugAll: false,
    debugColumns: false,
  });

  const { rows } = table.getRowModel();

  const virtualizer = useVirtualizer({
    count: rows.length,
    getScrollElement: () => parentRef.current,
    estimateSize: () => 24,
  });

  // Handle infinite scrolling if needed
  React.useEffect(() => {
    if (!onFetchNextPage) return;

    const [lastItem] = [...virtualizer.getVirtualItems()].reverse();

    if (!lastItem) {
      return;
    }

    if (
      lastItem.index >= songs.length - 1 &&
      hasNextPage &&
      !isFetchingNextPage
    ) {
      onFetchNextPage();
    }
  }, [
    hasNextPage,
    onFetchNextPage,
    songs.length,
    isFetchingNextPage,
    virtualizer.getVirtualItems(),
  ]);

  return (
    <>
      {(title || description) && (
        <div className={styles.header}>
          {title && <h2 className={styles.title}>{title}</h2>}
          {description && <p className={styles.description}>{description}</p>}
        </div>
      )}
      
      <div ref={parentRef} className={`${styles.scrollList} ${className || ''}`}>
        <div style={{ height: `${virtualizer.getTotalSize()}px` }}>
          <table>
            <thead>
            {table.getHeaderGroups().map((headerGroup) => (
              <tr key={headerGroup.id}>
                {headerGroup.headers.map((header) => {
                  return (
                    <th
                      key={header.id}
                      colSpan={header.colSpan}
                      style={{
                        width: header.getSize(),
                        position: 'sticky',
                        top: 0,
                      }}
                    >
                      {header.isPlaceholder ? null : (
                        <div
                          {...{
                            className: header.column.getCanSort()
                                       ? 'cursor-pointer select-none'
                                       : '',
                            onClick: header.column.getToggleSortingHandler(),
                          }}
                        >
                          {flexRender(
                            header.column.columnDef.header,
                            header.getContext(),
                          )}
                          {{
                            asc: ' 🔼',
                            desc: ' 🔽',
                          }[header.column.getIsSorted() as string] ?? null}
                        </div>
                      )}
                    </th>
                  );
                })}
              </tr>
            ))}
            </thead>
            <tbody>
            {virtualizer.getVirtualItems().map((virtualRow, index) => {
              const row = rows[virtualRow.index];
              return (
                <tr
                  key={row.id}
                  onClick={() => onSongClick(row.original.public_id)}
                  className={styles.listItem}
                  style={{
                    height: `${virtualRow.size}px`,
                    transform: `translateY(${
                      virtualRow.start - index * virtualRow.size
                    }px)`,
                  }}
                >
                  {row.getVisibleCells().map((cell) => {
                    return (
                      <td key={cell.id}>
                        {flexRender(
                          cell.column.columnDef.cell,
                          cell.getContext(),
                        )}
                      </td>
                    );
                  })}
                </tr>
              );
            })}
            </tbody>
          </table>
        </div>
      </div>

      <Dialog.Root open={open} onOpenChange={setOpen}>
        <Dialog.Content>
          {openedSong && <SongDetail publicId={openedSong.public_id}/>}
        </Dialog.Content>
      </Dialog.Root>
    </>
  );
}

interface SongTitleCellProps {
  song: SongResource;
}

function SongTitleCell({ song }: SongTitleCellProps) {
  const { currentSongPublicId } = useAppSelector(state => state.musicPlayer);

  return (
    <div className={styles.titleCell}>
      {currentSongPublicId === song.public_id && <SpeakerLoudIcon className={styles.titleCellNowPlayingIcon}/>}
      <div className={styles.titleCellTitle}>{song.title}</div>
    </div>
  );
}