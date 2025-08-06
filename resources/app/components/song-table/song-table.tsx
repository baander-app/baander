import { RefObject, useCallback, useEffect, useRef, useState } from 'react';
import { Iconify } from '@/ui/icons/iconify';
import { useAppDispatch, useAppSelector } from '@/store/hooks';
import { setQueueAndSong } from '@/store/music/music-player-slice';
import {
  ColumnDef,
  flexRender,
  getCoreRowModel,
  getSortedRowModel,
  Header,
  Row,
  SortingState,
  Table,
  useReactTable,
} from '@tanstack/react-table';
import { useVirtualizer, VirtualItem, Virtualizer } from '@tanstack/react-virtual';
import { SpeakerLoudIcon } from '@radix-ui/react-icons';
import styles from './song-table.module.scss';
import { SongResource } from '@/libs/api-client/gen/models';

export interface SongTableProps {
  songs: SongResource[];
  title?: string | null;
  description?: string | null;
  onScrollToBottom?: () => void;
  estimatedTotalCount?: number;
  className?: string;
}

interface TableHeaderProps {
  title?: string | null;
  description?: string | null;
}

interface StickyHeaderProps {
  table: Table<SongResource>;
}

interface HeaderCellProps {
  header: Header<SongResource, unknown>;
}

interface VirtualizedRowsProps {
  visibleRows: VirtualizedRowData[];
  onSongClick: (id: string) => void;
}

interface SongTitleCellProps {
  song: SongResource;
}

interface VirtualizedRowData {
  virtualRow: VirtualItem;
  row: Row<SongResource>;
}

interface UseVirtualizedTableProps {
  table: Table<SongResource>;
  parentRef: RefObject<HTMLDivElement | null>;
  estimatedTotalCount?: number;
  onScrollToBottom?: () => void;
  lastScrollTime: RefObject<number>;
  hasTriggered: RefObject<boolean>;
}

interface UseVirtualizedTableReturn {
  virtualizer: Virtualizer<HTMLDivElement, Element>;
  visibleRows: VirtualizedRowData[];
}

const COLUMN_DEFINITIONS: ColumnDef<SongResource>[] = [
  {
    header: 'Title',
    cell: (info) => <SongTitleCell song={info.row.original}/>,
  },
  {
    header: 'Lyrics',
    accessorKey: 'lyricsExist',
    cell: (info) => info.getValue() ? <Iconify icon="arcticons:quicklyric"/> : null,
    size: 60,
  },
  {
    header: 'Artist',
    accessorFn: (row) => row.artists?.map(x => x.name).join(', '),
  },
  {
    header: 'Album',
    accessorFn: (row) => row.album?.title,
  },
  {
    header: 'Duration',
    accessorKey: 'durationHuman',
    size: 80,
  },
  {
    header: 'Track',
    accessorKey: 'track',
    size: 60,
  },
];

export function SongTable({
                            songs,
                            title,
                            description,
                            onScrollToBottom,
                            estimatedTotalCount,
                            className,
                          }: SongTableProps) {
  const dispatch = useAppDispatch();
  const [sorting, setSorting] = useState<SortingState>([]);
  const parentRef = useRef<HTMLDivElement>(null);
  const lastScrollTimeRef = useRef(0);
  const hasTriggeredRef = useRef(false);

  const handleSongClick = useCallback((publicId: string) => {
    const newQueue = [...songs];
    const index = newQueue.findIndex(x => x.publicId === publicId);
    newQueue.splice(0, 0, newQueue.splice(index, 1)[0]);
    dispatch(setQueueAndSong({
      queue: newQueue,
      playPublicId: newQueue[0].publicId,
    }));
  }, [dispatch, songs]);

  const table = useReactTable({
    data: songs,
    columns: COLUMN_DEFINITIONS,
    state: { sorting },
    onSortingChange: setSorting,
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: getSortedRowModel(),
    debugAll: false,
    debugColumns: false,
  });

  const { virtualizer, visibleRows } = useVirtualizedTable({
    table,
    parentRef,
    estimatedTotalCount,
    onScrollToBottom,
    lastScrollTime: lastScrollTimeRef,
    hasTriggered: hasTriggeredRef,
  });

  return (
    <div className={`${styles.scrollList} ${className || ''}`}>
      <TableHeader title={title} description={description}/>

      <div className={styles.tableContainer}>
        <StickyHeader table={table}/>

        <div ref={parentRef} className={styles.scrollableContent}>
          <div className={styles.virtualizedContainer} style={{ height: `${virtualizer.getTotalSize()}px` }}>
            <VirtualizedRows
              visibleRows={visibleRows}
              onSongClick={handleSongClick}
            />
          </div>
        </div>
      </div>
    </div>
  );
}

function TableHeader({ title, description }: TableHeaderProps) {
  if (!title && !description) return null;

  return (
    <div className={styles.header}>
      {title && <h2 className={styles.title}>{title}</h2>}
      {description && <p className={styles.description}>{description}</p>}
    </div>
  );
}

function StickyHeader({ table }: StickyHeaderProps) {
  return (
    <div className={styles.fixedHeader}>
      <table>
        <thead>
        {table.getHeaderGroups().map((headerGroup) => (
          <tr key={headerGroup.id}>
            {headerGroup.headers.map((header) => (
              <HeaderCell key={header.id} header={header}/>
            ))}
          </tr>
        ))}
        </thead>
      </table>
    </div>
  );
}

function HeaderCell({ header }: HeaderCellProps) {
  return (
    <th style={{ width: header.getSize() }}>
      {header.isPlaceholder ? null : (
        <div
          className={header.column.getCanSort() ? 'cursor-pointer select-none' : ''}
          onClick={header.column.getToggleSortingHandler()}
        >
          {flexRender(header.column.columnDef.header, header.getContext())}
          {getSortIcon(header.column.getIsSorted())}
        </div>
      )}
    </th>
  );
}

function VirtualizedRows({ visibleRows, onSongClick }: VirtualizedRowsProps) {
  return (
    <table>
      <tbody>
      {visibleRows.map(({ virtualRow, row }) => (
        <tr
          key={row.id}
          onClick={() => onSongClick(row.original.publicId)}
          className={`${styles.listItem} ${styles.virtualizedRow}`}
          style={{
            height: `${virtualRow.size}px`,
            transform: `translateY(${virtualRow.start}px)`,
          }}
        >
          {row.getVisibleCells().map((cell) => (
            <td key={cell.id} style={{ width: cell.column.getSize() }}>
              {flexRender(cell.column.columnDef.cell, cell.getContext())}
            </td>
          ))}
        </tr>
      ))}
      </tbody>
    </table>
  );
}

function SongTitleCell({ song }: SongTitleCellProps) {
  const { currentSongPublicId } = useAppSelector(state => state.musicPlayer);
  const isCurrentSong = currentSongPublicId === song.publicId;

  return (
    <div className={styles.titleCell}>
      {isCurrentSong && <SpeakerLoudIcon className={styles.titleCellNowPlayingIcon}/>}
      <div className={styles.titleCellTitle}>{song.title}</div>
    </div>
  );
}

function useVirtualizedTable({
                               table,
                               parentRef,
                               estimatedTotalCount,
                               onScrollToBottom,
                               lastScrollTime,
                               hasTriggered,
                             }: UseVirtualizedTableProps): UseVirtualizedTableReturn {
  const { rows } = table.getRowModel();
  const actualRowCount = rows.length;
  const virtualizerCount = estimatedTotalCount && estimatedTotalCount > actualRowCount ? estimatedTotalCount : actualRowCount;

  // Track previous row count to detect if this is new data or appended data
  const previousRowCount = useRef(0);

  const virtualizer = useVirtualizer({
    count: virtualizerCount,
    getScrollElement: () => parentRef.current,
    estimateSize: () => 40,
    overscan: 5,
  });

  useEffect(() => {
    // Only scroll to top if we went from 0 rows to some rows (initial load)
    // or if the row count decreased (new dataset)
    if (rows.length > 0 && (previousRowCount.current === 0 || rows.length < previousRowCount.current)) {
      virtualizer.scrollToIndex(0, { align: 'start' });
    }
    previousRowCount.current = rows.length;
  }, [rows.length, virtualizer]);

  useEffect(() => {
    hasTriggered.current = false;
  }, [rows.length, hasTriggered]);

  useEffect(() => {
    if (!onScrollToBottom) return;

    const scrollElement = parentRef.current;
    if (!scrollElement) return;

    const handleScroll = () => {
      const now = Date.now();
      if (now - lastScrollTime.current < 200) return;
      lastScrollTime.current = now;

      const virtualItems = virtualizer.getVirtualItems();
      if (virtualItems.length === 0) return;

      const lastVirtualItem = virtualItems[virtualItems.length - 1];
      const triggerThreshold = Math.max(5, Math.min(10, Math.floor(actualRowCount * 0.1)));
      const isNearEnd = lastVirtualItem.index >= actualRowCount - triggerThreshold;

      if (isNearEnd && !hasTriggered.current) {
        hasTriggered.current = true;
        onScrollToBottom();
      }
    };

    scrollElement.addEventListener('scroll', handleScroll, { passive: true });
    return () => scrollElement.removeEventListener('scroll', handleScroll);
  }, [onScrollToBottom, virtualizer, actualRowCount, lastScrollTime, hasTriggered]);

  const virtualItems = virtualizer.getVirtualItems();
  const visibleRows = virtualItems
    .filter(virtualRow => virtualRow.index < actualRowCount)
    .map(virtualRow => ({
      virtualRow,
      row: rows[virtualRow.index],
    }));

  return { virtualizer, visibleRows };
}

function getSortIcon(sortDirection: string | false) {
  const icons = { asc: ' 🔼', desc: ' 🔽' };

  return icons[sortDirection as keyof typeof icons] ?? null;
}