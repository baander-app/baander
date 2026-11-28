import { useRef, useState } from 'react'
import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import { focusVisibleRing, textRole } from '@/shared/theme'
import { ContentText } from '@/shared/typography'
import { useLibraries } from '@/features/library/hooks/use-libraries'
import type { VideoMatchReviewPageResourceItemsItem } from '@/shared/api-client/gen/endpoints'
import { useVideoMatchReviews } from '../../hooks/use-video-match-admin'
import { VideoMatchDialog } from './VideoMatchDialog'

const Section = styled.section`
  display: flex;
  flex-direction: column;
  gap: 1rem;
  min-width: 0;
`
const Heading = styled.h2`
  ${textRole('subtitle')}
`
const Filters = styled.div`
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  label {
    display: grid;
    gap: 0.375rem;
    ${textRole('body-sm')}
  }
  select {
    ${focusVisibleRing}
    padding: 0.5rem;
    background: var(--color-background);
    color: var(--color-foreground);
    border: 1px solid var(--color-input);
    border-radius: ${({ theme }) => theme.radii.md};
  }
`
const Scroll = styled.div`
  overflow-x: auto;
`
const Table = styled.table`
  width: 100%;
  border-collapse: collapse;
  ${textRole('body-sm')}
  th, td {
    padding: 0.75rem;
    text-align: left;
    border-bottom: 1px solid var(--color-border);
  }
  th {
    ${textRole('caption')}
    color: var(--color-muted-foreground);
  }
`
const Path = styled.div`
  ${textRole('mono-sm')}
  overflow-wrap: anywhere;
  color: var(--color-muted-foreground);
`
const Status = styled.p`
  ${textRole('body-sm')}
  margin: 0;
`
const Failure = styled(Status)`
  color: var(--color-destructive);
`

const VIDEO_REVIEW_REASONS: Record<string, string> = {
  no_candidates: 'No candidates found',
  low_score: 'Best score too low',
  close_runner_up: 'Runner-up scores too close',
  duplicate_in_library: 'Duplicate in library',
  duplicate_file: 'Duplicate file',
  episode_collision: 'Episode collision',
  name_disagreement: 'Filename disagrees with episode',
  assignment_reset: 'Assignment needs review after rematch',
  ordering_unavailable: 'Episode ordering unavailable',
  login_failed: 'Provider login failed',
  unavailable: 'Provider unavailable',
  provider_paused: 'Provider matching paused',
  refused: 'Provider refused request',
}

const KIND_LABELS = { series: 'Series', movie: 'Movie', file: 'File' } as const

export function VideoMatchReviewTable() {
  const [kind, setKind] = useState('')
  const [library, setLibrary] = useState('')
  const [reason, setReason] = useState('')
  const [selected, setSelected] = useState<VideoMatchReviewPageResourceItemsItem | null>(null)
  const heading = useRef<HTMLHeadingElement>(null)
  const trigger = useRef<HTMLButtonElement | null>(null)
  const libraries = useLibraries()
  const reviews = useVideoMatchReviews({
    kind: kind || undefined,
    library: library || undefined,
    reason: reason || undefined,
  })
  const rows = reviews.data?.pages.flatMap(page => page.items) ?? []
  const paused = reviews.data?.pages[0]?.pausedProviders ?? []
  const restoreFocus = () => {
    requestAnimationFrame(() => {
      if (trigger.current?.isConnected) trigger.current.focus()
      else heading.current?.focus()
    })
  }

  return (
    <Section aria-labelledby="video-review-heading">
      <Heading id="video-review-heading" ref={heading} tabIndex={-1}>Video reviews</Heading>
      <Filters>
        <label>
          Kind
          <select value={kind} onChange={event => setKind(event.target.value)}>
            <option value="">All kinds</option>
            <option value="series">Series</option>
            <option value="movie">Movies</option>
            <option value="file">Files</option>
          </select>
        </label>
        <label>
          Library
          <select value={library} onChange={event => setLibrary(event.target.value)} disabled={libraries.isPending}>
            <option value="">All libraries</option>
            {libraries.data?.filter(item => item.type === 'movie' || item.type === 'tv_show').map(item => (
              <option key={item.publicId} value={item.publicId}>{item.name}</option>
            ))}
          </select>
        </label>
        <label>
          Reason
          <select value={reason} onChange={event => setReason(event.target.value)}>
            <option value="">All reasons</option>
            {Object.entries(VIDEO_REVIEW_REASONS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </select>
        </label>
      </Filters>
      {libraries.isError && (
        <Failure role="alert">
          Libraries could not be loaded.
          <Button variant="ghost" size="sm" onClick={() => libraries.refetch()}>Retry libraries</Button>
        </Failure>
      )}
      {paused.length > 0 && (
        <Status role="status">Matching is paused for {paused.map(item => `${item.provider} until ${new Date(item.retryAt).toLocaleString()}`).join(', ')}.</Status>
      )}
      {reviews.isRefetchError && !reviews.isFetchNextPageError && reviews.data && (
        <Failure role="alert">
          Video reviews could not be refreshed.
          <Button variant="ghost" size="sm" onClick={() => reviews.refetch()} disabled={reviews.isFetching}>Retry refresh</Button>
        </Failure>
      )}
      {reviews.isPending ? <Status role="status">Loading video reviews…</Status> : !reviews.data ? (
        <Failure role="alert">
          Video reviews could not be loaded.
          <Button variant="ghost" size="sm" onClick={() => reviews.refetch()} disabled={reviews.isFetching}>
            Retry video reviews
          </Button>
        </Failure>
      ) : rows.length === 0 ? <Status>No video files or titles need review for these filters.</Status> : (
        <Scroll>
          <Table aria-label="Video match reviews" aria-busy={reviews.isFetching}>
            <thead>
              <tr>
                <th scope="col">Title or file</th>
                <th scope="col">Kind</th>
                <th scope="col">Library</th>
                <th scope="col">Reason</th>
                <th scope="col">Checked</th>
                <th scope="col">Action</th>
              </tr>
            </thead>
            <tbody>
              {rows.map(review => (
                <tr key={`${review.kind}:${review.publicId}`}>
                  <td>
                    <ContentText textRole="body-sm" text={review.title} />
                    {review.path && <Path>{review.path}</Path>}
                  </td>
                  <td>{KIND_LABELS[review.kind]}</td>
                  <td>{review.libraryName}</td>
                  <td>{VIDEO_REVIEW_REASONS[review.reason] ?? review.reason}</td>
                  <td>{new Date(review.checkedAt).toLocaleString()}</td>
                  <td>
                    <Button
                      variant="outline"
                      size="sm"
                      aria-label={`Review ${review.path ?? review.title}`}
                      onClick={event => {
                        trigger.current = event.currentTarget
                        setSelected(review)
                      }}
                    >
                      Review
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </Table>
        </Scroll>
      )}
      {reviews.isFetchNextPageError && <Failure role="alert">More video reviews could not be loaded. Try again.</Failure>}
      {reviews.hasNextPage && <Button variant="outline" onClick={() => reviews.fetchNextPage()} disabled={reviews.isFetching}>Load more video reviews</Button>}
      {selected && (
        <VideoMatchDialog
          review={selected}
          open
          onOpenChange={open => {
            if (!open) {
              setSelected(null)
              restoreFocus()
            }
          }}
        />
      )}
    </Section>
  )
}
