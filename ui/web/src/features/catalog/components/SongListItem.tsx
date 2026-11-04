import styled, { css } from 'styled-components'
import { interactiveTransition, textRole } from '@/shared/theme'
import { ContentText } from '@/shared/typography'

const Row = styled.tr`
  height: 32px;
  cursor: pointer;
  ${interactiveTransition(['color', 'background-color'])}

  &:hover {
    background-color: color-mix(in srgb, var(--color-highlight) 50%, transparent);
  }
`

const IndexCell = styled.td`
  width: 2rem;
  padding: 0 0.5rem;
  text-align: right;
  ${textRole('caption')}
  font-variant-numeric: tabular-nums;
  color: var(--color-muted-foreground);
`

const Cell = styled.td`
  padding: 0 0.5rem;
`

const truncated = css`
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`

const Title = styled(ContentText)`
  ${truncated}
  font-weight: 500;
  color: var(--color-foreground);
`

const MutedText = styled(ContentText)`
  ${truncated}
  color: var(--color-muted-foreground);
`

const DurationCell = styled.td`
  width: 4rem;
  padding: 0 0.5rem;
  text-align: right;
  ${textRole('mono-sm')}
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
  /** The song's album language, which the title and album name take. */
  language?: string | null
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
  language,
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
      <Cell><Title textRole="body-sm" text={title} language={language} /></Cell>
      <Cell><MutedText textRole="body-sm" text={artistName ?? ''} /></Cell>
      <Cell><MutedText textRole="body-sm" text={albumName ?? ''} language={language} /></Cell>
      <DurationCell>{duration !== undefined ? formatDuration(duration) : '\u2014'}</DurationCell>
      {actions && (
        <ActionsCell>{actions}</ActionsCell>
      )}
    </Row>
  )
}
