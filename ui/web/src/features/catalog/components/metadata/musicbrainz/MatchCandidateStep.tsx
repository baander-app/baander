import { useId, useState, type FormEvent } from 'react'
import styled from 'styled-components'
import { VisuallyHidden } from 'radix-ui'
import { Button } from '@/shared/components/ui/button'
import { Input } from '@/shared/components/ui/input'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { textRole } from '@/shared/theme'
import { ContentText } from '@/shared/typography'
import { MatchCandidateList } from './MatchCandidateList'
import type { MatchCandidates, MatchItemType, MatchSearch } from './types'

/** What each kind of item matches, as the empty state names it. */
const CANDIDATE_NOUNS: Readonly<Record<MatchItemType, string>> = {
  album: 'release',
  song: 'recording',
  artist: 'artist',
}

const Step = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1rem;
`

const ErrorText = styled.p`
  margin: 0;
  ${textRole('body-sm')}
  color: var(--color-destructive);
`

const EmptyText = styled.p`
  margin: 0;
  ${textRole('body-sm')}
  color: var(--color-muted-foreground);
`

const Placeholders = styled.div`
  display: flex;
  flex-direction: column;
  gap: 2px;
`

const Form = styled.form`
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 0.5rem;
`

const Field = styled.div`
  display: flex;
  min-width: 10rem;
  flex: 1;
  flex-direction: column;
  gap: 0.25rem;
`

const FieldLabel = styled.label`
  ${textRole('caption')}
  color: var(--color-muted-foreground);
`

export interface MatchCandidateStepProps {
  readonly type: MatchItemType
  /** The item's own title (an artist's name) and artist, which the form shows until a search answers or the admin edits it. */
  readonly initialSearch: MatchSearch
  /** The latest answer; while a newer search runs, the previous one. Undefined until a search answers. */
  readonly candidates: MatchCandidates | undefined
  /** A search is running. */
  readonly searching: boolean
  /** The message of the latest failed search, from `matchErrorMessage`. */
  readonly error: string | null
  /** Searches again with the title and artist of the form; an emptied artist searches by the title alone. */
  readonly onSearch: (search: MatchSearch) => void
  /** Compares the item with a candidate. */
  readonly onPick: (candidateId: string) => void
}

/**
 * The first step of a match: the candidates a search found, best first, and a form to search again
 * with an edited title or artist. A running search keeps the previous candidates visible but
 * unpickable, and a failed one keeps what the admin typed.
 */
export function MatchCandidateStep({ type, initialSearch, candidates, searching, error, onSearch, onPick }: MatchCandidateStepProps) {
  const titleId = useId()
  const artistId = useId()
  const [draft, setDraft] = useState<MatchSearch | null>(null)
  const searched: MatchSearch | undefined = candidates === undefined ? undefined : { title: candidates.title, artist: candidates.artist }
  const shown = draft ?? searched ?? initialSearch
  const byName = type === 'artist'
  const canSearch = !searching && shown.title.trim() !== ''

  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (!canSearch) {
      return
    }

    onSearch({ title: shown.title.trim(), artist: byName ? null : (shown.artist ?? '').trim() })
  }

  return (
    <Step>
      {error !== null && <ErrorText role="alert">{error}</ErrorText>}
      {candidates !== undefined && candidates.candidates.length > 0 ? (
        <>
          <MatchCandidateList candidates={candidates} disabled={searching} onPick={onPick} />
          {searching && <VisuallyHidden.Root role="status">Searching MusicBrainz…</VisuallyHidden.Root>}
        </>
      ) : searching ? (
        <Placeholders role="status">
          <VisuallyHidden.Root>Searching MusicBrainz…</VisuallyHidden.Root>
          {Array.from({ length: 3 }, (_, index) => (
            <Skeleton key={index} style={{ height: '4rem', width: '100%' }} />
          ))}
        </Placeholders>
      ) : candidates !== undefined ? (
        <NoCandidates type={type} searched={candidates} />
      ) : null}
      <Form onSubmit={submit}>
        <Field>
          <FieldLabel htmlFor={titleId}>{byName ? 'Name' : 'Title'}</FieldLabel>
          <Input id={titleId} value={shown.title} onChange={(event) => setDraft({ ...shown, title: event.target.value })} />
        </Field>
        {!byName && (
          <Field>
            <FieldLabel htmlFor={artistId}>Artist</FieldLabel>
            <Input id={artistId} value={shown.artist ?? ''} onChange={(event) => setDraft({ ...shown, artist: event.target.value })} />
          </Field>
        )}
        <Button type="submit" variant="secondary" disabled={!canSearch}>
          Search
        </Button>
      </Form>
    </Step>
  )
}

function NoCandidates({ type, searched }: { readonly type: MatchItemType; readonly searched: MatchSearch }) {
  return (
    <EmptyText>
      No MusicBrainz {CANDIDATE_NOUNS[type]} matches “<ContentText textRole="body-sm" text={searched.title} />”
      {searched.artist !== null && (
        <>
          {' by “'}
          <ContentText textRole="body-sm" text={searched.artist} />”
        </>
      )}
      . {type === 'artist' ? 'Edit the name below and search again.' : 'Edit the title or artist below and search again.'}
    </EmptyText>
  )
}
