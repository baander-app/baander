import styled, { css } from 'styled-components'
import { X, Play } from 'lucide-react'
import { usePlayerStore, type Track } from '@/features/player/stores/player-store'

const Overlay = styled.div`
  position: fixed;
  inset: 0;
  z-index: 50;
  display: flex;
  align-items: center;
  justify-content: center;
`

const Backdrop = styled.div`
  position: absolute;
  inset: 0;
  background: rgb(0 0 0 / 0.5);
`

const ModalCard = styled.div`
  position: relative;
  margin: 0 1rem;
  display: flex;
  max-height: 70vh;
  width: 100%;
  max-width: 32rem;
  flex-direction: column;
  border-radius: var(--radius-lg);
  border: 1px solid var(--color-border);
  background-color: var(--color-background);
  box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
`

const ModalHeader = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid var(--color-border);
  padding: 0.75rem 1rem;
`

const ModalTitle = styled.h2`
  font-size: 0.875rem;
  font-weight: 600;
`

const HeaderActions = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
`

const TrackCount = styled.span`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const ClearButton = styled.button`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
  background: none;
  border: none;
  cursor: pointer;

  &:hover {
    color: var(--color-foreground);
  }
`

const CloseButton = styled.button`
  color: var(--color-muted-foreground);
  background: none;
  border: none;
  cursor: pointer;

  &:hover {
    color: var(--color-foreground);
  }
`

const TrackList = styled.div`
  flex: 1;
  overflow-y: auto;
`

const EmptyState = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 3rem 0;
  color: var(--color-muted-foreground);
`

const EmptyText = styled.p`
  font-size: 0.875rem;
`

const QueueItemLi = styled.li<{ $isCurrent?: boolean }>`
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.5rem 1rem;
  font-size: 0.875rem;
  transition: background-color 0.15s;

  background-color: ${({ $isCurrent }) =>
    $isCurrent ? 'var(--color-accent)' : 'transparent'};

  &:hover {
    background-color: var(--color-accent);
  }
`

const IndexCell = styled.span`
  width: 1.5rem;
  text-align: right;
  font-size: 0.75rem;
  font-variant-numeric: tabular-nums;
  color: var(--color-muted-foreground);
`

const IndexIcon = styled.span`
  color: var(--color-foreground);
`

const TrackButton = styled.button`
  min-width: 0;
  flex: 1;
  text-align: left;
  background: none;
  border: none;
  cursor: pointer;
  color: inherit;
  padding: 0;
`

const TrackName = styled.p<{ $isCurrent?: boolean }>`
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  font-weight: ${({ $isCurrent }) => ($isCurrent ? 500 : 400)};
  color: ${({ $isCurrent }) =>
    $isCurrent ? 'var(--color-foreground)' : 'var(--color-foreground)'};
  opacity: ${({ $isCurrent }) => ($isCurrent ? 1 : 0.8)};
`

const ArtistName = styled.p`
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const RemoveButton = styled.button`
  display: flex;
  height: 1.5rem;
  width: 1.5rem;
  align-items: center;
  justify-content: center;
  border-radius: 0.25rem;
  color: var(--color-muted-foreground);
  background: none;
  border: none;
  cursor: pointer;
  opacity: 0;
  transition: opacity 0.15s, color 0.15s;

  &:hover {
    color: var(--color-foreground);
  }

  ${QueueItemLi}:hover & {
    opacity: 1;
  }
`

export function QueueModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const queue = usePlayerStore((s) => s.queue)
  const currentTrack = usePlayerStore((s) => s.currentTrack)
  const removeFromQueue = usePlayerStore((s) => s.removeFromQueue)
  const playTrack = usePlayerStore((s) => s.playTrack)
  const clearQueue = usePlayerStore((s) => s.clearQueue)

  if (!open) return null

  return (
    <Overlay>
      {/* Backdrop */}
      <Backdrop onClick={onClose} />

      {/* Modal */}
      <ModalCard>
        {/* Header */}
        <ModalHeader>
          <ModalTitle>Queue</ModalTitle>
          <HeaderActions>
            <TrackCount>{queue.length} tracks</TrackCount>
            <ClearButton onClick={() => { clearQueue(); onClose() }}>
              Clear
            </ClearButton>
            <CloseButton onClick={onClose} aria-label="Close">
              <X size={16} />
            </CloseButton>
          </HeaderActions>
        </ModalHeader>

        {/* Track list */}
        <TrackList>
          {queue.length === 0 ? (
            <EmptyState>
              <EmptyText>Queue is empty</EmptyText>
            </EmptyState>
          ) : (
            <ul>
              {queue.map((track, index) => (
                <QueueItem
                  key={`${track.publicId}-${index}`}
                  track={track}
                  isCurrent={currentTrack?.publicId === track.publicId}
                  index={index}
                  onSelect={() => playTrack(track)}
                  onRemove={() => removeFromQueue(index)}
                />
              ))}
            </ul>
          )}
        </TrackList>
      </ModalCard>
    </Overlay>
  )
}

function QueueItem({
  track,
  isCurrent,
  index,
  onSelect,
  onRemove,
}: {
  track: Track
  isCurrent: boolean
  index: number
  onSelect: () => void
  onRemove: () => void
}) {
  return (
    <QueueItemLi $isCurrent={isCurrent}>
      <IndexCell>
        {isCurrent ? (
          <IndexIcon><Play size={14} fill="currentColor" /></IndexIcon>
        ) : (
          index + 1
        )}
      </IndexCell>
      <TrackButton onClick={onSelect}>
        <TrackName $isCurrent={isCurrent}>{track.title}</TrackName>
        <ArtistName>{track.artistName}</ArtistName>
      </TrackButton>
      <RemoveButton
        onClick={(e) => { e.stopPropagation(); onRemove() }}
        aria-label={`Remove ${track.title}`}
      >
        <X size={12} />
      </RemoveButton>
    </QueueItemLi>
  )
}
