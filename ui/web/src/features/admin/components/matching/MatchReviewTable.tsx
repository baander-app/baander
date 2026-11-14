import { useMemo, useRef } from 'react'
import styled from 'styled-components'
import { useVirtualizer } from '@tanstack/react-virtual'
import { textRole } from '@/shared/theme'
import { ContentText } from '@/shared/typography'
import { Button } from '@/shared/components/ui/button'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { MATCH_REVIEW_REASON_LABELS, useMatchReviews, type MatchReview } from '../../api/match-reviews-api'

const ROW_HEIGHT = 48
const COLUMNS = 'minmax(10rem, 1fr) 12rem 3.5rem 11rem 4.5rem'
const TABLE_MIN_WIDTH = '45rem'

const Container = styled.section`
  display: flex;
  height: 100%;
  min-height: 0;
  flex-direction: column;
  gap: 1rem;
  padding: 1.5rem;
`

const Description = styled.p`
  ${textRole('body-sm')}
  color: var(--color-muted-foreground);
`

const TableScroll = styled.div`
  min-height: 0;
  overflow: auto;
`

const HeaderRow = styled.div`
  position: sticky;
  top: 0;
  z-index: 1;
  display: grid;
  grid-template-columns: ${COLUMNS};
  gap: 1rem;
  min-width: ${TABLE_MIN_WIDTH};
  padding: 0.375rem 0.5rem;
  border-bottom: 1px solid var(--color-border);
  background: var(--color-background);
`

const ColLabel = styled.span`
  ${textRole('label')}
  color: var(--color-muted-foreground);
`

const ScoreLabel = styled(ColLabel)`
  text-align: right;
`

const RowCanvas = styled.div`
  position: relative;
  width: 100%;
  min-width: ${TABLE_MIN_WIDTH};
`

const Row = styled.div`
  position: absolute;
  top: 0;
  left: 0;
  display: grid;
  width: 100%;
  height: ${ROW_HEIGHT}px;
  grid-template-columns: ${COLUMNS};
  align-items: center;
  gap: 1rem;
  padding: 0 0.5rem;
  transition: background-color 100ms ease-out;

  &:hover {
    background: color-mix(in srgb, var(--color-highlight) 20%, transparent);
  }
`

const TitleCell = styled(ContentText)`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`

const ReasonCell = styled.span`
  ${textRole('body-sm')}
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`

const ScoreCell = styled.span`
  ${textRole('mono-sm')}
  text-align: right;
`

const CheckedCell = styled.span`
  ${textRole('caption')}
  color: var(--color-muted-foreground);
`

const SkeletonStack = styled.div`
  display: flex;
  flex-direction: column;
  gap: 2px;
`

const SkeletonRow = styled(Skeleton)`
  display: block;
  height: ${ROW_HEIGHT}px;
  background: var(--color-muted);
`

const EmptyState = styled.p`
  ${textRole('body')}
  padding: 2rem 0;
  text-align: center;
  color: var(--color-muted-foreground);
`

const ErrorState = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1rem;
  padding: 2rem 0;
`

const ErrorText = styled.p`
  ${textRole('body')}
  color: var(--color-destructive);
`

const Footer = styled.div`
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: 1rem;
`

interface MatchReviewTableProps {
  /** Starts matching the review's album by hand. */
  onMatchAlbum: (review: MatchReview) => void
}

/** The albums automatic matching could not match confidently, with the action that matches each one by hand. */
export function MatchReviewTable({ onMatchAlbum }: MatchReviewTableProps) {
  const { data, isPending, isFetching, refetch, hasNextPage, fetchNextPage, isFetchNextPageError } = useMatchReviews()
  const reviews = useMemo(() => data?.pages.flatMap((page) => page.items) ?? [], [data])

  return (
    <Container>
      <Description>Albums automatic matching could not match confidently, most recently checked first.</Description>

      {isPending ? (
        <SkeletonStack>
          {Array.from({ length: 5 }).map((_, index) => (
            <SkeletonRow key={index} />
          ))}
        </SkeletonStack>
      ) : !data ? (
        <ErrorState role="alert">
          <ErrorText>Unable to load the albums that need review.</ErrorText>
          <Button size="sm" variant="ghost" onClick={() => refetch()} disabled={isFetching}>Retry</Button>
        </ErrorState>
      ) : reviews.length === 0 ? (
        <EmptyState>No albums need a match review.</EmptyState>
      ) : (
        <>
          <ReviewRows reviews={reviews} onMatchAlbum={onMatchAlbum} />
          {hasNextPage && (
            <Footer>
              {isFetchNextPageError && <ErrorText role="alert">Unable to load more albums.</ErrorText>}
              <Button size="sm" variant="outline" onClick={() => fetchNextPage()} disabled={isFetching}>
                Load more
              </Button>
            </Footer>
          )}
        </>
      )}
    </Container>
  )
}

interface ReviewRowsProps {
  reviews: readonly MatchReview[]
  onMatchAlbum: (review: MatchReview) => void
}

function ReviewRows({ reviews, onMatchAlbum }: ReviewRowsProps) {
  const scrollRef = useRef<HTMLDivElement>(null)
  const virtualizer = useVirtualizer({
    count: reviews.length,
    getScrollElement: () => scrollRef.current,
    estimateSize: () => ROW_HEIGHT,
    overscan: 10,
    getItemKey: (index) => reviews[index].albumPublicId,
  })

  return (
    <TableScroll ref={scrollRef}>
      <HeaderRow>
        <ColLabel>Album</ColLabel>
        <ColLabel>Reason</ColLabel>
        <ScoreLabel>Score</ScoreLabel>
        <ColLabel>Checked</ColLabel>
        <span />
      </HeaderRow>
      <RowCanvas style={{ height: `${virtualizer.getTotalSize()}px` }}>
        {virtualizer.getVirtualItems().map((virtualRow) => {
          const review = reviews[virtualRow.index]
          return (
            <Row key={review.albumPublicId} style={{ transform: `translateY(${virtualRow.start}px)` }}>
              <TitleCell textRole="body-sm" text={review.title} />
              <ReasonCell>{MATCH_REVIEW_REASON_LABELS[review.reason]}</ReasonCell>
              <ScoreCell>{review.bestScore ?? '—'}</ScoreCell>
              <CheckedCell>{new Date(review.checkedAt).toLocaleString()}</CheckedCell>
              <Button
                size="sm"
                variant="outline"
                aria-label={`Match ${review.title}`}
                onClick={() => onMatchAlbum(review)}
              >
                Match
              </Button>
            </Row>
          )
        })}
      </RowCanvas>
    </TableScroll>
  )
}
