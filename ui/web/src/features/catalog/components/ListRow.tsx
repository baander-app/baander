import styled, { css } from 'styled-components'
import React, { useCallback, useMemo } from 'react';
import { Play } from 'lucide-react';
import { formatDuration } from '@/shared/utils/format-duration';
import { useSelectionStore } from '../stores/selection-store';
import { ALL_COLUMNS, DEFAULT_WIDTHS, useListColumnStore } from '../stores/list-column-store';
import { SongContextMenu } from './menus/SongContextMenu';
import { type Track, usePlayerStore } from '@/features/player/stores/player-store';

const RowContainer = styled.div<{ $isSelected: boolean; $isPlaying: boolean }>`
  position: absolute;
  left: 0;
  top: 0;
  display: flex;
  width: 100%;
  cursor: default;
  align-items: center;
  padding: 0 0.5rem;

  ${({ $isSelected, $isPlaying }) =>
    $isSelected
      ? css`
        border-left: 2px solid var(--color-primary);
        background-color: color-mix(in srgb, var(--color-primary) 5%, transparent);
      `
      : $isPlaying
        ? css`
          background-color: color-mix(in srgb, var(--color-primary) 10%, transparent);
          color: var(--color-primary);
        `
        : css`
          color: color-mix(in srgb, var(--color-foreground) 80%, transparent);
          &:hover {
            background-color: color-mix(in srgb, var(--color-accent) 40%, transparent);
          }
        `}
`

const IndexCell = styled.span`
  position: relative;
  flex-shrink: 0;
  padding-left: 0.25rem;
  text-align: right;
  font-size: 0.75rem;
  font-variant-numeric: tabular-nums;
  color: var(--color-muted-foreground);
`

const IndexText = styled.span`
  display: inline;

  ${IndexCell}:hover & {
    display: none;
  }
`

const IndexPlayIcon = styled.span`
  display: none;
  margin-left: auto;
  cursor: pointer;

  ${IndexCell}:hover & {
    display: inline-block;
  }
`

const TitleCell = styled.span<{ $isPlaying: boolean }>`
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  padding: 0 0.5rem;
  font-size: 0.875rem;
  font-weight: 500;

  ${({ $isPlaying }) =>
    $isPlaying &&
    css`color: var(--color-primary);`}
`

const MutedCell = styled.span`
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  padding: 0 0.5rem;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const DurationCell = styled.span`
  flex-shrink: 0;
  text-align: right;
  font-size: 0.75rem;
  font-variant-numeric: tabular-nums;
  color: var(--color-muted-foreground);
`

const MonoCell = styled.span`
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  padding: 0 0.5rem;
  font-size: 0.75rem;
  font-family: var(--font-mono);
  color: var(--color-muted-foreground);
`

const Spacer = styled.span`
  width: 0.75rem;
  flex-shrink: 0;
`

export interface ListSongData {
  publicId: string;
  title: string;
  artistName?: string;
  albumName?: string;
  year?: number;
  genre?: string;
  duration?: number;
  bitrate?: number;
  format?: string;
  createdAt?: string;
  albumId?: string;
  albumPublicId?: string;
  artistId?: string;
  /** Index in the list (1-based display) */
  index: number;
}

interface ListRowProps {
  song: ListSongData;
  /** Full song list for queue context when playing via Enter */
  allSongs: ListSongData[];
  style: React.CSSProperties;
}

function formatBitrate(bitrate: number): string {
  return `${Math.round(bitrate / 1000)} kbps`;
}

function formatDate(dateStr: string): string {
  try {
    return new Date(dateStr).toLocaleDateString(undefined, {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    });
  } catch {
    return dateStr;
  }
}

export function ListRow({song, allSongs, style}: ListRowProps) {
  const {visibleColumns, columnOrder, columnWidths} = useListColumnStore();
  const selectedId = useSelectionStore((s) => s.selectedId);
  const select = useSelectionStore((s) => s.select);
  const currentTrack = usePlayerStore((s) => s.currentTrack);
  const playTrack = usePlayerStore((s) => s.playTrack);

  const isSelected = selectedId === song.publicId;
  const isPlaying = currentTrack?.publicId === song.publicId;

  const orderedVisible = useMemo(
    () =>
      columnOrder
        .filter((id) => visibleColumns.includes(id))
        .map((id) => ALL_COLUMNS.find((c) => c.id === id)!)
        .filter(Boolean),
    [columnOrder, visibleColumns],
  );

  const getWidth = useCallback(
    (colId: string): number => columnWidths[colId] ?? DEFAULT_WIDTHS[colId] ?? 150,
    [columnWidths],
  );

  const handleClick = useCallback(() => {
    select(song.publicId, 'song');
  }, [select, song.publicId]);

  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        const tracks: Track[] = allSongs.map((s) => ({
          publicId: s.publicId,
          title: s.title,
          artistName: s.artistName,
          albumName: s.albumName,
          albumPublicId: s.albumPublicId,
          duration: s.duration,
        }));
        const idx = allSongs.findIndex((s) => s.publicId === song.publicId);
        playTrack(
          tracks[idx] ?? {
            publicId: song.publicId,
            title: song.title,
            artistName: song.artistName,
            albumName: song.albumName,
            albumPublicId: song.albumPublicId,
            duration: song.duration,
          },
          tracks,
        );
      }
    },
    [song, allSongs, playTrack],
  );

  const renderCell = (colId: string) => {
    const width = getWidth(colId);

    switch (colId) {
      case '#':
        return (
          <IndexCell key={colId} style={{width}}>
            {isPlaying ? (
              <Play size={10} fill="currentColor" style={{ marginLeft: 'auto', display: 'inline-block', color: 'var(--color-primary)' }} />
            ) : (
              <>
                <IndexText>{song.index}</IndexText>
                <IndexPlayIcon><Play size={10} /></IndexPlayIcon>
              </>
            )}
          </IndexCell>
        );
      case 'title':
        return (
          <TitleCell key={colId} $isPlaying={isPlaying} style={{width}}>
            {song.title}
          </TitleCell>
        );
      case 'artist':
        return (
          <MutedCell key={colId} style={{width}}>
            {song.artistName}
          </MutedCell>
        );
      case 'album':
        return (
          <MutedCell key={colId} style={{width}}>
            {song.albumName}
          </MutedCell>
        );
      case 'year':
        return (
          <MutedCell key={colId} style={{width}}>
            {song.year ?? ''}
          </MutedCell>
        );
      case 'genre':
        return (
          <MutedCell key={colId} style={{width}}>
            {song.genre ?? ''}
          </MutedCell>
        );
      case 'duration':
        return (
          <DurationCell key={colId} style={{width}}>
            {song.duration !== undefined ? formatDuration(song.duration) : '\u2014'}
          </DurationCell>
        );
      case 'bitrate':
        return (
          <MonoCell key={colId} style={{width}}>
            {song.bitrate ? formatBitrate(song.bitrate) : ''}
          </MonoCell>
        );
      case 'format':
        return (
          <MutedCell key={colId} style={{width}}>
            {song.format ?? ''}
          </MutedCell>
        );
      case 'createdAt':
        return (
          <MutedCell key={colId} style={{width}}>
            {song.createdAt ? formatDate(song.createdAt) : ''}
          </MutedCell>
        );
      default:
        return null;
    }
  };

  return (
    <SongContextMenu
      song={{
        publicId: song.publicId,
        title: song.title,
        artistName: song.artistName,
        albumName: song.albumName,
        duration: song.duration,
        albumId: song.albumId,
        artistId: song.artistId,
      }}
    >
      <RowContainer
        $isSelected={isSelected}
        $isPlaying={isPlaying}
        style={style}
        onClick={handleClick}
        onKeyDown={handleKeyDown}
        tabIndex={0}
        role="row"
      >
        {orderedVisible.map((col, i) => {
          const isLast = i === orderedVisible.length - 1;
          return (
            <React.Fragment key={col.id}>
              {renderCell(col.id)}
              {!isLast && <Spacer />}
            </React.Fragment>
          );
        })}
      </RowContainer>
    </SongContextMenu>
  );
}
