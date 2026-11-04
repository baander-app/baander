import styled from 'styled-components'
import { interactiveTransition, textRole } from '@/shared/theme'
import { ContentText } from '@/shared/typography'

const Row = styled.div`
  display: grid;
  grid-template-columns: 2rem 1fr 1fr 4rem;
  align-items: center;
  gap: 1rem;
  height: 32px;
  border-radius: var(--radius-md);
  padding: 0 0.75rem;
  ${textRole('body-sm')}
  ${interactiveTransition(['color', 'background-color'])}

  &:hover {
    background-color: var(--color-highlight);
  }
`

const MutedSpan = styled(ContentText)`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--color-muted-foreground);
`

const TitleSpan = styled(ContentText)`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: 500;
`

const TrackNumber = styled.span`
  text-align: right;
  ${textRole('caption')}
  font-variant-numeric: tabular-nums;
  color: var(--color-muted-foreground);
`

const DurationSpan = styled.span`
  text-align: right;
  ${textRole('mono-sm')}
  color: var(--color-muted-foreground);
`

interface SongRowProps {
  title: string
  /** The song's album language, which the title and album name take. */
  language?: string | null
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

export function SongRow({ title, language, artistName, albumName, trackNumber, duration }: SongRowProps) {
  return (
    <Row>
      {trackNumber !== undefined && (
        <TrackNumber>{trackNumber}</TrackNumber>
      )}
      <TitleSpan textRole="body-sm" text={title} language={language} />
      {artistName !== undefined
        ? <MutedSpan textRole="body-sm" text={artistName} />
        : <MutedSpan textRole="body-sm" text={albumName ?? ''} language={language} />}
      {duration !== undefined && (
        <DurationSpan>{formatDuration(duration)}</DurationSpan>
      )}
    </Row>
  )
}
