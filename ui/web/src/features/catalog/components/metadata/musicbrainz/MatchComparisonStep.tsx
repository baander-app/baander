import { useId, useState } from 'react'
import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import { textRole } from '@/shared/theme'
import { ArtistActionRow, CreditsTickRow, FieldTickRow, MatchRowHeads } from './MatchRows'
import { MatchTrackPairing } from './MatchTrackPairing'
import { MATCH_STALE } from './match-error-message'
import { CREDITS_LABEL, countOf, matchFieldLabel, matchRowLabels } from './match-format'
import {
  artistActionKey,
  buildMatchSelection,
  creditsRowKey,
  defaultTicks,
  fieldRowKey,
  isPairedTrack,
  standingArtistActions,
} from './match-ticks'
import type { MatchComparison, MatchItemType, MatchSelection, MatchTrack } from './types'

const ITEM_TITLES: Readonly<Record<MatchItemType, string>> = {
  album: 'Album',
  song: 'Song',
  artist: 'Artist',
}

const Step = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
`

const Notice = styled.p`
  margin: 0;
  padding: 0.5rem 0.75rem;
  border-radius: var(--radius-md);
  background-color: var(--color-muted);
  ${textRole('body-sm')}
  color: var(--color-foreground);
`

const Section = styled.section`
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
`

const SectionTitle = styled.h3`
  margin: 0;
  ${textRole('label')}
  color: var(--color-muted-foreground);
`

const Summary = styled.p`
  margin: 0;
  ${textRole('caption')}
  color: var(--color-muted-foreground);
`

const Footer = styled.div`
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
`

export interface MatchComparisonStepProps {
  readonly comparison: MatchComparison
  /** An apply is running: no tick can change and the buttons are disabled. */
  readonly applying: boolean
  /**
   * After the comparison was reloaded because the item changed, the tick keys (`fieldRowKey`,
   * `creditsRowKey`) of the rows whose current value changed; a notice lists them.
   */
  readonly changedRowKeys?: readonly string[]
  /** The item's stored language, which its titles and names take. */
  readonly language?: string | null
  /** Applies the ticked rows. */
  readonly onApply: (selection: MatchSelection) => void
  /** Returns to the candidates. */
  readonly onBack?: () => void
}

/**
 * The second step of a match: every field, credit and track the match would set, each with its
 * tick, and the artists it would merge or remove. The ticks start at the comparison's defaults;
 * mount the step again, with a new `key`, to reset them for a reloaded comparison. Unticking a
 * credits row hides the merges and removals that need it.
 */
export function MatchComparisonStep({ comparison, applying, changedRowKeys = [], language, onApply, onBack }: MatchComparisonStepProps) {
  const [ticks, setTicks] = useState(() => defaultTicks(comparison))
  const ownTitleId = useId()
  const tracksTitleId = useId()
  const actionsTitleId = useId()
  const standing = standingArtistActions(comparison, ticks)
  const { credits } = comparison

  const tick = (key: string, ticked: boolean) => {
    setTicks((current) => ({ ...current, [key]: ticked }))
  }

  return (
    <Step>
      {changedRowKeys.length > 0 && <ChangedRowsNotice comparison={comparison} keys={changedRowKeys} />}
      {(comparison.fields.length > 0 || credits !== null) && (
        <Section aria-labelledby={ownTitleId}>
          <SectionTitle id={ownTitleId}>{ITEM_TITLES[comparison.type]}</SectionTitle>
          <MatchRowHeads />
          {comparison.fields.map((row) => (
            <FieldTickRow
              key={row.field}
              row={row}
              label={matchFieldLabel(row.field, comparison.type)}
              language={language}
              ticked={ticks[fieldRowKey(row)] === true}
              applying={applying}
              onTick={(ticked) => tick(fieldRowKey(row), ticked)}
            />
          ))}
          {credits !== null && (
            <CreditsTickRow
              row={credits}
              label={CREDITS_LABEL}
              ticked={ticks[creditsRowKey(credits)] === true}
              applying={applying}
              onTick={(ticked) => tick(creditsRowKey(credits), ticked)}
            />
          )}
        </Section>
      )}
      {comparison.tracks.length > 0 && (
        <Section aria-labelledby={tracksTitleId}>
          <SectionTitle id={tracksTitleId}>Tracks</SectionTitle>
          <TrackSummary tracks={comparison.tracks} />
          <MatchTrackPairing tracks={comparison.tracks} ticks={ticks} applying={applying} onTick={tick} language={language} />
        </Section>
      )}
      {standing.length > 0 && (
        <Section aria-labelledby={actionsTitleId}>
          <SectionTitle id={actionsTitleId}>Artists merged or removed</SectionTitle>
          {standing.map((action) => (
            <ArtistActionRow
              key={action.artistPublicId}
              action={action}
              ticked={ticks[artistActionKey(action)] === true}
              applying={applying}
              onTick={(ticked) => tick(artistActionKey(action), ticked)}
            />
          ))}
        </Section>
      )}
      <Footer>
        {onBack !== undefined && (
          <Button variant="ghost" disabled={applying} onClick={onBack}>
            Back
          </Button>
        )}
        <Button disabled={applying} onClick={() => onApply(buildMatchSelection(comparison, ticks))}>
          {applying ? 'Applying…' : 'Apply'}
        </Button>
      </Footer>
    </Step>
  )
}

function ChangedRowsNotice({ comparison, keys }: { readonly comparison: MatchComparison; readonly keys: readonly string[] }) {
  const labels = matchRowLabels(comparison)
  const changed = keys.map((key) => labels.get(key)).filter((label): label is string => label !== undefined)

  return (
    <Notice role="status">
      {MATCH_STALE} Every tick is back to its default.
      {changed.length > 0 && ` Changed since then: ${changed.join(', ')}.`}
    </Notice>
  )
}

/** How many tracks pair, and how many are left on either side. */
function TrackSummary({ tracks }: { readonly tracks: readonly MatchTrack[] }) {
  const paired = tracks.filter(isPairedTrack).length
  const releaseOnly = tracks.filter((track) => track.song === null).length
  const localOnly = tracks.filter((track) => track.release === null).length
  const parts = [
    countOf(paired, 'paired track', 'paired tracks'),
    releaseOnly > 0 ? `${releaseOnly} not in your library` : null,
    localOnly > 0 ? countOf(localOnly, 'file without a track', 'files without a track') : null,
  ].filter((part) => part !== null)

  return <Summary>{parts.join(' · ')}. Only paired tracks are written.</Summary>
}
