import styled from 'styled-components'
import { useCallback } from 'react'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { Button } from '@/shared/components/ui/button'
import { SortableContainer, SortableItem } from '@/shared/components/ui/dnd-sortable'
import { QueueRow } from './QueueRow'

const ClearButton = styled(Button)`
  color: var(--color-muted-foreground);
`

const StyledSortableList = styled(SortableContainer)`
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
`

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`

const HeaderRow = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
`

const TrackCount = styled.span`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const SortableList = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
`

const EmptyState = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  padding: 3rem 0;
`

const EmptyTitle = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const EmptySubtitle = styled.p`
  font-size: 0.75rem;
  color: color-mix(in srgb, var(--color-muted-foreground) 70%, transparent);
`

const noop = () => {}

export function QueueTab() {
  const queue = usePlayerStore((s) => s.queue)
  const currentIndex = usePlayerStore((s) => s.currentIndex)
  const currentTrack = usePlayerStore((s) => s.currentTrack)
  const removeFromQueue = usePlayerStore((s) => s.removeFromQueue)
  const playTrack = usePlayerStore((s) => s.playTrack)
  const clearQueue = usePlayerStore((s) => s.clearQueue)
  const reorderQueue = usePlayerStore((s) => s.reorderQueue)

  const handlePlay = useCallback(
    (track: Parameters<typeof playTrack>[0]) => playTrack(track),
    [playTrack],
  )

  const handleRemove = useCallback(
    (index: number) => removeFromQueue(index),
    [removeFromQueue],
  )

  if (queue.length === 0) {
    return (
      <EmptyState>
        <EmptyTitle>Queue is empty</EmptyTitle>
        <EmptySubtitle>Double-click a track or album to start playing</EmptySubtitle>
      </EmptyState>
    )
  }

  return (
    <Container>
      <HeaderRow>
        <TrackCount>{queue.length} tracks</TrackCount>
        <ClearButton variant="ghost" size="xs" onClick={clearQueue}>
          Clear all
        </ClearButton>
      </HeaderRow>

      <StyledSortableList
        items={queue.map((t) => t.publicId)}
        direction="vertical"
        onReorder={noop}
        dndContextProps={{
          collisionDetection: undefined,
          onDragEnd: (event) => {
            const { active, over } = event
            if (over && active.id !== over.id) {
              const fromIndex = queue.findIndex((t) => t.publicId === active.id)
              const toIndex = queue.findIndex((t) => t.publicId === over.id)
              reorderQueue(fromIndex, toIndex)
            }
          },
        }}
      >
        {queue.map((track, index) => (
          <SortableItem
            key={`${track.publicId}-${index}`}
            id={track.publicId}
            showHandle={currentIndex !== index}
            disabled={currentIndex === index}
          >
            <QueueRow
              track={track}
              index={index}
              isCurrent={currentTrack?.publicId === track.publicId}
              onPlay={handlePlay}
              onRemove={handleRemove}
            />
          </SortableItem>
        ))}
      </StyledSortableList>
    </Container>
  )
}
