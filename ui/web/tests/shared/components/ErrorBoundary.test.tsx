import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { ErrorBoundary } from '@/shared/components/ErrorBoundary'

function ThrowOnRender({ shouldThrow }: { shouldThrow: boolean }) {
  if (shouldThrow) {
    throw new Error('test error message')
  }
  return <div>Child content</div>
}

describe('ErrorBoundary', () => {
  it('renders children when no error', () => {
    render(
      <ErrorBoundary>
        <ThrowOnRender shouldThrow={false} />
      </ErrorBoundary>,
    )
    expect(screen.getByText('Child content')).toBeInTheDocument()
  })

  it('renders error UI when child throws', () => {
    const spy = vi.spyOn(console, 'error').mockImplementation(() => {})

    render(
      <ErrorBoundary>
        <ThrowOnRender shouldThrow={true} />
      </ErrorBoundary>,
    )

    expect(screen.getByText('Something went wrong')).toBeInTheDocument()
    expect(screen.getByText('test error message')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /reload/i })).toBeInTheDocument()

    spy.mockRestore()
  })

  it('shows generic message when error has no message', () => {
    const spy = vi.spyOn(console, 'error').mockImplementation(() => {})

    function ThrowNoMessage() {
      throw new Error()
    }

    render(
      <ErrorBoundary>
        <ThrowNoMessage />
      </ErrorBoundary>,
    )

    expect(screen.getByText('An unexpected error occurred.')).toBeInTheDocument()

    spy.mockRestore()
  })
})
