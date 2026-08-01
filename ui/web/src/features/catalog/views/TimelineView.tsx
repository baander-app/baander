import styled from 'styled-components'
import { useTimelineViewModel } from '../hooks/use-timeline-view-model'
import { TimelineDecade } from '../components/TimelineDecade'
import { Button } from '@/shared/components/ui/button'
import { Skeleton } from '@/shared/components/ui/skeleton'

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 2rem;
`

const CenterMessage = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  padding: 5rem 0;
`

const ErrorText = styled.p`
  font-size: 0.875rem;
  color: var(--color-destructive);
`

const EmptyText = styled.p`
  padding: 1rem 0;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const SkeletonDecade = styled.div`
  /* wrapper for each decade skeleton */
`

const SkeletonYearRow = styled.div`
  display: flex;
  align-items: start;
  gap: 1rem;
`

const SkeletonYearCovers = styled.div`
  display: flex;
  gap: 0.5rem;
`

const SkeletonYearRows = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1rem;
`

export function TimelineView() {
  const { decades, isLoading, error, refetch } = useTimelineViewModel()

  if (isLoading) {
    return <TimelineSkeleton />
  }

  if (error) {
    return (
      <CenterMessage>
        <ErrorText>Failed to load albums</ErrorText>
        <Button variant="ghost" size="sm" onClick={() => refetch()}>
          Retry
        </Button>
      </CenterMessage>
    )
  }

  if (!decades.length) {
    return (
      <EmptyText>No albums with year information</EmptyText>
    )
  }

  return (
    <Container role="list" aria-label="Albums by year">
      {decades.map((decade) => (
        <div key={decade.label} role="listitem">
          <TimelineDecade decade={decade} />
        </div>
      ))}
    </Container>
  )
}

function TimelineSkeleton() {
  return (
    <Container>
      {Array.from({ length: 3 }).map((_, i) => (
        <SkeletonDecade key={i}>
          <Skeleton style={{ marginBottom: '0.5rem', height: '1rem', width: '4rem' }} />
          <SkeletonYearRows>
            {Array.from({ length: 2 }).map((__, j) => (
              <SkeletonYearRow key={j}>
                <Skeleton style={{ height: '1.25rem', width: '3rem', flexShrink: 0 }} />
                <SkeletonYearCovers>
                  {Array.from({ length: 6 }).map((___, k) => (
                    <Skeleton key={k} style={{ height: '4rem', width: '4rem', flexShrink: 0, borderRadius: '0.25rem' }} />
                  ))}
                </SkeletonYearCovers>
              </SkeletonYearRow>
            ))}
          </SkeletonYearRows>
        </SkeletonDecade>
      ))}
    </Container>
  )
}
