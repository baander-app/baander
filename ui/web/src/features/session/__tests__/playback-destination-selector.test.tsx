import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { PlaybackDestinationSelector } from '../components/PlaybackDestinationSelector'
import { PLAYBACK_CONNECTION_KEY, PLAYBACK_SESSION_KEY, registerPlaybackCoordinator, type PlaybackConnection, type PlaybackSessionCoordinator } from '../services/playback-session-coordinator'

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
    expect(screen.getByRole('status')).toHaveTextContent('Chrome on Linux is unavailable and has not confirmed that it stopped.')
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

describe('Play here anyway', () => {
  const consequence = 'Play here anyway ends its hold; if it is still playing, it continues until it reconnects.'
  type Presence = 'unavailable' | 'missing' | 'available'
  interface Held {
    runner?: Presence | null
    transfer?: { state: 'preparing' | 'quiescing' | 'starting' | 'uncertain'; target: Presence } | null
    connection?: Partial<PlaybackConnection>
  }
  let unregister: (() => void) | undefined
  afterEach(() => { unregister?.(); unregister = undefined })

  /** The menu, opened, beside a session held as described; `override` stands for the coordinator's request. */
  async function openMenu({ runner = 'unavailable', transfer = null, connection = {} }: Held = {}, override = vi.fn(async () => {})) {
    unregister = registerPlaybackCoordinator({ override } as unknown as PlaybackSessionCoordinator)
    const client = new QueryClient()
    client.setQueryData(PLAYBACK_CONNECTION_KEY, { connected: true, error: null, endpoint: { destinationId: 'local', incarnationId: 'local-tab', epoch: 1 }, ...connection })
    const destination = (id: string, name: string, presence: Presence | null | undefined) => presence && presence !== 'missing'
      ? [{ destinationId: id, incarnationId: `${id}-tab`, name, available: presence === 'available', capabilities: ['song'] }] : []
    client.setQueryData(PLAYBACK_SESSION_KEY, {
      media: { kind: 'song', publicId: 'held-song' }, queue: ['held-song'], position: 77, observedState: 'unknown',
      activeIncarnationId: runner ? 'runner-tab' : null,
      destinations: [
        { destinationId: 'local', incarnationId: 'local-tab', name: 'Local browser', available: true, capabilities: ['song'] },
        ...destination('runner', 'Firefox on Linux', runner),
        ...destination('target', 'Living room', transfer?.target),
      ],
      transfer: transfer ? { state: transfer.state, sourceIncarnationId: runner ? 'runner-tab' : null, targetIncarnationId: 'target-tab' } : null,
    })
    render(<QueryClientProvider client={client}><PlaybackDestinationSelector /></QueryClientProvider>)
    fireEvent.keyDown(screen.getByRole('button', { name: 'Playback destination' }), { key: 'ArrowDown' })
    await screen.findByRole('menu')
    return { override, item: () => screen.queryByRole('menuitem', { name: 'Play here anyway' }) }
  }

  it.each([
    ['the runner is unavailable and no transfer is open', { runner: 'unavailable' }],
    ['the runner is missing from the destination list', { runner: 'missing' }],
    ['a transfer is uncertain and its target is unavailable', { runner: 'available', transfer: { state: 'uncertain', target: 'unavailable' } }],
    ['a transfer without a source is uncertain and its target is missing', { runner: null, transfer: { state: 'uncertain', target: 'missing' } }],
  ] as [string, Held][])('is offered while %s', async (_case, held) => {
    const { item } = await openMenu(held)
    expect(item()).toBeInTheDocument()
    expect(item()).not.toHaveAttribute('data-disabled')
  })

  it.each([
    ['the runner is available', { runner: 'available' }],
    ['nothing is held', { runner: null }],
    ['an uncertain transfer\'s target can still answer', { runner: 'available', transfer: { state: 'uncertain', target: 'available' } }],
    ['a transfer is preparing beside an unavailable runner', { runner: 'unavailable', transfer: { state: 'preparing', target: 'available' } }],
    ['a transfer is quiescing beside an unavailable runner', { runner: 'unavailable', transfer: { state: 'quiescing', target: 'unavailable' } }],
    ['a transfer is starting beside an unavailable runner', { runner: 'unavailable', transfer: { state: 'starting', target: 'unavailable' } }],
  ] as [string, Held][])('is not offered while %s', async (_case, held) => {
    const { item } = await openMenu(held)
    expect(item()).not.toBeInTheDocument()
  })

  it.each([
    ['disconnected', { connected: false }],
    ['recovering local playback', { localRecovery: { status: 'recovering', message: 'Checking previous local playback…' } }],
  ] as [string, Partial<PlaybackConnection>][])('is disabled while the tab is %s', async (_case, connection) => {
    const { item } = await openMenu({ connection })
    expect(item()).toHaveAttribute('data-disabled')
  })

  it('names the unavailable runner, the action and what happens to playback that is still going on', async () => {
    await openMenu({ runner: 'unavailable' })
    expect(screen.getByRole('status')).toHaveTextContent(`Firefox on Linux is unavailable and has not confirmed that it stopped. ${consequence}`)
  })

  it('speaks of the previous destination when the runner is no longer listed', async () => {
    await openMenu({ runner: 'missing' })
    expect(screen.getByRole('status')).toHaveTextContent(`The previous destination is unavailable and has not confirmed that it stopped. ${consequence}`)
  })

  it('says only that the runner has not confirmed its stop while a transfer keeps the action away', async () => {
    await openMenu({ runner: 'unavailable', transfer: { state: 'preparing', target: 'available' } })
    expect(screen.getByRole('status')).toHaveTextContent('Firefox on Linux is unavailable and has not confirmed that it stopped.')
    expect(screen.getByRole('status')).not.toHaveTextContent('Play here anyway')
  })

  it('adds the action and its consequence to the uncertain-transfer status when the target is unavailable, in one status line', async () => {
    const { item } = await openMenu({ runner: 'unavailable', transfer: { state: 'uncertain', target: 'unavailable' } })
    expect(screen.getAllByRole('status')).toHaveLength(1)
    expect(screen.getByRole('status')).toHaveTextContent(
      `The destination has not confirmed it stopped. Playback here remains paused until it is safe to resume. It is unavailable. ${consequence}`)
    expect(item()).toBeInTheDocument()
    expect(screen.getByRole('menuitem', { name: 'Check destination stopped' })).toBeInTheDocument()
  })

  it('leaves the uncertain-transfer status as it was when the target can still answer', async () => {
    await openMenu({ runner: 'available', transfer: { state: 'uncertain', target: 'available' } })
    expect(screen.getByRole('status').textContent).toBe('The destination has not confirmed it stopped. Playback here remains paused until it is safe to resume.')
    expect(screen.getByRole('menuitem', { name: 'Check destination stopped' })).toBeInTheDocument()
  })

  it('is a menu item the keyboard reaches, asks once, stays disabled while it asks and closes the menu once accepted', async () => {
    let accept!: () => void
    const { override, item } = await openMenu({}, vi.fn(() => new Promise<void>(resolve => { accept = resolve })))
    const action = item()!
    act(() => { action.focus() })
    expect(action).toHaveFocus()

    fireEvent.keyDown(action, { key: 'Enter' })

    await waitFor(() => expect(item()).toHaveAttribute('data-disabled'))
    expect(override).toHaveBeenCalledOnce()
    expect(screen.getByRole('menu')).toBeInTheDocument()
    fireEvent.keyDown(action, { key: 'Enter' })
    expect(override).toHaveBeenCalledOnce()

    await act(async () => { accept() })
    await waitFor(() => expect(screen.queryByRole('menu')).not.toBeInTheDocument())
  })

  it('keeps the menu open on a refusal, shows the server\'s reason where the listener pressed and leaves the action in place', async () => {
    const reason = 'The destination is still connected. Stop playback there.'
    const { override, item } = await openMenu({}, vi.fn(async () => { throw new Error(reason) }))

    fireEvent.click(item()!)

    expect(await screen.findByRole('alert')).toHaveTextContent(reason)
    expect(override).toHaveBeenCalledOnce()
    expect(screen.getByRole('menu')).toBeInTheDocument()
    expect(item()).toBeInTheDocument()
    expect(item()).not.toHaveAttribute('data-disabled')
  })
})
