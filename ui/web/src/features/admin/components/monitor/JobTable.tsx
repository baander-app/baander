import { useState } from 'react'
import styled, { keyframes } from 'styled-components'
import { useJobList } from '../../hooks/use-job-monitor'
import { JobFilters } from './JobFilters'
import { JobTableRow } from './JobTableRow'
import type { JobFilters as JobFilterType } from '../../api/job-monitor-api'
import { EmptyState } from '@/features/catalog/components/EmptyState'
import { interactiveTransition } from '@/shared/theme'

const pulse = keyframes`
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
`

const Wrapper = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
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

const Count = styled.span`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const TableCard = styled.div`
  border-radius: 0.375rem;
  border: 1px solid var(--color-border);
  background-color: var(--color-card);
`

const TableHeader = styled.div`
  display: grid;
  grid-template-columns: 1fr 100px 100px 60px 120px 100px;
  gap: 0.5rem;
  border-bottom: 1px solid var(--color-border);
  padding: 0.5rem 1rem;
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--color-muted-foreground);
`

const CenterSpan = styled.span`
  text-align: center;
`

const Divider = styled.div`
  & > div { border-bottom: 1px solid var(--color-border); }
  & > div:last-child { border-bottom: none; }
`

const ErrorBody = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  padding: 3rem 0;
`

const ErrorText = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const RetryBtn = styled.button`
  border-radius: 0.375rem;
  border: 1px solid var(--color-border);
  padding: 0.375rem 0.75rem;
  font-size: 0.875rem;
  ${interactiveTransition(['background-color'])}

  &:hover { background-color: var(--color-accent); }
`

const PaginationArea = styled.div`
  border-top: 1px solid var(--color-border);
  padding: 0.5rem 1rem;
`

const LoadMoreBtn = styled.button`
  width: 100%;
  border-radius: 0.375rem;
  border: 1px solid var(--color-border);
  padding: 0.375rem 0.75rem;
  font-size: 0.875rem;
  ${interactiveTransition(['background-color'])}

  &:hover { background-color: var(--color-accent); }
  &:disabled { opacity: 0.5; }
`

const SkeletonLine = styled.div`
  border-radius: 0.25rem;
  background-color: var(--color-muted);
  animation: ${pulse} 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
`

const SkeletonRow = styled.div`
  display: grid;
  grid-template-columns: 1fr 100px 100px 60px 120px 100px;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 1rem;
  border-bottom: 1px solid var(--color-border);

  &:last-child { border-bottom: none; }
`

interface JobTableProps {
  onJobSelect: (jobId: string) => void
}

export function JobTable({ onJobSelect }: JobTableProps) {
  const [filters, setFilters] = useState<JobFilterType>({})
  const [cursor, setCursor] = useState<string | undefined>()

  const { data, isLoading, isError, refetch, isFetching } = useJobList({
    ...filters,
    cursor,
  })

  const handleFiltersChange = (newFilters: JobFilterType) => {
    setFilters(newFilters)
    setCursor(undefined)
  }

  const handleLoadMore = () => {
    if (data?.next_cursor) {
      setCursor(data.next_cursor)
    }
  }

  return (
    <Wrapper>
      <Header>
        <Title>Jobs</Title>
        <Count>{data?.items.length ?? 0} jobs</Count>
      </Header>

      <JobFilters filters={filters} onFiltersChange={handleFiltersChange} />

      <TableCard>
        <TableHeader>
          <span>Name</span>
          <CenterSpan>Status</CenterSpan>
          <span>Queue</span>
          <CenterSpan>#</CenterSpan>
          <span>Progress</span>
          <span>Duration</span>
        </TableHeader>

        <Divider>
          {isLoading && !data && <JobTableSkeleton />}

          {isError && (
            <ErrorBody>
              <ErrorText>Failed to load jobs</ErrorText>
              <RetryBtn onClick={() => refetch()}>Retry</RetryBtn>
            </ErrorBody>
          )}

          {!isLoading && !isError && data?.items.length === 0 && (
            <EmptyState message="No jobs found" />
          )}

          {data?.items.map((job) => (
            <JobTableRow key={job.jobId} job={job} onClick={() => onJobSelect(job.jobId)} />
          ))}
        </Divider>

        {data?.has_next_page && (
          <PaginationArea>
            <LoadMoreBtn onClick={handleLoadMore} disabled={isFetching}>
              {isFetching ? 'Loading...' : 'Load more'}
            </LoadMoreBtn>
          </PaginationArea>
        )}
      </TableCard>
    </Wrapper>
  )
}

function JobTableSkeleton() {
  return (
    <>
      {Array.from({ length: 8 }).map((_, i) => (
        <SkeletonRow key={i}>
          <SkeletonLine style={{ height: '1rem', width: '66%' }} />
          <SkeletonLine style={{ height: '1.25rem', width: '4rem', margin: '0 auto', borderRadius: '50px' }} />
          <SkeletonLine style={{ height: '1rem', width: '75%' }} />
          <SkeletonLine style={{ height: '1rem', width: '1.5rem', margin: '0 auto' }} />
          <SkeletonLine style={{ height: '1rem', width: '100%' }} />
          <SkeletonLine style={{ height: '1rem', width: '3rem' }} />
        </SkeletonRow>
      ))}
    </>
  )
}
