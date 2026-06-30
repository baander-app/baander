import styled from 'styled-components'
import { useState } from 'react'
import { useRateLimiters, useClearRateLimiters } from '../hooks/use-rate-limiter'
import type { RateLimiterConfig } from '../api/rate-limiter-api'

const policyLabels: Record<string, string> = {
  sliding_window: 'Sliding Window',
  fixed_window: 'Fixed Window',
}

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  padding: 1.5rem;
`

const HeaderRow = styled.div`
  display: flex;
  justify-content: flex-end;
`

const DangerButton = styled.button`
  border-radius: var(--radius-md);
  background: var(--color-destructive);
  padding: 0.375rem 0.75rem;
  font-size: 0.875rem;
  color: var(--color-destructive-foreground);
`

const ConfirmBox = styled.div`
  border-radius: var(--radius-md);
  border: 1px solid color-mix(in srgb, var(--color-destructive) 50%, transparent);
  background: color-mix(in srgb, var(--color-destructive) 5%, transparent);
  padding: 1rem;
`

const ConfirmText = styled.p`
  font-size: 0.875rem;
`

const ButtonRow = styled.div`
  margin-top: 0.75rem;
  display: flex;
  gap: 0.5rem;
`

const ConfirmDanger = styled.button`
  border-radius: var(--radius-md);
  background: var(--color-destructive);
  padding: 0.375rem 0.75rem;
  font-size: 0.875rem;
  color: var(--color-destructive-foreground);

  &:disabled {
    opacity: 0.5;
  }
`

const CancelButton = styled.button`
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  padding: 0.375rem 0.75rem;
  font-size: 0.875rem;

  &:disabled {
    opacity: 0.5;
  }
`

const ErrorText = styled.p`
  margin-top: 0.5rem;
  font-size: 0.875rem;
  color: var(--color-destructive);
`

const TableWrapper = styled.div`
  overflow-x: auto;
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
`

const StyledTable = styled.table`
  width: 100%;
  font-size: 0.875rem;
`

const HeadRow = styled.tr`
  border-bottom: 1px solid var(--color-border);
  background: color-mix(in srgb, var(--color-muted) 50%, transparent);
`

const Th = styled.th`
  padding: 0.5rem 1rem;
  text-align: left;
  font-weight: 500;

  &[align="right"] {
    text-align: right;
  }
`

const BodyRow = styled.tr`
  border-bottom: 1px solid var(--color-border);

  &:last-child {
    border-bottom: none;
  }
`

const Td = styled.td`
  padding: 0.5rem 1rem;
`

const TdMono = styled.td`
  padding: 0.5rem 1rem;
  font-family: var(--font-mono);
  font-size: 0.75rem;
`

const TdRight = styled.td`
  padding: 0.5rem 1rem;
  text-align: right;
  font-variant-numeric: tabular-nums;
`

const TdMuted = styled.td`
  padding: 0.5rem 1rem;
  color: var(--color-muted-foreground);
`

const PolicyBadge = styled.span`
  border-radius: var(--radius-md);
  background: var(--color-muted);
  padding: 0.125rem 0.375rem;
  font-size: 0.75rem;
`

const SkeletonRow = styled.div`
  display: flex;
  gap: 1rem;
  padding: 0.5rem 1rem;
`

const SkeletonBar = styled.div`
  height: 1rem;
  border-radius: var(--radius-md);
  background: var(--color-muted);
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;

  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
  }
`

const PageError = styled.p`
  margin-top: 0.5rem;
  font-size: 0.875rem;
  color: var(--color-destructive);
`

const RetryLink = styled.button`
  color: var(--color-primary);

  &:hover {
    text-decoration: underline;
  }
`

export function RateLimitersPage() {
  const { data, isLoading, error, refetch } = useRateLimiters()
  const clearMutation = useClearRateLimiters()
  const [showClearConfirm, setShowClearConfirm] = useState(false)

  if (isLoading) return <RateLimitersSkeleton />

  if (error) {
    return (
      <Container>
        <PageError>
          Error loading rate limiters.{' '}
          <RetryLink onClick={() => refetch()}>Retry</RetryLink>
        </PageError>
      </Container>
    )
  }

  if (!data) return null

  const limiters = Object.entries(data.limiters) as [string, RateLimiterConfig][]

  return (
    <Container>
      <HeaderRow>
        <DangerButton onClick={() => setShowClearConfirm(true)}>
          Clear All
        </DangerButton>
      </HeaderRow>

      {/* Clear confirmation */}
      {showClearConfirm && (
        <ConfirmBox>
          <ConfirmText>
            All rate limiter state will be cleared from Redis. This will reset limits for
            every limiter and unblock all rate-limited clients.
          </ConfirmText>
          <ButtonRow>
            <ConfirmDanger
              onClick={() => {
                clearMutation.mutate('all', {
                  onSuccess: () => setShowClearConfirm(false),
                })
              }}
              disabled={clearMutation.isPending}
            >
              {clearMutation.isPending ? 'Clearing...' : 'Confirm Clear'}
            </ConfirmDanger>
            <CancelButton
              onClick={() => setShowClearConfirm(false)}
              disabled={clearMutation.isPending}
            >
              Cancel
            </CancelButton>
          </ButtonRow>
          {clearMutation.isError && (
            <ErrorText>Failed to clear rate limiters.</ErrorText>
          )}
        </ConfirmBox>
      )}

      {/* Table */}
      <TableWrapper>
        <StyledTable>
          <thead>
            <HeadRow>
              <Th>Name</Th>
              <Th>Policy</Th>
              <Th style={{ textAlign: 'right' }}>Limit</Th>
              <Th>Interval</Th>
              <Th>Description</Th>
            </HeadRow>
          </thead>
          <tbody>
            {limiters.map(([name, config]) => (
              <BodyRow key={name}>
                <TdMono>{name}</TdMono>
                <Td>
                  <PolicyBadge>
                    {policyLabels[config.policy] ?? config.policy}
                  </PolicyBadge>
                </Td>
                <TdRight>{config.limit}</TdRight>
                <Td>{config.interval}</Td>
                <TdMuted>{config.description}</TdMuted>
              </BodyRow>
            ))}
          </tbody>
        </StyledTable>
      </TableWrapper>
    </Container>
  )
}

function RateLimitersSkeleton() {
  return (
    <Container>
      <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
        {Array.from({ length: 5 }).map((_, i) => (
          <SkeletonRow key={i}>
            <SkeletonBar style={{ width: '8rem' }} />
            <SkeletonBar style={{ width: '6rem' }} />
            <SkeletonBar style={{ width: '3rem' }} />
            <SkeletonBar style={{ width: '6rem' }} />
            <SkeletonBar style={{ width: '10rem' }} />
          </SkeletonRow>
        ))}
      </div>
    </Container>
  )
}
