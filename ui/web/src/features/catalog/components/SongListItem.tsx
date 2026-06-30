import styled from 'styled-components'
import { interactiveTransition } from '@/shared/theme'

const Row = styled.tr`
  cursor: pointer;
  ${interactiveTransition(['color', 'background-color'])}

  &:hover {
    background-color: color-mix(in srgb, var(--color-accent) 50%, transparent);
  }
`

const IndexCell = styled.td`
  width: 2rem;
  padding: 0.375rem 0.5rem;
  text-align: right;
  font-size: 0.75rem;
  font-variant-numeric: tabular-nums;
  color: var(--color-muted-foreground);
`

const Cell = styled.td`
  padding: 0.375rem 0.5rem;
`

const Title = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--color-foreground);
`

const MutedText = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const DurationCell = styled.td`
  width: 4rem;
  padding: 0.375rem 0.5rem;
  text-align: right;
  font-size: 0.75rem;
  font-variant-numeric: tabular-nums;
  color: var(--color-muted-foreground);
`

const ActionsCell = styled.td`
  width: 2rem;
  padding: 0.25rem;
  opacity: 0;
  transition: opacity 150ms;

  ${Row}:hover & {
    opacity: 1;
  }
`

interface SongListItemProps {
  index: number
  title: string
  artistName?: string
  albumName?: string
  duration?: number
  onClick: () => void
  onDoubleClick: () => void
  actions?: React.ReactNode
}

function formatDuration(seconds: number): string {
  if (isNaN(seconds) || !isFinite(seconds) || seconds < 0) return '\u2014'
  const m = Math.floor(seconds / 60)
  const s = Math.floor(seconds % 60)
  return `${m}:${s.toString().padStart(2, '0')}`
}

export function SongListItem({
  index,
  title,
  artistName,
  albumName,
  duration,
  onClick,
  onDoubleClick,
  actions,
}: SongListItemProps) {
  return (
    <Row
      onClick={onClick}
      onDoubleClick={onDoubleClick}
    >
      <IndexCell>{index + 1}</IndexCell>
      <Cell><Title>{title}</Title></Cell>
      <Cell><MutedText>{artistName}</MutedText></Cell>
      <Cell><MutedText>{albumName}</MutedText></Cell>
      <DurationCell>{duration !== undefined ? formatDuration(duration) : '\u2014'}</DurationCell>
      {actions && (
        <ActionsCell>{actions}</ActionsCell>
      )}
    </Row>
  )
}
