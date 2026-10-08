import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import { SkeletonBar } from './diagnostics-styles'

const SkeletonBody = styled.div`
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
  padding: var(--space-md);
`

const ErrorBody = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-md);
  padding: 0.625rem var(--space-md);
`

const ErrorMessage = styled.p`
  font-size: 0.875rem;
  color: var(--color-destructive);
`

interface SectionSkeletonProps {
  rows?: number
}

export function SectionSkeleton({ rows = 3 }: SectionSkeletonProps) {
  return (
    <SkeletonBody aria-hidden="true">
      {Array.from({ length: rows }, (_, index) => (
        <SkeletonBar key={index} />
      ))}
    </SkeletonBody>
  )
}

interface SectionErrorProps {
  message: string
  onRetry: () => void
}

export function SectionError({ message, onRetry }: SectionErrorProps) {
  return (
    <ErrorBody>
      <ErrorMessage>{message}</ErrorMessage>
      <Button variant="ghost" size="sm" onClick={onRetry}>
        Retry
      </Button>
    </ErrorBody>
  )
}
