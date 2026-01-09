import { memo, RefObject, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Iconify } from '@/app/ui/icons/iconify';
import { usePlayerActions, usePlayerCurrentSongPublicId } from '@/app/modules/library-music-player/store';
import { useAppDispatch } from '@/app/store/hooks';
import { createNotification } from '@/app/store/notifications/notifications-slice';
import {
  ColumnDef,
  flexRender,
  getCoreRowModel,
  getSortedRowModel,
  Header,
<<<<<<< HEAD
  Row, RowData,
=======
  Row,
  RowData,
>>>>>>> private/master
  SortingState,
  Table,
  useReactTable,
} from '@tanstack/react-table';
import { useVirtualizer, VirtualItem, Virtualizer } from '@tanstack/react-virtual';
import { SpeakerLoudIcon } from '@radix-ui/react-icons';
import { ContextMenu } from '@radix-ui/themes';
<<<<<<< HEAD
import { DndContext, closestCenter, DragEndEvent, DragStartEvent, PointerSensor, useSensor, useSensors, DragOverlay } from '@dnd-kit/core';
import { SortableContext, useSortable, verticalListSortingStrategy, arrayMove } from '@dnd-kit/sortable';
=======
import {
  closestCenter,
  DndContext,
  DragEndEvent,
  DragOverlay,
  DragStartEvent,
  PointerSensor,
  useSensor,
  useSensors,
} from '@dnd-kit/core';
import { arrayMove, SortableContext, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
>>>>>>> private/master
import { CSS } from '@dnd-kit/utilities';
import styles from './song-table.module.scss';
import { SongResource } from '@/app/libs/api-client/gen/models';
import { AddToPlaylistMenu } from '@/app/components/add-to-playlist-menu/add-to-playlist-menu';

export interface SongTableProps {
  songs: SongResource[];
  title?: string | null;
  description?: string | null;
  onScrollToBottom?: () => void;
  estimatedTotalCount?: number;
  className?: string;
  contextMenuActions?: {
    onEdit?: (song: SongResource) => void;
    onRemoveFromPlaylist?: (song: SongResource) => void;
  };
  reorderable?: boolean;
  onReorder?: (oldIndex: number, newIndex: number) => void;
}

interface TableHeaderProps {
  title?: string | null;
  description?: string | null;
}

interface StickyHeaderProps {
  table: Table<RowData>;
}

interface HeaderCellProps {
  key: string;
  header: Header<RowData, unknown>;
}

interface VirtualizedRowsProps {
  visibleRows: VirtualizedRowData[];
  onSongClick: (id: string) => void;
  contextMenuActions?: SongTableProps['contextMenuActions'];
  reorderable?: boolean;
}

interface VirtualizedRowProps {
  key: string;
  virtualRow: VirtualItem;
  row: Row<SongResource>;
  onSongClick: (id: string) => void;
  contextMenuActions?: SongTableProps['contextMenuActions'];
  reorderable?: boolean;
  index?: number;
}

interface SongTitleCellProps {
  song: SongResource;
}

interface VirtualizedRowData {
  virtualRow: VirtualItem;
  row: Row<SongResource>;
}

interface UseVirtualizedTableProps {
  table: Table<RowData>;
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

const createColumnDefinitions = (reorderable?: boolean): ColumnDef<SongResource>[] => {
  const columns: ColumnDef<SongResource>[] = [];
<<<<<<< HEAD

  if (reorderable) {
    columns.push({
      id: 'drag',
      header: '',
      cell: () => (
        <div className={styles.dragHandle}>
          <Iconify icon="ph:dots-six-vertical-bold" width={16} height={16} />
        </div>
      ),
      size: 40,
      enableSorting: false,
    });
  }

  columns.push(
    {
      header: 'Title',
      cell: (info) => <SongTitleCell song={info.row.original}/>,
    },
    {
      header: 'Lyrics',
      accessorFn: (row) => !!row.lyrics,
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
      header: 'Genre',
      accessorFn: (row) => row.genres?.map(x => x.name).join(', '),
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
    }
  );

  return columns;
};

const SongTitleCell = memo(({ song }: SongTitleCellProps) => {
=======

  if (reorderable) {
    columns.push({
      id: 'drag',
      header: '',
      cell: () => (
        <div className={styles.dragHandle}>
          <Iconify icon="ph:dots-six-vertical-bold" width={16} height={16}/>
        </div>
      ),
      size: 40,
      enableSorting: false,
    });
  }

  columns.push(
    {
      header: 'Title',
      cell: (info) => <SongTitleCell song={info.row.original}/>,
    },
    {
      header: 'Lyrics',
      accessorFn: (row) => !!row.lyrics,
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
      header: 'Genre',
      accessorFn: (row) => row.genres?.map(x => x.name).join(', '),
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
  );

  return columns;
};

const SongTitleCell = memo(({song}: SongTitleCellProps) => {
>>>>>>> private/master
  const currentSongPublicId = usePlayerCurrentSongPublicId();
  const isCurrentSong = currentSongPublicId === song.publicId;

  return (
    <div className={styles.titleCell}>
      {isCurrentSong && <SpeakerLoudIcon className={styles.titleCellNowPlayingIcon}/>}
      <div className={styles.titleCellTitle}>{song.title}</div>
    </div>
  );
});

SongTitleCell.displayName = 'SongTitleCell';

export function SongTable({
                            songs,
                            title,
                            description,
                            onScrollToBottom,
                            estimatedTotalCount,
                            className,
                            contextMenuActions,
                            reorderable,
                            onReorder,
                          }: SongTableProps) {
<<<<<<< HEAD
  const { setQueueAndPlay } = usePlayerActions();
=======
  const {setQueueAndPlay} = usePlayerActions();
>>>>>>> private/master
  const [sorting, setSorting] = useState<SortingState>([]);
  const [songsState, setSongsState] = useState(songs);
  const [activeId, setActiveId] = useState<string | null>(null);
  const parentRef = useRef<HTMLDivElement | null>(null);
  const lastScrollTimeRef = useRef(0);
  const hasTriggeredRef = useRef(false);

  // Update songs state when prop changes
  useEffect(() => {
    if (!reorderable) {
      setSongsState(songs);
    }
  }, [songs, reorderable]);

  const sensors = useSensors(
    useSensor(PointerSensor, {
      activationConstraint: {
        distance: 8,
      },
<<<<<<< HEAD
    })
=======
    }),
>>>>>>> private/master
  );

  const handleDragStart = useCallback((event: DragStartEvent) => {
    setActiveId(event.active.id);
  }, []);

  const handleDragEnd = useCallback((event: DragEndEvent) => {
<<<<<<< HEAD
    const { active, over } = event;
=======
    const {active, over} = event;
>>>>>>> private/master
    setActiveId(null);

    if (over && active.id !== over.id) {
      const oldIndex = songsState.findIndex((s) => s.publicId === active.id);
      const newIndex = songsState.findIndex((s) => s.publicId === over.id);

      if (oldIndex !== -1 && newIndex !== -1) {
        const newSongs = arrayMove(songsState, oldIndex, newIndex);
        setSongsState(newSongs);
        onReorder?.(oldIndex, newIndex);
      }
    }
  }, [songsState, onReorder]);

  const handleSongClick = useCallback((publicId: string) => {
    const newQueue = [...songs];
    const index = newQueue.findIndex(x => x.publicId === publicId);
    newQueue.splice(0, 0, newQueue.splice(index, 1)[0]);
    setQueueAndPlay(newQueue, newQueue[0].publicId);
  }, [setQueueAndPlay, songs]);


  const table = useReactTable<SongResource>({
    data: reorderable ? songsState : songs,
    columns: createColumnDefinitions(reorderable),
<<<<<<< HEAD
    state: { sorting },
=======
    state: {sorting},
>>>>>>> private/master
    onSortingChange: setSorting,
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: getSortedRowModel(),
    debugAll: false,
    debugColumns: false,
  });

  const {virtualizer, visibleRows} = useVirtualizedTable({
    table,
    parentRef,
    estimatedTotalCount,
    onScrollToBottom,
    lastScrollTime: lastScrollTimeRef,
    hasTriggered: hasTriggeredRef,
  });

  const tableContent = (
    <div className={`${styles.scrollList} ${className || ''}`}>
      <TableHeader title={title} description={description}/>

      <div className={styles.tableContainer}>
        <StickyHeader table={table}/>

        <div ref={parentRef} className={styles.scrollableContent}>
<<<<<<< HEAD
          <div className={styles.virtualizedContainer} style={{ height: `${virtualizer.getTotalSize()}px` }}>
=======
          <div className={styles.virtualizedContainer} style={{height: `${virtualizer.getTotalSize()}px`}}>
>>>>>>> private/master
            {reorderable ? (
              <SortableContext
                items={songsState.map(s => s.publicId)}
                strategy={verticalListSortingStrategy}
              >
                <VirtualizedRows
                  visibleRows={visibleRows}
                  onSongClick={handleSongClick}
                  contextMenuActions={contextMenuActions}
                  reorderable={reorderable}
                />
              </SortableContext>
            ) : (
              <VirtualizedRows
                visibleRows={visibleRows}
                onSongClick={handleSongClick}
                contextMenuActions={contextMenuActions}
                reorderable={reorderable}
              />
            )}
          </div>
        </div>
      </div>
    </div>
  );

  if (!reorderable) {
    return tableContent;
  }

  return (
    <DndContext
      sensors={sensors}
      collisionDetection={closestCenter}
      onDragStart={handleDragStart}
      onDragEnd={handleDragEnd}
    >
      {tableContent}
      <DragOverlay>
        {activeId ? (
<<<<<<< HEAD
          <div style={{ background: 'var(--gray-3)', padding: '8px', borderRadius: '4px', opacity: 0.8 }}>
=======
          <div style={{background: 'var(--gray-3)', padding: '8px', borderRadius: '4px', opacity: 0.8}}>
>>>>>>> private/master
            {songsState.find(s => s.publicId === activeId)?.title}
          </div>
        ) : null}
      </DragOverlay>
    </DndContext>
  );
}

<<<<<<< HEAD
const TableHeader = memo(({ title, description }: TableHeaderProps) => {
=======
const TableHeader = memo(({title, description}: TableHeaderProps) => {
>>>>>>> private/master
  if (!title && !description) return null;

  return (
    <div className={styles.header}>
      {title && <h2 className={styles.title}>{title}</h2>}
      {description && <p className={styles.description}>{description}</p>}
    </div>
  );
});

TableHeader.displayName = 'TableHeader';

<<<<<<< HEAD
const StickyHeader = memo(({ table }: StickyHeaderProps) => {
=======
const StickyHeader = memo(({table}: StickyHeaderProps) => {
>>>>>>> private/master
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
});

StickyHeader.displayName = 'StickyHeader';

<<<<<<< HEAD
const HeaderCell = memo(({ header }: HeaderCellProps) => {
=======
const HeaderCell = memo(({header}: HeaderCellProps) => {
>>>>>>> private/master
  return (
    <th style={{width: header.getSize()}}>
      {header.isPlaceholder ? null : (
        <div
          className={header.column.getCanSort() ? 'cursor-pointer select-none' : ''}
          onClick={header.column.getToggleSortingHandler()}
        >
          {flexRender(header.column.columnDef.header, header.getContext())}
        </div>
      )}
    </th>
  );
});

HeaderCell.displayName = 'HeaderCell';

<<<<<<< HEAD
const VirtualizedRow = memo(({ virtualRow, row, onSongClick, contextMenuActions, reorderable }: VirtualizedRowProps) => {
=======
const VirtualizedRow = memo(({virtualRow, row, onSongClick, contextMenuActions, reorderable}: VirtualizedRowProps) => {
>>>>>>> private/master
  const handleRowClick = useCallback(() => {
    onSongClick(row.original.publicId);
  }, [row.original.publicId, onSongClick]);

  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({
    id: row.original.publicId,
    disabled: !reorderable,
  });

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    height: `${virtualRow.size}px`,
    position: 'relative' as const,
    top: 0,
    left: 0,
    width: '100%',
    opacity: isDragging ? 0.5 : 1,
  };

  const rowStyle = reorderable ? style : {
    height: `${virtualRow.size}px`,
    transform: `translateY(${virtualRow.start}px)`,
  };

  const rowProps = reorderable ? {
    ref: setNodeRef,
    ...attributes,
  } : {};

  return (
    <ContextMenu.Root key={row.id}>
      <ContextMenu.Trigger>
        <tr
          {...rowProps}
          onClick={handleRowClick}
          className={`${styles.listItem} ${styles.virtualizedRow}`}
          style={rowStyle as any}
        >
          {row.getVisibleCells().map((cell) => {
            const isDragHandle = cell.column.id === 'drag';
            const cellProps = isDragHandle && reorderable ? {
              ...listeners,
            } : {};

            return (
<<<<<<< HEAD
              <td key={cell.id} style={{ width: cell.column.getSize() }} {...cellProps}>
=======
              <td key={cell.id} style={{width: cell.column.getSize()}} {...cellProps}>
>>>>>>> private/master
                {flexRender(cell.column.columnDef.cell, cell.getContext())}
              </td>
            );
          })}
        </tr>
      </ContextMenu.Trigger>

      {contextMenuActions && (
        <SongContextMenu
          song={row.original}
          onEdit={contextMenuActions.onEdit}
          onRemoveFromPlaylist={contextMenuActions.onRemoveFromPlaylist}
        />
      )}
    </ContextMenu.Root>
  );
});

VirtualizedRow.displayName = 'VirtualizedRow';

<<<<<<< HEAD
const VirtualizedRows = memo(({ visibleRows, onSongClick, contextMenuActions, reorderable }: VirtualizedRowsProps) => {
  return (
    <table>
      <tbody>
      {visibleRows.map(({ virtualRow, row }, index) => (
=======
const VirtualizedRows = memo(({visibleRows, onSongClick, contextMenuActions, reorderable}: VirtualizedRowsProps) => {
  return (
    <table>
      <tbody>
      {visibleRows.map(({virtualRow, row}, index) => (
>>>>>>> private/master
        <VirtualizedRow
          key={row.id}
          virtualRow={virtualRow}
          row={row}
          onSongClick={onSongClick}
          contextMenuActions={contextMenuActions}
          reorderable={reorderable}
          index={index}
        />
      ))}
      </tbody>
    </table>
  );
});

VirtualizedRows.displayName = 'VirtualizedRows';

interface SongContextMenuProps {
  song: SongResource;
  onEdit?: (song: SongResource) => void;
  onRemoveFromPlaylist?: (song: SongResource) => void;
}

<<<<<<< HEAD
const SongContextMenu = memo(({ song, onEdit, onRemoveFromPlaylist }: SongContextMenuProps) => {
  const { setQueueAndPlay, insertInQueue, addToQueue } = usePlayerActions();
=======
const SongContextMenu = memo(({song, onEdit, onRemoveFromPlaylist}: SongContextMenuProps) => {
  const {setQueueAndPlay, insertInQueue, addToQueue} = usePlayerActions();
>>>>>>> private/master
  const dispatch = useAppDispatch();

  const handleEditClick = useCallback(() => {
    onEdit?.(song);
  }, [onEdit, song]);

  const handleRemoveClick = useCallback(() => {
    onRemoveFromPlaylist?.(song);
  }, [onRemoveFromPlaylist, song]);

  const handlePlayNow = useCallback(() => {
    // Find the song in the current songs array and play it
    // This is a simple implementation that just plays the song
    setQueueAndPlay([song], song.publicId);
  }, [song, setQueueAndPlay]);

  const handlePlayNext = useCallback(() => {
    insertInQueue(song);
    dispatch(
      createNotification({
        title: 'Added to queue',
        message: `"${song.title}" will play next`,
        type: 'success',
        toast: true,
<<<<<<< HEAD
      })
=======
      }),
>>>>>>> private/master
    );
  }, [song, insertInQueue, dispatch]);

  const handleAddToQueue = useCallback(() => {
    addToQueue(song);
    dispatch(
      createNotification({
        title: 'Added to queue',
        message: `"${song.title}" added to queue`,
        type: 'success',
        toast: true,
<<<<<<< HEAD
      })
=======
      }),
>>>>>>> private/master
    );
  }, [song, addToQueue, dispatch]);

  return (
    <ContextMenu.Content>
<<<<<<< HEAD
      <ContextMenu.Item onClick={handlePlayNow}>
        Play Now
      </ContextMenu.Item>
      <ContextMenu.Item onClick={handlePlayNext}>
        Play Next
      </ContextMenu.Item>
      <ContextMenu.Item onClick={handleAddToQueue}>
        Add to Queue
      </ContextMenu.Item>
      <ContextMenu.Separator />
      <AddToPlaylistMenu songPublicId={song.publicId} librarySlug={song.librarySlug || 'music'} />
      {onEdit && <ContextMenu.Item onClick={handleEditClick}>Edit</ContextMenu.Item>}
      {onRemoveFromPlaylist && (
        <>
          <ContextMenu.Separator />
          <ContextMenu.Item color="red" onClick={handleRemoveClick}>Remove from Playlist</ContextMenu.Item>
=======
      <ContextMenu.Item className={styles.contentMenuItem} onClick={handlePlayNow}>
        Play Now
      </ContextMenu.Item>
      <ContextMenu.Item className={styles.contentMenuItem} onClick={handlePlayNext}>
        Play Next
      </ContextMenu.Item>
      <ContextMenu.Item className={styles.contentMenuItem} onClick={handleAddToQueue}>
        Add to Queue
      </ContextMenu.Item>

      <ContextMenu.Separator/>

      <AddToPlaylistMenu songPublicId={song.publicId} librarySlug={song.librarySlug || 'music'}/>
      {onEdit && <ContextMenu.Item  className={styles.contentMenuItem} onClick={handleEditClick}>Edit</ContextMenu.Item>}
      {onRemoveFromPlaylist && (
        <>
          <ContextMenu.Separator/>

          <ContextMenu.Item  className={styles.contentMenuItem} color="red" onClick={handleRemoveClick}>Remove from Playlist</ContextMenu.Item>
>>>>>>> private/master
        </>
      )}
    </ContextMenu.Content>
  );
});

SongContextMenu.displayName = 'SongContextMenu';

function useVirtualizedTable({
                               table,
                               parentRef,
                               estimatedTotalCount,
                               onScrollToBottom,
                               lastScrollTime,
                               hasTriggered,
                             }: UseVirtualizedTableProps): UseVirtualizedTableReturn {
  const {rows} = table.getRowModel();
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
      virtualizer.scrollToIndex(0, {align: 'start'});
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

    scrollElement.addEventListener('scroll', handleScroll, {passive: true});
    return () => scrollElement.removeEventListener('scroll', handleScroll);
  }, [onScrollToBottom, virtualizer, actualRowCount, lastScrollTime, hasTriggered]);

  const virtualItems = virtualizer.getVirtualItems();
  const visibleRows = useMemo(() =>
<<<<<<< HEAD
    virtualItems
      .filter(virtualRow => virtualRow.index < actualRowCount)
      .map(virtualRow => ({
        virtualRow,
        row: rows[virtualRow.index],
      })),
    [virtualItems, actualRowCount, rows]
=======
      virtualItems
        .filter(virtualRow => virtualRow.index < actualRowCount)
        .map(virtualRow => ({
          virtualRow,
          row: rows[virtualRow.index],
        })),
    [virtualItems, actualRowCount, rows],
>>>>>>> private/master
  );

  return {virtualizer, visibleRows} as UseVirtualizedTableReturn;
}
