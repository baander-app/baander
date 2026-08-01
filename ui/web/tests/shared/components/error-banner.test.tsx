import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ErrorBanner } from '@/shared/components/error-banner'

describe('ErrorBanner', () => {
  it('renders default error message', () => {
    render(<ErrorBanner />)
    expect(screen.getByText('Something went wrong')).toBeInTheDocument()
  })

  it('renders custom error message', () => {
    render(<ErrorBanner message="Network error" />)
    expect(screen.getByText('Network error')).toBeInTheDocument()
  })

  it('renders retry button when onRetry is provided', () => {
    render(<ErrorBanner onRetry={vi.fn()} />)
    expect(screen.getByText('Retry')).toBeInTheDocument()
  })

  it('does not render retry button when onRetry is not provided', () => {
    render(<ErrorBanner />)
    expect(screen.queryByText('Retry')).not.toBeInTheDocument()
  })

  it('calls onRetry when retry button is clicked', async () => {
    const user = userEvent.setup()
    const onRetry = vi.fn()
    render(<ErrorBanner onRetry={onRetry} />)

    await user.click(screen.getByText('Retry'))
    expect(onRetry).toHaveBeenCalledOnce()
  })

  it('has alert role for accessibility', () => {
    render(<ErrorBanner message="Test error" />)
    expect(screen.getByRole('alert')).toBeInTheDocument()
  })
})
