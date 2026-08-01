import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { PreferenceConflictDialog } from '../components/PreferenceConflictDialog'

describe('PreferenceConflictDialog', () => {
  it('renders conflict message with server version', () => {
    render(
      <PreferenceConflictDialog
        open={true}
        serverVersion={5}
        onResolve={vi.fn()}
      />,
    )

    expect(screen.getByText('Settings conflict')).toBeInTheDocument()
    expect(screen.getByText(/version 5/)).toBeInTheDocument()
  })

  it('renders without version when null', () => {
    render(
      <PreferenceConflictDialog
        open={true}
        serverVersion={null}
        onResolve={vi.fn()}
      />,
    )

    expect(screen.queryByText(/version \d+/)).not.toBeInTheDocument()
  })

  it('calls onResolve with "mine" when keep button clicked', async () => {
    const onResolve = vi.fn()
    render(
      <PreferenceConflictDialog
        open={true}
        serverVersion={3}
        onResolve={onResolve}
      />,
    )

    await userEvent.click(screen.getByText('Keep my changes'))
    expect(onResolve).toHaveBeenCalledWith('mine')
  })

  it('calls onResolve with "theirs" when server button clicked', async () => {
    const onResolve = vi.fn()
    render(
      <PreferenceConflictDialog
        open={true}
        serverVersion={3}
        onResolve={onResolve}
      />,
    )

    await userEvent.click(screen.getByText('Use server version'))
    expect(onResolve).toHaveBeenCalledWith('theirs')
  })

  it('resolves to "theirs" when dialog is closed', async () => {
    const onResolve = vi.fn()
    render(
      <PreferenceConflictDialog
        open={true}
        serverVersion={3}
        onResolve={onResolve}
      />,
    )

    // DialogContent's onOpenChange fires when close button or overlay is clicked
    // Simulate by finding the close button
    const closeButton = screen.getByRole('button', { name: /close/i })
    if (closeButton) {
      await userEvent.click(closeButton)
      expect(onResolve).toHaveBeenCalledWith('theirs')
    }
  })
})
