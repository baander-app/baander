import { useStarredStations, useStations, useUnstarStation } from '../hooks/use-radio-stations'
import styled from 'styled-components'
import { StationCard } from './StationCard'
import { Skeleton } from '@/shared/components/ui/skeleton'

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
`

const SkeletonContainer = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
`

const EmptyMessage = styled.p`
  padding: 2rem 0;
  text-align: center;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

export function StarredStations() {
  const starredQuery = useStarredStations()
  const starred = starredQuery.data ?? []
  const stationsQuery = useStations('', '', starred.length > 0)
  const starredIds = new Set(starred.map((item) => item.stationId))
  const stations = (stationsQuery.data ?? []).filter(
    (station) => starredIds.has(station.publicId),
  )
  const loading = starredQuery.isPending || (
    starred.length > 0 && stationsQuery.isFetching && stationsQuery.isPlaceholderData
  )
  const unstar = useUnstarStation()

  if (loading) {
    return (
      <SkeletonContainer>
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} style={{ height: '3.5rem', borderRadius: 'var(--radius-md)' }} />
        ))}
      </SkeletonContainer>
    )
  }

  if (stations.length === 0) {
    return (
      <EmptyMessage>
        No starred stations yet. Star stations while browsing to see them here.
      </EmptyMessage>
    )
  }

  return (
    <Container>
      {stations.map((station) => (
        <StationCard
          key={station.publicId}
          station={station}
          isStarred={true}
          onStar={() => {}}
          onUnstar={(stationId) => unstar.mutate(stationId)}
        />
      ))}
    </Container>
  )
}
