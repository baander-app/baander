import { Component, type ReactNode, type ErrorInfo } from 'react'
import styled from 'styled-components'
import { AlertTriangle } from 'lucide-react'
import { Button } from '@/shared/components/ui/button'

interface ErrorBoundaryState {
  hasError: boolean
  error: Error | null
}

const ErrorContainer = styled.div`
  display: flex;
  min-height: 16rem;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  padding: 2rem;
  text-align: center;
`

const IconCircle = styled.div`
  display: flex;
  height: 2.5rem;
  width: 2.5rem;
  align-items: center;
  justify-content: center;
  border-radius: 9999px;
  background-color: color-mix(in srgb, var(--color-destructive) 10%, transparent);
  color: var(--color-destructive);
`

const ErrorTitle = styled.p`
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--color-foreground);
`

const ErrorMessage = styled.p`
  margin-top: 0.25rem;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

export class ErrorBoundary extends Component<{ children: ReactNode }, ErrorBoundaryState> {
  constructor(props: { children: ReactNode }) {
    super(props)
    this.state = { hasError: false, error: null }
  }

  static getDerivedStateFromError(error: Error): ErrorBoundaryState {
    return { hasError: true, error }
  }

  componentDidCatch(error: Error, errorInfo: ErrorInfo) {
    console.error('[ErrorBoundary] Uncaught error:', error, errorInfo)
  }

  render() {
    if (this.state.hasError) {
      return (
        <ErrorContainer>
          <IconCircle>
            <AlertTriangle size={20} />
          </IconCircle>
          <div>
            <ErrorTitle>Something went wrong</ErrorTitle>
            <ErrorMessage>
              {this.state.error?.message || 'An unexpected error occurred.'}
            </ErrorMessage>
          </div>
          <Button size="sm" onClick={() => window.location.reload()}>
            Reload page
          </Button>
        </ErrorContainer>
      )
    }

    return this.props.children
  }
}
