import { useState, useCallback, useMemo } from 'react'
import styled, { keyframes } from 'styled-components'
import {
  useAnalyticsSummary,
  useAnalyticsTiming,
  useAnalyticsFailures,
} from '../../hooks/use-job-monitor'
import { TimeRangePicker } from './TimeRangePicker'
import { ThroughputChart } from './ThroughputChart'
import { SuccessFailureChart } from './SuccessFailureChart'
import { TimingChart } from './TimingChart'
import { FailureBreakdownChart } from './FailureBreakdownChart'
import { interactiveTransition } from '@/shared/theme'

const pulse = keyframes`
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
`

const Wrapper = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
`

const Header = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
`

const Title = styled.h2`
  font-size: 1.125rem;
  font-weight: 600;
`

const ChartGrid = styled.div`
  display: grid;
  grid-template-columns: repeat(1, 1fr);
  gap: 1rem;

  @media (min-width: 1024px) {
    grid-template-columns: repeat(2, 1fr);
  }
`

const SkeletonCard = styled.div`
  border-radius: 0.375rem;
  border: 1px solid var(--color-border);
  background-color: var(--color-card);
  padding: 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
`

const SkeletonLine = styled.div`
  border-radius: 0.25rem;
  background-color: var(--color-muted);
  animation: ${pulse} 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
`

const SkeletonRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.75rem;
`

const ErrorCard = styled.div`
  border-radius: 0.375rem;
  border: 1px solid color-mix(in srgb, var(--color-destructive) 50%, transparent);
  background-color: var(--color-card);
  padding: 1rem;
`

const ErrorText = styled.p`
  font-size: 0.875rem;
  color: var(--color-destructive);
`

const RetryLink = styled.button`
  margin-top: 0.5rem;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
  text-decoration: underline;
  ${interactiveTransition(['color'])}

  &:hover { color: var(--color-foreground); }
`

const EmptyCard = styled.div`
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 0.375rem;
  border: 1px solid var(--color-border);
  background-color: var(--color-card);
  padding: 2rem;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

function ChartSkeleton() {
  return (
    <SkeletonCard>
      <SkeletonLine style={{ height: '1rem', width: '8rem' }} />
      <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
        {Array.from({ length: 3 }).map((_, i) => (
          <SkeletonRow key={i}>
            <SkeletonLine style={{ height: '0.75rem', width: '8rem' }} />
            <SkeletonLine style={{ height: '1.25rem', flex: 1 }} />
          </SkeletonRow>
        ))}
      </div>
    </SkeletonCard>
  )
}

function ThroughputSkeleton() {
  return (
    <SkeletonCard>
      <SkeletonLine style={{ height: '1rem', width: '6rem' }} />
      <SkeletonLine style={{ marginTop: '0.5rem', height: '2rem', width: '8rem' }} />
    </SkeletonCard>
  )
}

function SectionError({ message, onRetry }: { message: string; onRetry: () => void }) {
  return (
    <ErrorCard>
      <ErrorText>{message}</ErrorText>
      <RetryLink type="button" onClick={onRetry}>Retry</RetryLink>
    </ErrorCard>
  )
}

function SectionEmpty({ message }: { message: string }) {
  return <EmptyCard>{message}</EmptyCard>
}

function getInitialRange() {
  return {
    from: new Date(Date.now() - 24 * 60 * 60 * 1000).toISOString().slice(0, 19) + 'Z',
    to: new Date().toISOString().slice(0, 19) + 'Z',
  }
}

export function AnalyticsSection() {
  const initial = useMemo(getInitialRange, [])
  const [from, setFrom] = useState(initial.from)
  const [to, setTo] = useState(initial.to)

  const handleRangeChange = useCallback((newFrom: string, newTo: string) => {
    setFrom(newFrom)
    setTo(newTo)
  }, [])

  const summaryQuery = useAnalyticsSummary(from, to)
  const timingQuery = useAnalyticsTiming(from, to)
  const failuresQuery = useAnalyticsFailures(from, to)

  const hasAnyData = useMemo(() => {
    const summary = summaryQuery.data
    if (!summary) return false
    const totalJobs = Object.values(summary.statusCounts).reduce((a, b) => a + b, 0)
    return totalJobs > 0 || summary.throughputPerHour > 0
  }, [summaryQuery.data])

  return (
    <Wrapper>
      <Header>
        <Title>Analytics</Title>
        <TimeRangePicker from={from} to={to} onChange={handleRangeChange} />
      </Header>

      {!summaryQuery.isLoading && !summaryQuery.isError && !hasAnyData && (
        <SectionEmpty message="No job data in the selected time range." />
      )}

      <ChartGrid>
        <div>
          {summaryQuery.isLoading ? (
            <ThroughputSkeleton />
          ) : summaryQuery.isError ? (
            <SectionError message="Failed to load summary data." onRetry={() => summaryQuery.refetch()} />
          ) : (
            <ThroughputChart throughput={summaryQuery.data!.throughputPerHour} />
          )}
        </div>
        <div>
          {summaryQuery.isLoading ? (
            <ChartSkeleton />
          ) : summaryQuery.isError ? (
            <SectionError message="Failed to load status distribution." onRetry={() => summaryQuery.refetch()} />
          ) : (
            <SuccessFailureChart statusCounts={summaryQuery.data!.statusCounts} />
          )}
        </div>
      </ChartGrid>

      <div>
        {timingQuery.isLoading ? (
          <ChartSkeleton />
        ) : timingQuery.isError ? (
          <SectionError message="Failed to load timing data." onRetry={() => timingQuery.refetch()} />
        ) : (
          <TimingChart executionTimes={timingQuery.data!.executionTimes} />
        )}
      </div>

      <div>
        {failuresQuery.isLoading ? (
          <ChartSkeleton />
        ) : failuresQuery.isError ? (
          <SectionError message="Failed to load failure data." onRetry={() => failuresQuery.refetch()} />
        ) : (
          <FailureBreakdownChart topExceptionClasses={failuresQuery.data!.topExceptionClasses} />
        )}
      </div>
    </Wrapper>
  )
}
