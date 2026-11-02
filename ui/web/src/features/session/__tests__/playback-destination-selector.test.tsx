import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { PlaybackDestinationSelector } from '../components/PlaybackDestinationSelector'
import { PLAYBACK_CONNECTION_KEY, PLAYBACK_SESSION_KEY } from '../services/playback-session-coordinator'

vi.mock('@/shared/live/transport', () => ({ authenticatedTransport: {} }))
function renderSelector(uncertain = false, idle = false, error: string | null = null, compact = false, transferring = false) {
  const client = new QueryClient()
  client.setQueryData(PLAYBACK_CONNECTION_KEY, { connected: error === null, endpoint: { destinationId: 'local', incarnationId: 'local-tab', epoch: 1 }, error })
  client.setQueryData(PLAYBACK_SESSION_KEY, {
    media: idle ? null : { kind: 'song', publicId: 'song' }, activeDestinationId: 'local', activeIncarnationId: 'local-tab', observedState: 'paused',
    destinations: [{ destinationId: 'local', incarnationId: 'local-tab', name: 'Browser', available: true, capabilities: ['song', 'video'] },
      ...(idle ? [{ destinationId: 'remote', incarnationId: 'remote-tab', name: 'Living room', available: true, capabilities: ['song', 'video'] }] : [])],
    transfer: uncertain ? { state: 'uncertain' } : transferring ? { state: 'starting' } : null,
  })
  return render(<QueryClientProvider client={client}><PlaybackDestinationSelector compact={compact} /></QueryClientProvider>)
}

function renderRecoverySelector(active: 'unavailable' | 'available' | 'none', uncertain = false) {
  const client = new QueryClient()
  client.setQueryData(PLAYBACK_CONNECTION_KEY, { connected: true, error: null,
    endpoint: { destinationId: 'local', incarnationId: 'local-tab', epoch: 1 },
    localRecovery: { status: 'recovered', message: 'Previous local playback stopped. Resume when ready.' } })
  client.setQueryData(PLAYBACK_SESSION_KEY, {
    media: { kind: 'song', publicId: 'retained-song' }, queue: ['retained-song'], position: 77,
    activeIncarnationId: active === 'none' ? null : 'legacy-tab', observedState: 'paused',
    destinations: [
      { destinationId: 'local', incarnationId: 'local-tab', name: 'Local browser', available: true, capabilities: ['song'] },
      { destinationId: 'legacy', incarnationId: 'legacy-tab', name: 'Chrome on Linux', available: active === 'available', capabilities: ['song'] },
      { destinationId: 'historic', incarnationId: 'historic-tab', name: 'Old browser', available: false, capabilities: ['song'] },
    ],
    transfer: uncertain ? { state: 'uncertain' } : null,
  })
  return render(<QueryClientProvider client={client}><PlaybackDestinationSelector /></QueryClientProvider>)
}

describe('playback destinations', () => {
  it('lists available targets and identifies unavailable active playback separately', async () => {
    renderRecoverySelector('unavailable')
    const button = screen.getByRole('button', { name: 'Playback destination' })
    expect(button).toHaveTextContent('This browser')
    fireEvent.keyDown(button, { key: 'ArrowDown' })
    expect(await screen.findByRole('menuitem', { name: 'This browser' })).toHaveAttribute('data-disabled')
    expect(screen.queryByRole('menuitem', { name: /Chrome on Linux|Old browser/ })).not.toBeInTheDocument()
    expect(screen.getByRole('status')).toHaveTextContent('Chrome on Linux')
    expect(screen.getByRole('status')).toHaveTextContent('may still be playing')
    expect(screen.queryByText('Previous local playback stopped. Resume when ready.')).not.toBeInTheDocument()
  })

  it('does not offer resume after unrelated recovery while a transfer remains uncertain', async () => {
    renderRecoverySelector('available', true)
    fireEvent.keyDown(screen.getByRole('button', { name: 'Playback destination' }), { key: 'ArrowDown' })
    expect(await screen.findByRole('menuitem', { name: 'Check destination stopped' })).toBeInTheDocument()
    expect(screen.queryByText('Previous local playback stopped. Resume when ready.')).not.toBeInTheDocument()
  })

  it('offers resume when recovery has left no active runner or transfer', async () => {
    renderRecoverySelector('none')
    fireEvent.keyDown(screen.getByRole('button', { name: 'Playback destination' }), { key: 'ArrowDown' })
    expect(await screen.findByText('Previous local playback stopped. Resume when ready.')).toBeInTheDocument()
    expect(screen.getByRole('menuitem', { name: 'This browser' })).not.toHaveAttribute('data-disabled')
  })

  it('keeps the destination control available with only the local browser', () => {
    renderSelector()
    expect(screen.getByRole('button', { name: 'Playback destination' })).toBeInTheDocument()
  })

  it('labels the local active destination and exposes keyboard menu semantics', async () => {
    renderSelector()
    const button = screen.getByRole('button', { name: 'Playback destination' })
    fireEvent.keyDown(button, { key: 'ArrowDown' })
    expect(await screen.findByRole('menuitem', { name: 'This browser — Active' })).toHaveAttribute('data-disabled')
  })

  it('allows choosing an available destination before loading media', async () => {
    renderSelector(false, true)
    fireEvent.keyDown(screen.getByRole('button', { name: 'Playback destination' }), { key: 'ArrowDown' })
    expect(await screen.findByRole('menuitem', { name: 'Living room' })).not.toHaveAttribute('data-disabled')
  })

  it('exposes a user retry after playback registration fails', async () => {
    renderSelector(false, false, 'Temporary registration failure')
    fireEvent.keyDown(screen.getByRole('button', { name: 'Playback destination' }), { key: 'ArrowDown' })
    expect(await screen.findByRole('menuitem', { name: 'Retry playback connection' })).not.toHaveAttribute('data-disabled')
  })

  it('announces transfer progress in the compact control', () => {
    renderSelector(false, false, null, true, true)
    const button = screen.getByRole('button', { name: 'Playback destination' })
    expect(button).toHaveAttribute('title', 'Transferring playback…')
    expect(button).toHaveAccessibleDescription('Transferring playback…')
    expect(screen.getByRole('status')).toHaveTextContent('Transferring playback…')
  })

  it('announces uncertainty in the compact control', () => {
    renderSelector(true, false, null, true)
    const button = screen.getByRole('button', { name: 'Playback destination' })
    expect(button).toHaveAttribute('title', 'Playback at the destination is uncertain.')
    expect(button).toHaveAccessibleDescription('Playback at the destination is uncertain.')
    expect(screen.getByRole('status')).toHaveTextContent('uncertain')
  })

  it('keeps uncertain playback distinct from a successful destination switch', async () => {
    renderSelector(true)
    fireEvent.keyDown(screen.getByRole('button', { name: 'Playback destination' }), { key: 'ArrowDown' })
    expect(await screen.findByRole('status')).toHaveTextContent('has not confirmed it stopped')
    expect(screen.getByRole('menuitem', { name: 'Check destination stopped' })).toBeInTheDocument()
  })
})
