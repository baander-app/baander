import styled, { css } from 'styled-components'
import { memo } from 'react'
import { Play, X } from 'lucide-react'
import type { Track } from '@/features/player/stores/player-store'
import { Button } from '@/shared/components/ui/button'
import { formatDuration } from '@/shared/utils/format-duration'

const Row = styled.div`
  display: flex;
  min-width: 0;
  width: 100%;
  align-items: center;
  gap: 0.5rem;
  border-radius: var(--radius-md);
  padding: 0.375rem 0.25rem;
  font-size: 0.875rem;
  transition: background-color 150ms ease;

  &:hover {
    background-color: color-mix(in srgb, var(--color-accent) 50%, transparent);
  }
`

const IndexCell = styled.span`
  width: 1.25rem;
  flex-shrink: 0;
  text-align: right;
  font-size: 0.75rem;
  font-variant-numeric: tabular-nums;
  color: var(--color-muted-foreground);
`

const TitleButton = styled.button`
  min-width: 0;
  flex: 1;
  text-align: left;
  background: none;
  border: none;
  padding: 0;
  cursor: pointer;
  color: inherit;
  font: inherit;
`

const trackTitleStyles = css`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`

const TrackTitle = styled.p<{ $isCurrent: boolean }>`
  ${trackTitleStyles}
  font-weight: ${({ $isCurrent }) => $isCurrent ? 500 : 400};
  color: ${({ $isCurrent }) =>
    $isCurrent
      ? 'var(--color-foreground)'
      : 'color-mix(in srgb, var(--color-foreground) 80%, transparent)'};
`

const TrackArtist = styled.p`
  ${trackTitleStyles}
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const Duration = styled.span`
  font-size: 0.75rem;
  font-variant-numeric: tabular-nums;
  color: var(--color-muted-foreground);
`

const RemoveButton = styled(Button)`
  opacity: 0;
  flex-shrink: 0;

  ${Row}:hover & {
    opacity: 1;
  }
`

interface QueueRowProps {
  track: Track
  index: number
  isCurrent: boolean
  onPlay: (track: Track) => void
  onRemove: (index: number) => void
}

export const QueueRow = memo(function QueueRow({
  track,
  index,
  isCurrent,
  onPlay,
  onRemove,
}: QueueRowProps) {
  return (
    <Row>
      <IndexCell>
        {isCurrent ? (
          <Play size={12} fill="currentColor" style={{ color: 'var(--color-foreground)' }} />
        ) : (
          index + 1
        )}
      </IndexCell>

      <TitleButton onClick={() => onPlay(track)}>
        <TrackTitle $isCurrent={isCurrent}>{track.title}</TrackTitle>
        <TrackArtist>{track.artistName}</TrackArtist>
      </TitleButton>

      <Duration>
        {track.duration ? formatDuration(track.duration) : '\u2014'}
      </Duration>

      {!isCurrent && (
        <RemoveButton
          variant="ghost"
          size="icon-xs"
          onClick={() => onRemove(index)}
          aria-label={`Remove ${track.title}`}
        >
          <X size={12} />
        </RemoveButton>
      )}
    </Row>
  )
})
