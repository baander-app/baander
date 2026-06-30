import { useState, useEffect, useCallback } from 'react'
import styled from 'styled-components'
import { getStations, getStarredStations, unstarStation, type RadioStation, type StarredStation } from '@/features/radio/api/radio-api'
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
  const [_starredIds, setStarred] = useState<StarredStation[]>([])
  const [stations, setStations] = useState<RadioStation[]>([])
  const [loading, setLoading] = useState(true)

  const loadData = useCallback(async () => {
    try {
      const starredData = await getStarredStations()
      setStarred(starredData)

      if (starredData.length > 0) {
        const allStations = await getStations()
        const starredIds = new Set(starredData.map((s) => s.stationId))
        setStations(allStations.filter((s) => starredIds.has(s.id)))
      } else {
        setStations([])
      }
    } catch {
      // ignore
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    loadData()
  }, [loadData])

  const handleUnstar = async (stationId: string) => {
    try {
      await unstarStation(stationId)
      setStarred((prev) => prev.filter((s) => s.stationId !== stationId))
      setStations((prev) => prev.filter((s) => s.id !== stationId))
    } catch {
      // ignore
    }
  }

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
          key={station.id}
          station={station}
          isStarred={true}
          onStar={() => {}}
          onUnstar={handleUnstar}
        />
      ))}
    </Container>
  )
}
