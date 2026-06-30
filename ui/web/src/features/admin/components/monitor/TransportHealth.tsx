import { useState } from 'react'
import styled, { keyframes } from 'styled-components'
import { useTransportStatus, useFlushFailedQueue } from '../../hooks/use-job-monitor'
import { interactiveTransition } from '@/shared/theme'

const pulse = keyframes`
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
`

const Wrapper = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1rem;
`

const Title = styled.h2`
  font-size: 1.125rem;
  font-weight: 600;
`

const CardsGrid = styled.div`
  display: grid;
  grid-template-columns: repeat(1, 1fr);
  gap: 1rem;

  @media (min-width: 640px) {
    grid-template-columns: repeat(3, 1fr);
  }
`

const Card = styled.div`
  border-radius: 0.375rem;
  border: 1px solid var(--color-border);
  background-color: var(--color-card);
  padding: 1rem;
`

const CardLabel = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const CardValue = styled.p<{ $color?: string }>`
  margin-top: 0.25rem;
  font-size: 1.5rem;
  font-weight: 700;
  ${({ $color }) => $color ? `color: ${$color};` : ''}
`

const ConsumerRow = styled.div`
  margin-top: 0.25rem;
  display: flex;
  align-items: center;
  gap: 0.5rem;
`

const ConsumerDot = styled.div<{ $running: boolean }>`
  height: 0.625rem;
  width: 0.625rem;
  border-radius: 50%;
  background-color: ${({ $running }) => $running ? '#22c55e' : 'var(--color-destructive)'};
`

const ConsumerName = styled.span`
  font-size: 0.875rem;
  font-weight: 500;
`

const FlushCard = styled.div`
  border-radius: 0.375rem;
  border: 1px solid var(--color-border);
  background-color: var(--color-card);
  padding: 1rem;
`

const FlushConfirmBody = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
`

const FlushText = styled.p`
  font-size: 0.875rem;
`

const FlushActions = styled.div`
  display: flex;
  gap: 0.5rem;
`

const DestructiveButton = styled.button`
  border-radius: 0.375rem;
  background-color: var(--color-destructive);
  padding: 0.375rem 0.75rem;
  font-size: 0.875rem;
  color: var(--color-destructive-foreground);

  &:disabled { opacity: 0.5; }
`

const OutlineButton = styled.button`
  border-radius: 0.375rem;
  border: 1px solid var(--color-border);
  padding: 0.375rem 0.75rem;
  font-size: 0.875rem;
`

const FlushRow = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
`

const FlushCount = styled.p`
  font-size: 0.875rem;
  font-weight: 500;
`

const FlushError = styled.p`
  font-size: 0.875rem;
  color: var(--color-destructive);
`

const NoFailedText = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const RetryButton = styled.button`
  font-size: 0.875rem;
`

const SkeletonLine = styled.div`
  border-radius: 0.25rem;
  background-color: var(--color-muted);
  animation: ${pulse} 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
`

function TransportHealthSkeleton() {
  return (
    <Wrapper>
      <SkeletonLine style={{ height: '1.5rem', width: '10rem' }} />
      <CardsGrid>
        {Array.from({ length: 3 }).map((_, i) => (
          <Card key={i}>
            <SkeletonLine style={{ height: '0.75rem', width: '5rem' }} />
            <SkeletonLine style={{ marginTop: '0.5rem', height: '1.5rem', width: '3rem' }} />
          </Card>
        ))}
      </CardsGrid>
    </Wrapper>
  )
}

export function TransportHealth() {
  const { data, isLoading, error, refetch } = useTransportStatus()
  const flushMutation = useFlushFailedQueue()
  const [showFlushConfirm, setShowFlushConfirm] = useState(false)

  if (isLoading) return <TransportHealthSkeleton />
  if (error)
    return (
      <div>
        Error loading transport status.{' '}
        <RetryButton onClick={() => refetch()}>Retry</RetryButton>
      </div>
    )

  if (!data) return null

  return (
    <Wrapper>
      <Title>Transport Health</Title>

      <CardsGrid>
        <Card>
          <CardLabel>Async Queue</CardLabel>
          <CardValue $color={data.asyncQueueDepth > 0 ? '#ca8a04' : undefined}>
            {data.asyncQueueDepth}
          </CardValue>
        </Card>

        <Card>
          <CardLabel>Failed Queue</CardLabel>
          <CardValue $color={data.failedQueueDepth > 0 ? 'var(--color-destructive)' : undefined}>
            {data.failedQueueDepth}
          </CardValue>
        </Card>

        <Card>
          <CardLabel>Consumer</CardLabel>
          <ConsumerRow>
            <ConsumerDot $running={data.consumerRunning} />
            <ConsumerName>{data.consumerName}</ConsumerName>
          </ConsumerRow>
        </Card>
      </CardsGrid>

      {data.failedQueueDepth > 0 ? (
        <FlushCard>
          {showFlushConfirm ? (
            <FlushConfirmBody>
              <FlushText>
                Are you sure you want to flush all {data.failedQueueDepth} failed
                message(s)? This action cannot be undone.
              </FlushText>
              <FlushActions>
                <DestructiveButton
                  onClick={() => {
                    flushMutation.mutate(undefined, {
                      onSuccess: () => { setShowFlushConfirm(false) },
                    })
                  }}
                  disabled={flushMutation.isPending}
                >
                  {flushMutation.isPending ? 'Flushing...' : 'Confirm Flush'}
                </DestructiveButton>
                <OutlineButton
                  onClick={() => setShowFlushConfirm(false)}
                  disabled={flushMutation.isPending}
                >
                  Cancel
                </OutlineButton>
              </FlushActions>
              {flushMutation.isError && (
                <FlushError>Failed to flush queue. Please try again.</FlushError>
              )}
            </FlushConfirmBody>
          ) : (
            <FlushRow>
              <FlushCount>{data.failedQueueDepth} failed message(s)</FlushCount>
              <DestructiveButton onClick={() => setShowFlushConfirm(true)}>
                Flush All
              </DestructiveButton>
            </FlushRow>
          )}
        </FlushCard>
      ) : (
        <NoFailedText>No failed messages</NoFailedText>
      )}
    </Wrapper>
  )
}
