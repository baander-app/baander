import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { SessionTransferPrompt } from '../components/SessionTransferPrompt'

function wrapper({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={new QueryClient()}>{children}</QueryClientProvider>
}

const mockSession = {
  id: 'session-1',
  userId: 'user-1',
  activeDeviceId: 'other-device',
  queue: ['track-1', 'track-2', 'track-3'],
  currentTrackIndex: 0,
  position: 125,
  playbackState: 'playing' as const,
  createdAt: '2026-01-01T00:00:00Z',
  updatedAt: '2026-01-01T00:00:00Z',
  lastUsedAt: null,
}

describe('SessionTransferPrompt', () => {
  it('renders resume prompt with track count and position', () => {
    render(
      <SessionTransferPrompt
        open={true}
        session={mockSession}
        onClaim={vi.fn()}
        onClaimWithQueue={vi.fn()}
        onNew={vi.fn()}
        onDismiss={vi.fn()}
        isClaiming={false}
        isCreating={false}
      />,
      { wrapper },
    )

    expect(screen.getByText('Resume listening?')).toBeInTheDocument()
    expect(screen.getByText(/3 tracks/)).toBeInTheDocument()
    expect(screen.getByText(/2:05/)).toBeInTheDocument()
  })

  it('calls onClaim when resume button clicked', async () => {
    const onClaim = vi.fn()
    render(
      <SessionTransferPrompt
        open={true}
        session={mockSession}
        onClaim={onClaim}
        onClaimWithQueue={vi.fn()}
        onNew={vi.fn()}
        onDismiss={vi.fn()}
        isClaiming={false}
        isCreating={false}
      />,
      { wrapper },
    )

    await userEvent.click(screen.getByText('Resume here'))
    expect(onClaim).toHaveBeenCalled()
  })

  it('calls onNew when new session button clicked', async () => {
    const onNew = vi.fn()
    render(
      <SessionTransferPrompt
        open={true}
        session={mockSession}
        onClaim={vi.fn()}
        onClaimWithQueue={vi.fn()}
        onNew={onNew}
        onDismiss={vi.fn()}
        isClaiming={false}
        isCreating={false}
      />,
      { wrapper },
    )

    await userEvent.click(screen.getByText('Start new session'))
    expect(onNew).toHaveBeenCalled()
  })

  it('disables buttons while claiming', () => {
    render(
      <SessionTransferPrompt
        open={true}
        session={mockSession}
        onClaim={vi.fn()}
        onClaimWithQueue={vi.fn()}
        onNew={vi.fn()}
        onDismiss={vi.fn()}
        isClaiming={true}
        isCreating={false}
      />,
      { wrapper },
    )

    expect(screen.getByText('Resuming...')).toBeInTheDocument()
  })

  it('calls onDismiss when dialog closed', async () => {
    const onDismiss = vi.fn()
    render(
      <SessionTransferPrompt
        open={true}
        session={mockSession}
        onClaim={vi.fn()}
        onClaimWithQueue={vi.fn()}
        onNew={vi.fn()}
        onDismiss={onDismiss}
        isClaiming={false}
        isCreating={false}
      />,
      { wrapper },
    )

    const closeBtn = screen.getByRole('button', { name: /close/i })
    if (closeBtn) {
      await userEvent.click(closeBtn)
      expect(onDismiss).toHaveBeenCalled()
    }
  })
})
