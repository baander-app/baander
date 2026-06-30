import styled from 'styled-components'
import { interactiveTransition } from '@/shared/theme'

const Row = styled.div`
  display: grid;
  grid-template-columns: 2rem 1fr 1fr 4rem;
  align-items: center;
  gap: 1rem;
  border-radius: var(--radius-md);
  padding: 0.5rem 0.75rem;
  font-size: 0.875rem;
  ${interactiveTransition(['color', 'background-color'])}

  &:hover {
    background-color: var(--color-accent);
  }
`

const MutedSpan = styled.span`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--color-muted-foreground);
`

const TitleSpan = styled.span`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: 500;
`

const TrackNumber = styled.span`
  text-align: right;
  color: var(--color-muted-foreground);
`

const DurationSpan = styled.span`
  text-align: right;
  color: var(--color-muted-foreground);
`

interface SongRowProps {
  title: string
  artistName?: string
  albumName?: string
  trackNumber?: number
  duration?: number
}

function formatDuration(seconds: number): string {
  const m = Math.floor(seconds / 60)
  const s = Math.floor(seconds % 60)
  return `${m}:${s.toString().padStart(2, '0')}`
}

export function SongRow({ title, artistName, albumName, trackNumber, duration }: SongRowProps) {
  return (
    <Row>
      {trackNumber !== undefined && (
        <TrackNumber>{trackNumber}</TrackNumber>
      )}
      <TitleSpan>{title}</TitleSpan>
      <MutedSpan>{artistName ?? albumName}</MutedSpan>
      {duration !== undefined && (
        <DurationSpan>{formatDuration(duration)}</DurationSpan>
      )}
    </Row>
  )
}
