import { useState } from 'react'
import styled from 'styled-components'
import { toast } from 'sonner'
import { Button } from '@/shared/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/shared/components/ui/dialog'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { textRole } from '@/shared/theme'
import { ContentText } from '@/shared/typography'
import { toApplyRequest, useApplyMatch, useMatchCandidates, useMatchComparison } from '../../hooks/use-musicbrainz-match'
import { MatchCandidateStep } from './musicbrainz/MatchCandidateStep'
import { MatchComparisonStep } from './musicbrainz/MatchComparisonStep'
import { MATCH_STALE, isMatchStale, matchErrorMessage } from './musicbrainz/match-error-message'
import { changedRowKeys } from './musicbrainz/match-ticks'
import type { MatchComparison, MatchItemType, MatchSearch, MatchSelection } from './musicbrainz/types'

const ITEM_NOUNS: Readonly<Record<MatchItemType, string>> = {
  album: 'Album',
  song: 'Song',
  artist: 'Artist',
}

const Placeholders = styled.div`
  display: flex;
  flex-direction: column;
  gap: 2px;
`

const Failure = styled.div`
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.5rem;
`

const ErrorText = styled.p`
  margin: 0;
  ${textRole('body-sm')}
  color: var(--color-destructive);
`

const Actions = styled.div`
  display: flex;
  gap: 0.5rem;
`

const Comparing = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1rem;
`

export interface MusicBrainzMatchDialogProps {
  readonly open: boolean
  readonly onOpenChange: (open: boolean) => void
  readonly type: MatchItemType
  readonly publicId: string
  /** The item's title, or an artist's name, which the dialog names and the search form starts with. */
  readonly title: string
  /** The item's primary artist, which the search form starts with; none for an artist. */
  readonly artist?: string | null
  /** The item's stored language, which its titles take. */
  readonly language?: string | null
}

/**
 * Matches an album, song or artist with MusicBrainz: the candidates a search finds, best first,
 * then the comparison with the one the admin picks, whose ticked rows an apply writes. A
 * successful apply closes the dialog. Everything the dialog read is dropped when it closes.
 */
export function MusicBrainzMatchDialog({ open, onOpenChange, ...item }: MusicBrainzMatchDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent size="wide">
        <DialogHeader>
          <DialogTitle>Match with MusicBrainz</DialogTitle>
          <DialogDescription>
            {ITEM_NOUNS[item.type]} “<ContentText textRole="body" text={item.title} language={item.language} />”
          </DialogDescription>
        </DialogHeader>
        <MatchSteps {...item} onApplied={() => onOpenChange(false)} />
      </DialogContent>
    </Dialog>
  )
}

interface MatchStepsProps extends Omit<MusicBrainzMatchDialogProps, 'open' | 'onOpenChange'> {
  readonly onApplied: () => void
}

/** Each comparison the steps show: a new one remounts the comparison step, which resets its ticks. */
interface ShownComparison {
  readonly version: number
  /** After a reload, the keys of the rows whose current value changed. */
  readonly changedRowKeys?: readonly string[]
}

function MatchSteps({ type, publicId, title, artist = null, language, onApplied }: MatchStepsProps) {
  const [search, setSearch] = useState<MatchSearch | null>(null)
  const [candidateId, setCandidateId] = useState<string | null>(null)
  const [shown, setShown] = useState<ShownComparison>({ version: 0 })
  const [stale, setStale] = useState(false)
  const candidates = useMatchCandidates(type, publicId, search)
  const comparison = useMatchComparison(type, publicId, candidateId)
  const apply = useApplyMatch()

  const pick = (id: string | null) => {
    setCandidateId(id)
    setStale(false)
    setShown((current) => ({ version: current.version + 1 }))
  }

  const applySelection = (candidate: string, selection: MatchSelection) => {
    apply.mutate(
      { type, publicId, data: toApplyRequest(candidate, selection) },
      {
        onSuccess: onApplied,
        onError: (error) => setStale(isMatchStale(error)),
      },
    )
  }

  // Reads the comparison again and shows it with default ticks, naming the rows that changed.
  const reload = async (before: MatchComparison) => {
    const result = await comparison.refetch()
    if (result.isError || result.data === undefined) {
      return
    }

    const changed = changedRowKeys(before, result.data)
    setShown((current) => ({ version: current.version + 1, changedRowKeys: changed }))
    setStale(false)
  }

  if (candidateId === null) {
    return (
      <MatchCandidateStep
        type={type}
        initialSearch={{ title, artist: type === 'artist' ? null : (artist ?? '') }}
        candidates={candidates.data}
        searching={candidates.isFetching}
        error={candidates.isError ? matchErrorMessage(candidates.error, 'The search failed. Try again.') : null}
        onSearch={setSearch}
        onPick={pick}
      />
    )
  }

  const back = () => pick(null)
  const shownComparison = comparison.data

  if (shownComparison !== undefined) {
    return (
      <Comparing>
        {stale && (
          <Failure role="alert">
            <ErrorText>
              {MATCH_STALE} Nothing was written. Reload the comparison to see what changed; every tick goes back to its default.
            </ErrorText>
            {comparison.isError && <ErrorText>{matchErrorMessage(comparison.error, 'The comparison could not be reloaded.')}</ErrorText>}
            <Button
              variant="ghost"
              disabled={comparison.isFetching}
              onClick={() => {
                reload(shownComparison).catch((error: unknown) => toast.error(matchErrorMessage(error, 'The comparison could not be reloaded.')))
              }}
            >
              Reload comparison
            </Button>
          </Failure>
        )}
        <MatchComparisonStep
          key={shown.version}
          comparison={shownComparison}
          applying={apply.isPending}
          changedRowKeys={shown.changedRowKeys}
          language={language}
          onApply={(selection) => applySelection(shownComparison.candidateId, selection)}
          onBack={back}
        />
      </Comparing>
    )
  }

  if (comparison.isError) {
    return (
      <Failure>
        <ErrorText role="alert">{matchErrorMessage(comparison.error, 'The comparison failed. Try again.')}</ErrorText>
        <Actions>
          <Button variant="ghost" onClick={back}>
            Back
          </Button>
          <Button variant="ghost" onClick={() => comparison.refetch()}>
            Try again
          </Button>
        </Actions>
      </Failure>
    )
  }

  return (
    <Placeholders role="status" aria-label="Comparing with MusicBrainz">
      {Array.from({ length: 4 }, (_, index) => (
        <Skeleton key={index} style={{ height: '2rem', width: '100%' }} />
      ))}
    </Placeholders>
  )
}
