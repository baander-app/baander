import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { SongResource } from '@/api-client/requests';
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
} from './types';
import { SpeakerLoudIcon } from '@radix-ui/react-icons';
import styles from './song-table.module.scss';

export interface SongTableProps {
  songs: SongResource[];
  title?: string | null;
  description?: string | null;
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

  const columns = useMemo<Array<ColumnDef<SongResource>>>(
    () => [
      {
        'header': 'Title',
        cell: (info) => <SongTitleCell song={info.row.original}/>,
        size: 200,
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
        size: 150,
      },
      {
        'header': 'Album',
        accessorFn: (row) => row.album?.title,
        size: 150,
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

  // Custom virtualization implementation
  const [visibleRange, setVisibleRange] = useState({ start: 0, end: 50 });
  const [totalHeight, setTotalHeight] = useState(rows.length * 24);
  const [visibleItems, setVisibleItems] = useState<Array<{ index: number, start: number, size: number }>>([]);
  const rowHeight = 24; // Same as the estimateSize in the original implementation

  // Handle scroll events to update visible range
  const handleScroll = useCallback(() => {
    if (!parentRef.current) return;

    const { scrollTop, clientHeight } = parentRef.current;
    const buffer = 10; // Extra rows to render above and below viewport

    const startIndex = Math.max(0, Math.floor(scrollTop / rowHeight) - buffer);
    const endIndex = Math.min(rows.length - 1, Math.ceil((scrollTop + clientHeight) / rowHeight) + buffer);

    setVisibleRange({ start: startIndex, end: endIndex });

    // Check if we need to load more data (infinite scrolling)
    if (
      endIndex >= rows.length - 5 &&
      hasNextPage &&
      !isFetchingNextPage &&
      onFetchNextPage
    ) {
      onFetchNextPage();
    }
  }, [rows.length, hasNextPage, isFetchingNextPage, onFetchNextPage]);

  // Update visible items when visible range changes
  useEffect(() => {
    const items = [];
    for (let i = visibleRange.start; i <= visibleRange.end; i++) {
      if (i < rows.length) {
        items.push({
          index: i,
          start: i * rowHeight,
          size: rowHeight
        });
      }
    }
    setVisibleItems(items);
    setTotalHeight(rows.length * rowHeight);
  }, [visibleRange, rows.length]);

  // Add scroll event listener
  useEffect(() => {
    const scrollElement = parentRef.current;
    if (!scrollElement) return;

    scrollElement.addEventListener('scroll', handleScroll);
    handleScroll(); // Initial calculation

    return () => {
      scrollElement.removeEventListener('scroll', handleScroll);
    };
  }, [handleScroll]);

  return (
    <>
      {(title || description) && (
        <div className={styles.header}>
          {title && <h2 className={styles.title}>{title}</h2>}
          {description && <p className={styles.description}>{description}</p>}
        </div>
      )}

      <div ref={parentRef} className={`${styles.scrollList} ${className || ''}`}>
        <div style={{ height: `${totalHeight}px`, position: 'relative', width: '100%' }}>
          <table style={{ width: '100%', display: 'table', tableLayout: 'fixed' }}>
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
                        cursor: 'pointer',
                        zIndex: 20
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
            {visibleItems.map((virtualRow) => {
              const row = rows[virtualRow.index];
              return (
                <tr
                  key={row.id}
                  onClick={() => onSongClick(row.original.public_id)}
                  className={styles.listItem}
                  style={{
                    height: `${virtualRow.size}px`,
                    position: 'absolute',
                    top: 0,
                    left: 0,
                    right: 0,
                    transform: `translateY(${virtualRow.start}px)`,
                    width: '100%',
                    zIndex: 1
                  }}
                >
                  {row.getVisibleCells().map((cell) => {
                    return (
                      <td 
                        key={cell.id}
                        style={{
                          width: cell.column.getSize(),
                          display: 'table-cell',
                          boxSizing: 'border-box'
                        }}
                      >
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
