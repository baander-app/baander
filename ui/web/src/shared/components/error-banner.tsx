import styled from 'styled-components'
import { AlertTriangle, RotateCcw } from 'lucide-react'
import { Button } from '@/shared/components/ui/button'

interface ErrorBannerProps {
  message?: string
  onRetry?: () => void
  className?: string
}

const Banner = styled.div`
  display: flex;
  align-items: center;
  gap: 0.75rem;
  border-radius: var(--radius-lg);
  background-color: color-mix(in srgb, var(--color-destructive) 10%, transparent);
  padding: 0.75rem 1rem;
  font-size: 0.875rem;
  color: var(--color-destructive);
`

const ShrinkIcon = styled(AlertTriangle)`
  flex-shrink: 0;
`

const Message = styled.p`
  flex: 1;
`

export function ErrorBanner({ message = 'Something went wrong', onRetry, className }: ErrorBannerProps) {
  return (
    <Banner className={className} role="alert">
      <ShrinkIcon size={16} />
      <Message>{message}</Message>
      {onRetry && (
        <Button variant="ghost" size="xs" onClick={onRetry}>
          <RotateCcw size={12} />
          Retry
        </Button>
      )}
    </Banner>
  )
}
