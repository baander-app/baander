import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { useDiscoverViewModel } from '../hooks/use-discover-view-model'
import { RecommendationClusterRow } from '../components/RecommendationCluster'

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 2rem;
`

const TopRow = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
`

const Title = styled.h2`
  font-size: 1.125rem;
  font-weight: 600;
  letter-spacing: -0.025em;
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

const SkeletonClusterRow = styled.div`
  display: flex;
  gap: 1rem;
`

const SkeletonItem = styled.div`
  flex-shrink: 0;
  width: 120px;
`

const Section = styled.section`
  /* wrapper for each cluster skeleton */
`

export function DiscoverView() {
  const { clusters, isLoading, error, refresh } = useDiscoverViewModel()

  if (error) {
    return (
      <CenterMessage>
        <ErrorText>Failed to load recommendations</ErrorText>
        <Button variant="ghost" size="sm" onClick={refresh}>
          Retry
        </Button>
      </CenterMessage>
    )
  }

  if (isLoading) {
    return <DiscoverSkeleton />
  }

  if (!clusters.length) {
    return (
      <EmptyText>
        No recommendations yet. Listen to more music to get personalized suggestions.
      </EmptyText>
    )
  }

  return (
    <Container>
      <TopRow>
        <Title>Discover</Title>
        <Button variant="ghost" size="sm" onClick={refresh}>
          Refresh
        </Button>
      </TopRow>
      {clusters.map((cluster) => (
        <RecommendationClusterRow key={`${cluster.sourceType}:${cluster.sourceId}`} cluster={cluster} />
      ))}
    </Container>
  )
}

function DiscoverSkeleton() {
  return (
    <Container>
      <TopRow>
        <Skeleton style={{ height: '1.5rem', width: '6rem' }} />
        <Skeleton style={{ height: '2rem', width: '4rem' }} />
      </TopRow>
      {Array.from({ length: 3 }).map((_, i) => (
        <Section key={i}>
          <Skeleton style={{ marginBottom: '0.75rem', height: '1rem', width: '12rem' }} />
          <SkeletonClusterRow>
            {Array.from({ length: 5 }).map((_, j) => (
              <SkeletonItem key={j}>
                <Skeleton style={{ aspectRatio: '1', width: '120px', borderRadius: '0.375rem' }} />
                <Skeleton style={{ marginTop: '0.5rem', height: '0.75rem', width: '5rem' }} />
                <Skeleton style={{ marginTop: '0.25rem', height: '0.5rem', width: '3rem' }} />
              </SkeletonItem>
            ))}
          </SkeletonClusterRow>
        </Section>
      ))}
    </Container>
  )
}
