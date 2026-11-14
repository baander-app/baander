import { useId, type ReactNode } from 'react'
import styled from 'styled-components'
import { VisuallyHidden } from 'radix-ui'
import { Badge } from '@/shared/components/ui/badge'
import { Button } from '@/shared/components/ui/button'
import { textRole } from '@/shared/theme'
import { ContentText, ContentTextList } from '@/shared/typography'
import { countOf, formatLifeSpan, formatMilliseconds } from './match-format'
import type { ArtistCandidate, MatchCandidates, RecordingCandidate, RecordingCandidateRelease, ReleaseCandidate } from './types'

/** How many releases a recording candidate lists before it counts the rest. */
const LISTED_RELEASES = 3

const List = styled.ol`
  display: flex;
  flex-direction: column;
  gap: 2px;
  margin: 0;
  padding: 0;
  list-style: none;
  transition: opacity 80ms ease-out;

  &[aria-busy='true'] {
    opacity: 0.5;
  }
`

const Item = styled.li`
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: start;
  gap: 0.75rem;
  padding: 0.5rem 0;

  & + & {
    border-top: 1px solid var(--color-border);
  }
`

const Body = styled.div`
  display: flex;
  min-width: 0;
  flex-direction: column;
  gap: 0.125rem;
`

const TitleLine = styled.p`
  margin: 0;
  overflow-wrap: anywhere;
  color: var(--color-foreground);
`

const Muted = styled.span`
  ${textRole('body-sm')}
  color: var(--color-muted-foreground);
`

const DetailLine = styled.p`
  display: flex;
  flex-wrap: wrap;
  column-gap: 0.375rem;
  margin: 0;
  ${textRole('caption')}
  color: var(--color-muted-foreground);
`

const Detail = styled.span`
  & + &::before {
    content: '·';
    padding-right: 0.375rem;
  }
`

const Length = styled.span`
  ${textRole('mono-sm')}
`

const ReleaseList = styled.div`
  display: flex;
  flex-direction: column;
  margin-top: 0.25rem;
`

interface MatchCandidateListProps {
  readonly candidates: MatchCandidates
  /** A newer search is running: the list stays visible, but no candidate can be picked. */
  readonly disabled: boolean
  readonly onPick: (candidateId: string) => void
}

/** The candidates of a search, best first, each with its score, the details that tell it from the others, and a button to compare it. */
export function MatchCandidateList({ candidates, disabled, onPick }: MatchCandidateListProps) {
  return (
    <List aria-label="Candidates" aria-busy={disabled}>
      {candidates.type === 'album' &&
        candidates.candidates.map((candidate) => (
          <CandidateItem key={candidate.id} id={candidate.id} score={candidate.score} disabled={disabled} onPick={onPick}>
            {(titleId) => <ReleaseSummary titleId={titleId} release={candidate} />}
          </CandidateItem>
        ))}
      {candidates.type === 'song' &&
        candidates.candidates.map((candidate) => (
          <CandidateItem key={candidate.id} id={candidate.id} score={candidate.score} disabled={disabled} onPick={onPick}>
            {(titleId) => <RecordingSummary titleId={titleId} recording={candidate} />}
          </CandidateItem>
        ))}
      {candidates.type === 'artist' &&
        candidates.candidates.map((candidate) => (
          <CandidateItem key={candidate.id} id={candidate.id} score={candidate.score} disabled={disabled} onPick={onPick}>
            {(titleId) => <ArtistSummary titleId={titleId} artist={candidate} />}
          </CandidateItem>
        ))}
    </List>
  )
}

interface CandidateItemProps {
  readonly id: string
  readonly score: number
  readonly disabled: boolean
  readonly onPick: (candidateId: string) => void
  /** The candidate's summary, given the ID its title line takes so the compare button can name it. */
  readonly children: (titleId: string) => ReactNode
}

function CandidateItem({ id, score, disabled, onPick, children }: CandidateItemProps) {
  const titleId = useId()

  return (
    <Item>
      <Badge variant="secondary">
        <VisuallyHidden.Root>Score </VisuallyHidden.Root>
        {score}
      </Badge>
      <Body>{children(titleId)}</Body>
      <Button size="sm" variant="outline" disabled={disabled} aria-describedby={titleId} onClick={() => onPick(id)}>
        Compare
      </Button>
    </Item>
  )
}

interface TitleProps {
  readonly titleId: string
  readonly title: string
  readonly disambiguation: string | null
}

function CandidateTitle({ titleId, title, disambiguation }: TitleProps) {
  return (
    <TitleLine id={titleId}>
      <ContentText textRole="body" text={title} />
      {disambiguation !== null && disambiguation !== '' && <Muted> ({disambiguation})</Muted>}
    </TitleLine>
  )
}

/** The details that are known, separated by middle dots. */
function Details({ items }: { readonly items: readonly (ReactNode | null)[] }) {
  const known = items.filter((item) => item !== null)

  return known.length === 0 ? null : <DetailLine>{known.map((item, index) => <Detail key={index}>{item}</Detail>)}</DetailLine>
}

function ReleaseSummary({ titleId, release }: { readonly titleId: string; readonly release: ReleaseCandidate }) {
  return (
    <>
      <CandidateTitle titleId={titleId} title={release.title} disambiguation={release.disambiguation} />
      <ContentText textRole="body-sm" text={release.artistCredit} element="p" />
      <Details
        items={[
          release.date,
          release.country,
          release.labels.length > 0 ? <ContentTextList textRole="caption" names={release.labels} /> : null,
          release.formats.length > 0 ? release.formats.join(', ') : null,
          countOf(release.trackCount, 'track', 'tracks'),
          countOf(release.mediumCount, 'medium', 'media'),
          release.status,
          release.hasFrontCover ? 'Front cover' : null,
        ]}
      />
    </>
  )
}

function RecordingSummary({ titleId, recording }: { readonly titleId: string; readonly recording: RecordingCandidate }) {
  const listed = recording.releases.slice(0, LISTED_RELEASES)
  const more = recording.releases.length - listed.length

  return (
    <>
      <CandidateTitle titleId={titleId} title={recording.title} disambiguation={recording.disambiguation} />
      <ContentText textRole="body-sm" text={recording.artistCredit} element="p" />
      <Details items={[recording.lengthMs === null ? null : <Length>{formatMilliseconds(recording.lengthMs)}</Length>]} />
      {listed.length > 0 && (
        <ReleaseList>
          {listed.map((release) => (
            <AppearsOn key={release.id} release={release} />
          ))}
          {more > 0 && <DetailLine>and {countOf(more, 'more release', 'more releases')}</DetailLine>}
        </ReleaseList>
      )}
    </>
  )
}

function AppearsOn({ release }: { readonly release: RecordingCandidateRelease }) {
  return (
    <Details
      items={[
        <ContentText textRole="caption" text={release.title} />,
        release.date,
        release.country,
        release.status,
        release.trackCount === null ? null : countOf(release.trackCount, 'track', 'tracks'),
      ]}
    />
  )
}

function ArtistSummary({ titleId, artist }: { readonly titleId: string; readonly artist: ArtistCandidate }) {
  return (
    <>
      <CandidateTitle titleId={titleId} title={artist.name} disambiguation={artist.disambiguation} />
      <Details items={[artist.type, artist.country, formatLifeSpan(artist.lifeSpanBegin, artist.lifeSpanEnd, artist.ended)]} />
    </>
  )
}
