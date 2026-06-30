import styled, { css } from 'styled-components'
import { useRef, useCallback } from 'react'
import { useVirtualizer } from '@tanstack/react-virtual'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { VirtualSongRow } from './VirtualSongRow'
import type { SongEntry } from '@/features/catalog/types'

export type { SongEntry }

const Container = styled.div`
  display: flex;
  flex: 1;
  flex-direction: column;
  overflow: hidden;
`

const HeaderRow = styled.div`
  display: flex;
  flex-shrink: 0;
  align-items: center;
  border-bottom: 1px solid var(--color-border);
  background-color: color-mix(in srgb, var(--color-muted) 30%, transparent);
  padding: 0 0.5rem;
`

const HeaderCell = styled.span`
  font-size: 11px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const HeaderIndex = styled(HeaderCell)`
  width: 2.5rem;
  padding-left: 0.25rem;
`

const HeaderTitle = styled(HeaderCell)`
  flex: 1;
`

const HeaderQuarter = styled(HeaderCell)`
  width: 25%;
`

const HeaderDuration = styled(HeaderCell)`
  width: 4rem;
  text-align: right;
`

const HeaderActions = styled.span`
  width: 2rem;
`

const EmptyContainer = styled.div`
  display: flex;
  flex: 1;
  align-items: center;
  justify-content: center;
`

const EmptyText = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const ScrollContainer = styled.div`
  flex: 1;
  overflow-y: auto;
`

interface VirtualSongListProps {
  songs: SongEntry[]
  isLoading?: boolean
  onSongClick?: (song: SongEntry, index: number) => void
  focusedIndex?: number
}

const ROW_HEIGHT = 32
const HEADER_HEIGHT = 30

function HeaderBar({ showActions }: { showActions?: boolean }) {
  return (
    <HeaderRow style={{ height: HEADER_HEIGHT }}>
      <HeaderIndex>#</HeaderIndex>
      <HeaderTitle>Title</HeaderTitle>
      <HeaderQuarter>Artist</HeaderQuarter>
      <HeaderQuarter>Album</HeaderQuarter>
      <HeaderDuration>Time</HeaderDuration>
      {showActions && <HeaderActions />}
    </HeaderRow>
  )
}

export function VirtualSongList({
  songs,
  isLoading,
  onSongClick,
  focusedIndex,
}: VirtualSongListProps) {
  const parentRef = useRef<HTMLDivElement>(null)
  const currentTrack = usePlayerStore((s) => s.currentTrack)
  const playTrackFromList = usePlayerStore((s) => s.playTrackFromList)

  const virtualizer = useVirtualizer({
    count: songs.length,
    getScrollElement: () => parentRef.current,
    estimateSize: () => ROW_HEIGHT,
    overscan: 30,
    getItemKey: (i) => songs[i]?.publicId ?? i,
  })

  const handlePlayWithContext = useCallback(
    (index: number) => {
      playTrackFromList(songs, index)
    },
    [songs, playTrackFromList],
  )

  if (isLoading) {
    return (
      <Container>
        <HeaderBar />
        <EmptyContainer>
          <EmptyText>Loading...</EmptyText>
        </EmptyContainer>
      </Container>
    )
  }

  if (!songs.length) {
    return (
      <Container>
        <HeaderBar />
        <EmptyContainer>
          <EmptyText>No songs</EmptyText>
        </EmptyContainer>
      </Container>
    )
  }

  return (
    <Container>
      {/* Fixed header */}
      <HeaderBar showActions />

      {/* Virtualized body */}
      <ScrollContainer ref={parentRef}>
        <div
          style={{
            height: `${virtualizer.getTotalSize()}px`,
            width: '100%',
            position: 'relative',
          }}
        >
          {virtualizer.getVirtualItems().map((virtualRow) => {
            const song = songs[virtualRow.index]
            const isPlaying = currentTrack?.publicId === song.publicId
            const isFocused = focusedIndex === virtualRow.index

            return (
              <VirtualSongRow
                key={song.publicId}
                song={song}
                index={virtualRow.index}
                isPlaying={isPlaying}
                isFocused={isFocused}
                onPlay={handlePlayWithContext}
                onClick={onSongClick ?? (() => {})}
                height={virtualRow.size}
                translateY={virtualRow.start}
              />
            )
          })}
        </div>
      </ScrollContainer>
    </Container>
  )
}
