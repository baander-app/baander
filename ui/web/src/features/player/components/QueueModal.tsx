import styled from 'styled-components'
import { Play } from 'lucide-react'
import { usePlayerStore, type Track } from '@/features/player/stores/player-store'
import {
  Dialog,
  DialogContent,
  DialogTitle,
} from '@/shared/components/ui/dialog'

const QueueContent = styled(DialogContent)`
  max-width: 32rem;
  padding: 0;
  gap: 0;
`

const ModalHeader = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid var(--color-border);
  padding: 0.75rem 2.5rem 0.75rem 1rem;
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

const TrackList = styled.div`
  flex: 1;
  overflow-y: auto;
  max-height: 70vh;
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
  color: var(--color-foreground);
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

  return (
    <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
      <QueueContent>
        {/* Header */}
        <ModalHeader>
          <DialogTitle asChild>
            <span>Queue</span>
          </DialogTitle>
          <HeaderActions>
            <TrackCount>{queue.length} tracks</TrackCount>
            <ClearButton onClick={() => { clearQueue(); onClose() }}>
              Clear
            </ClearButton>
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
      </QueueContent>
    </Dialog>
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
        {/* X icon */}
        <svg width={12} height={12} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
      </RemoveButton>
    </QueueItemLi>
  )
}
