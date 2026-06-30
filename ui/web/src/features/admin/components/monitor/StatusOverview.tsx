import { useMemo } from 'react'
import styled, { keyframes } from 'styled-components'
import { useStatusOverview, useAnalyticsSummary } from '../../hooks/use-job-monitor'

const pulse = keyframes`
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
`

const Grid = styled.div`
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 1rem;

  @media (min-width: 768px) {
    grid-template-columns: repeat(4, 1fr);
  }

  @media (min-width: 1024px) {
    grid-template-columns: repeat(8, 1fr);
  }
`

const SkeletonCard = styled.div`
  border-radius: 0.375rem;
  border: 1px solid var(--color-border);
  background-color: var(--color-card);
  padding: 1rem;
`

const SkeletonLine1 = styled.div`
  height: 0.75rem;
  width: 4rem;
  border-radius: 0.25rem;
  background-color: var(--color-muted);
  animation: ${pulse} 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
`

const SkeletonLine2 = styled.div`
  margin-top: 0.5rem;
  height: 1.5rem;
  width: 3rem;
  border-radius: 0.25rem;
  background-color: var(--color-muted);
  animation: ${pulse} 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
`

const StatCard = styled.div`
  border-radius: 0.375rem;
  border: 1px solid var(--color-border);
  background-color: var(--color-card);
  padding: 1rem;
`

const StatLabel = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const StatValue = styled.p<{ $color?: string }>`
  margin-top: 0.25rem;
  font-size: 1.5rem;
  font-weight: 700;
  ${({ $color }) => $color ? `color: ${$color};` : ''}
`

const ErrorText = styled.p`
  font-size: 0.875rem;
  color: var(--color-destructive);
`

export function StatusOverviewSkeleton() {
  return (
    <Grid>
      {Array.from({ length: 8 }).map((_, i) => (
        <SkeletonCard key={i}>
          <SkeletonLine1 />
          <SkeletonLine2 />
        </SkeletonCard>
      ))}
    </Grid>
  )
}

export function StatusOverview() {
  const { from, to } = useMemo(() => {
    const now = new Date()
    return {
      from: new Date(now.getTime() - 24 * 60 * 60 * 1000).toISOString().slice(0, 19) + 'Z',
      to: now.toISOString().slice(0, 19) + 'Z',
    }
  }, [])

  const { data: overview, isLoading: overviewLoading, error: overviewError } = useStatusOverview()
  const { data: analytics } = useAnalyticsSummary(from, to)

  if (overviewLoading) {
    return <StatusOverviewSkeleton />
  }

  if (overviewError) {
    return <ErrorText>Failed to load status overview</ErrorText>
  }

  const counts = overview?.counts ?? {}
  const queued = counts['queued'] ?? 0
  const running = counts['running'] ?? 0
  const finished = counts['finished'] ?? 0
  const failed = counts['failed'] ?? 0
  const cancelled = counts['cancelled'] ?? 0
  const active = queued + running
  const successRate = analytics?.successRate ?? 0
  const throughput = analytics?.throughputPerHour ?? 0

  return (
    <Grid>
      <StatCard>
        <StatLabel>Queued</StatLabel>
        <StatValue $color="#3b82f5">{queued}</StatValue>
      </StatCard>
      <StatCard>
        <StatLabel>Running</StatLabel>
        <StatValue $color="#f59e0b">{running}</StatValue>
      </StatCard>
      <StatCard>
        <StatLabel>Finished</StatLabel>
        <StatValue $color="#22c55e">{finished}</StatValue>
      </StatCard>
      <StatCard>
        <StatLabel>Failed</StatLabel>
        <StatValue $color="#ef4444">{failed}</StatValue>
      </StatCard>
      <StatCard>
        <StatLabel>Cancelled</StatLabel>
        <StatValue $color="var(--color-muted-foreground)">{cancelled}</StatValue>
      </StatCard>
      <StatCard>
        <StatLabel>Active</StatLabel>
        <StatValue $color="#2563eb">{active}</StatValue>
      </StatCard>
      <StatCard>
        <StatLabel>Success rate</StatLabel>
        <StatValue>{`${Math.round(successRate * 100)}%`}</StatValue>
      </StatCard>
      <StatCard>
        <StatLabel>Throughput</StatLabel>
        <StatValue>{`${throughput.toFixed(1)}/hr`}</StatValue>
      </StatCard>
    </Grid>
  )
}
