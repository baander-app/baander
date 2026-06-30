import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { useActivityViewModel } from '../hooks/use-activity-view-model'
import { ActivityGroup } from '../components/ActivityGroup'

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  overflow-y: auto;
  padding: 1rem 1.5rem;
`

const LoadingContainer = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1rem 1.5rem;
`

const LoadingGroup = styled.div`
  display: flex;
  flex-direction: column;
`

const LoadingHeader = styled.div`
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 1rem 0.5rem 0.5rem;
`

const Divider = styled.div`
  height: 1px;
  flex: 1;
  background-color: var(--color-border);
`

const LoadingRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.375rem 0.5rem;
`

const LoadingInfo = styled.div`
  min-width: 0;
  flex: 1;
`

const CenterMessage = styled.div`
  display: flex;
  flex: 1;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  padding: 0 1.5rem;
`

const ErrorText = styled.p`
  font-size: 0.875rem;
  color: var(--color-destructive);
`

const EmptyText = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const LoadMoreRow = styled.div`
  display: flex;
  justify-content: center;
  padding: 1rem 0;
`

export function ActivityView() {
  const { groups, isLoading, error, loadMore, hasMore, refetch } = useActivityViewModel()

  if (isLoading) {
    return (
      <LoadingContainer>
        {Array.from({ length: 4 }).map((_, gi) => (
          <LoadingGroup key={gi}>
            <LoadingHeader>
              <Skeleton style={{ height: '0.75rem', width: '5rem' }} />
              <Divider />
            </LoadingHeader>
            {Array.from({ length: 3 }).map((_, i) => (
              <LoadingRow key={i}>
                <Skeleton style={{ height: '2rem', width: '2rem', flexShrink: 0, borderRadius: '0.25rem' }} />
                <LoadingInfo>
                  <Skeleton style={{ marginBottom: '0.25rem', height: '1rem', width: '10rem' }} />
                  <Skeleton style={{ height: '0.75rem', width: '6rem' }} />
                </LoadingInfo>
                <Skeleton style={{ height: '0.75rem', width: '3rem' }} />
              </LoadingRow>
            ))}
          </LoadingGroup>
        ))}
      </LoadingContainer>
    )
  }

  if (error) {
    return (
      <CenterMessage>
        <ErrorText>Failed to load activity history</ErrorText>
        <Button variant="ghost" size="sm" onClick={() => refetch()}>
          Retry
        </Button>
      </CenterMessage>
    )
  }

  if (!groups.length) {
    return (
      <CenterMessage>
        <EmptyText>No listening activity yet</EmptyText>
      </CenterMessage>
    )
  }

  return (
    <Container>
      {groups.map((group) => (
        <ActivityGroup key={group.label} label={group.label} items={group.items} />
      ))}

      {hasMore && (
        <LoadMoreRow>
          <Button variant="ghost" size="sm" onClick={loadMore}>
            Load more
          </Button>
        </LoadMoreRow>
      )}
    </Container>
  )
}
