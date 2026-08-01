import { useCallback, useRef, useEffect, useState } from 'react'
import styled from 'styled-components'
import { BrowserColumn } from '../components/BrowserColumn'
import { VirtualSongList } from '../components/VirtualSongList'
import type { SongEntry } from '../types'
import { useColumnBrowser, type ColumnFocus } from '../hooks/use-column-browser'
import { useContextPanelStore } from '@/features/layout/stores/context-panel-store'
import { usePlayerStore, type Track } from '@/features/player/stores/player-store'
import { useViewModeStore } from '../stores/view-mode-store'
import { AddToPlaylistDialog } from '@/features/playlist/components/AddToPlaylistDialog'

const PageContainer = styled.div`
  display: flex;
  height: 100%;
  flex-direction: column;
  outline: none;
`

const ColumnsRow = styled.div`
  display: flex;
  min-height: 0;
  overflow: hidden;
`

const FlexColumn = styled.div`
  flex: 1;
  min-width: 0;
`

const ResizeHandle = styled.div`
  display: flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  background-color: var(--color-border);
  cursor: row-resize;
  transition: background-color 60ms ease-out;

  &:hover {
    background-color: color-mix(in srgb, var(--color-primary) 40%, transparent);
  }

  &:active {
    background-color: color-mix(in srgb, var(--color-primary) 60%, transparent);
  }
`

const HandleGrip = styled.div`
  height: 1px;
  width: 2rem;
  border-radius: 9999px;
  background-color: color-mix(in srgb, var(--color-muted-foreground) 30%, transparent);
`

const SongSection = styled.div`
  display: flex;
  min-height: 0;
  flex: 1;
  flex-direction: column;
  overflow: hidden;
`

const SongHeader = styled.div`
  display: flex;
  height: 2rem;
  flex-shrink: 0;
  align-items: center;
  border-bottom: 1px solid var(--color-border);
  background-color: color-mix(in srgb, var(--color-muted) 30%, transparent);
  padding: 0 0.75rem;
`

const SongHeaderLabel = styled.span`
  font-size: 11px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const SongHeaderCount = styled.span`
  margin-left: auto;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const SongListArea = styled.div`
  display: flex;
  min-height: 0;
  flex: 1;
  flex-direction: column;
`

const COLUMNS: ColumnFocus[] = ['genre', 'artist', 'album', 'song']
const MIN_PANE = 80
const HANDLE_H = 4

export function ColumnBrowserView() {
  const {
    genres,
    artists,
    albums,
    songs,
    selectedGenre,
    selectedArtist,
    selectedAlbum,
    setSelectedGenre,
    setSelectedArtist,
    setSelectedAlbum,
    genresLoading,
    artistsLoading,
    albumsLoading,
    songsLoading,
    focusedColumn,
    setFocusedColumn,
  } = useColumnBrowser()

  const setSelectedItem = useContextPanelStore((s) => s.setSelectedItem)
  const playTrack = usePlayerStore((s) => s.playTrack)
  const columnSplitPx = useViewModeStore((s) => s.columnSplitPx)
  const setColumnSplitPx = useViewModeStore((s) => s.setColumnSplitPx)

  const [addToPlaylistSongId, setAddToPlaylistSongId] = useState<string | null>(null)
  const [focusedSongIndex, setFocusedSongIndex] = useState(0)

  const containerRef = useRef<HTMLDivElement>(null)
  const topRef = useRef<HTMLDivElement>(null)
  const dragging = useRef(false)

  // Focus the container on mount for keyboard navigation
  useEffect(() => {
    containerRef.current?.focus()
  }, [])

  // ── Resizable divider ─────────────────────────────────
  const handlePointerDown = useCallback((e: React.PointerEvent) => {
    e.preventDefault()
    dragging.current = true
    ;(e.target as HTMLElement).setPointerCapture(e.pointerId)
  }, [])

  const handlePointerMove = useCallback(
    (e: React.PointerEvent) => {
      if (!dragging.current || !containerRef.current) return
      const rect = containerRef.current.getBoundingClientRect()
      const y = e.clientY - rect.top
      const top = Math.max(MIN_PANE, Math.min(y - HANDLE_H / 2, rect.height - MIN_PANE - HANDLE_H))
      setColumnSplitPx(top)
    },
    [setColumnSplitPx],
  )

  const handlePointerUp = useCallback(() => {
    dragging.current = false
  }, [])

  // ── Double-click to reset ─────────────────────────────
  const handleDoubleClick = useCallback(() => {
    setColumnSplitPx(null)
  }, [setColumnSplitPx])

  // ── Resolve top height ────────────────────────────────
  const topHeight = columnSplitPx ?? '50%'

  // ── Keyboard navigation ───────────────────────────────
  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent) => {
      const colIdx = COLUMNS.indexOf(focusedColumn)

      if (e.key === 'Tab') {
        e.preventDefault()
        const dir = e.shiftKey ? -1 : 1
        const next = (colIdx + dir + COLUMNS.length) % COLUMNS.length
        setFocusedColumn(COLUMNS[next])
        return
      }

      if (e.key === 'Enter') {
        if (focusedColumn === 'song' && songs.length > 0) {
          const idx = Math.min(focusedSongIndex, songs.length - 1)
          const song = songs[idx]
          if (song) {
            const tracks: Track[] = songs.map((s) => ({
              publicId: s.publicId,
              title: s.title,
              artistName: s.artistName,
              albumName: s.albumName,
              albumPublicId: s.albumPublicId,
              duration: s.duration,
            }))
            playTrack(tracks[idx], tracks)
          }
        }
        return
      }
    },
    [focusedColumn, setFocusedColumn, songs, playTrack, focusedSongIndex],
  )

  const handleSongClick = useCallback(
    (song: SongEntry, index: number) => {
      setSelectedItem({ type: 'song', publicId: song.publicId })
      setFocusedSongIndex(index)
    },
    [setSelectedItem],
  )

  return (
    <PageContainer ref={containerRef}>
      {/* Three cascading columns */}
      <ColumnsRow
        ref={topRef}
        style={{ height: topHeight, flexShrink: 0 }}
        tabIndex={-1}
        onKeyDown={handleKeyDown}
      >
        <FlexColumn>
          <BrowserColumn
            title="Genre"
            items={genres}
            selectedId={selectedGenre}
            onSelect={setSelectedGenre}
            isLoading={genresLoading}
            isFocused={focusedColumn === 'genre'}
            onFocusColumn={() => setFocusedColumn('genre')}
          />
        </FlexColumn>
        <FlexColumn>
          <BrowserColumn
            title="Artist"
            items={artists}
            selectedId={selectedArtist}
            onSelect={setSelectedArtist}
            isLoading={artistsLoading}
            isFocused={focusedColumn === 'artist'}
            onFocusColumn={() => setFocusedColumn('artist')}
          />
        </FlexColumn>
        <FlexColumn>
          <BrowserColumn
            title="Album"
            items={albums}
            selectedId={selectedAlbum}
            onSelect={setSelectedAlbum}
            isLoading={albumsLoading}
            isFocused={focusedColumn === 'album'}
            onFocusColumn={() => setFocusedColumn('album')}
          />
        </FlexColumn>
      </ColumnsRow>

      {/* Resize handle */}
      <ResizeHandle
        style={{ height: HANDLE_H }}
        onPointerDown={handlePointerDown}
        onPointerMove={handlePointerMove}
        onPointerUp={handlePointerUp}
        onDoubleClick={handleDoubleClick}
        role="separator"
        aria-orientation="horizontal"
        aria-valuenow={typeof topHeight === 'number' ? topHeight : undefined}
        aria-label="Resize columns and songs split"
        tabIndex={0}
      >
        <HandleGrip />
      </ResizeHandle>

      {/* Song table */}
      <SongSection>
        <SongHeader>
          <SongHeaderLabel>
            Songs
          </SongHeaderLabel>
          {songs.length > 0 && (
            <SongHeaderCount>
              {songs.length}
            </SongHeaderCount>
          )}
        </SongHeader>
        <SongListArea>
          <VirtualSongList
            songs={songs}
            isLoading={songsLoading}
            onSongClick={handleSongClick}
            focusedIndex={focusedSongIndex}
          />
        </SongListArea>
      </SongSection>

      <AddToPlaylistDialog
        open={!!addToPlaylistSongId}
        onOpenChange={(open) => {
          if (!open) setAddToPlaylistSongId(null)
        }}
        songId={addToPlaylistSongId ?? ''}
      />
    </PageContainer>
  )
}
