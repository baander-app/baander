import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { ThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { MoviePlayerOverlay } from '@/features/catalog/components/MoviePlayerOverlay'

const { playback, session, request } = vi.hoisted(() => ({
  request: vi.fn(),
  session: { snapshot: { media: { kind: 'video', publicId: 'video' }, position: 3, activeIncarnationId: 'local', activeDestinationId: 'destination', destinations: [{ destinationId: 'destination', incarnationId: 'local', capabilities: ['volume'], observedVolume: 1 }], observedState: 'playing' }, endpoint: { incarnationId: 'local' } as { incarnationId: string } | null, connected: true },
  playback: {
  currentTime: 3, duration: 180, pendingSeekPosition: null as number | null, seekTo: vi.fn(), videoId: 'video', host: null as HTMLDivElement | null, error: null, reportError: vi.fn(),
} }))
vi.mock('@/features/catalog/stores/video-presentation-store', () => ({
  useVideoPresentation: Object.assign((selector: (state: typeof playback) => unknown) => selector(playback), { getState: () => playback }),
}))
vi.mock('@/features/session/hooks/use-playback-session', () => ({ usePlaybackSession: () => session }))
vi.mock('@/features/session/components/PlaybackDestinationSelector', () => ({ PlaybackDestinationSelector: () => <span>Destinations</span> }))
vi.mock('@/features/session/services/playback-execution', () => ({ requestPlayback: request }))

afterEach(() => { cleanup(); vi.restoreAllMocks(); vi.unstubAllGlobals() })
beforeEach(() => {
  vi.stubGlobal('PointerEvent', class extends MouseEvent {
    readonly pointerId: number
    constructor(type: string, options: PointerEventInit = {}) {
      super(type, options)
      this.pointerId = options.pointerId ?? 1
    }
  })
  session.snapshot.media.publicId = 'video'
  session.snapshot.activeIncarnationId = 'local'
  session.endpoint = { incarnationId: 'local' }
  playback.videoId = 'video'
  playback.host = null
  request.mockReset().mockImplementation((intent: { action: string, position?: number }) => {
    if (intent.action === 'seek') playback.seekTo(intent.position)
    return Promise.resolve()
  })
  playback.pendingSeekPosition = null
  playback.seekTo.mockClear()
})

function renderPlayer() {
  render(<ThemeProvider theme={resolveTheme('dark', 'violet')}>
    <MoviePlayerOverlay title="Fixture movie" videoId="video" onClose={vi.fn()} />
  </ThemeProvider>)
  return screen.getByRole('slider', { name: 'Seek movie' })
}

function trackFor(slider: HTMLElement, width = 180) {
  const track = slider.closest('[data-slot="slider"]') as HTMLElement
  vi.spyOn(track, 'getBoundingClientRect').mockReturnValue(new DOMRect(0, 0, width, 20))
  let captured = false
  track.setPointerCapture = () => { captured = true }
  track.hasPointerCapture = () => captured
  track.releasePointerCapture = () => { captured = false }
  return track
}

describe('movie source timeline', () => {
  it('previews a position across the full source and commits it on pointer release', () => {
    const timeline = renderPlayer()
    expect(timeline).toHaveAttribute('aria-valuemax', '180')
    const track = trackFor(timeline)
    fireEvent.pointerDown(track, { clientX: 120, pointerId: 1 })
    expect(playback.seekTo).not.toHaveBeenCalled()
    expect(timeline).toHaveAttribute('aria-valuetext', '2:00 of 3:00')
    fireEvent.pointerUp(track, { clientX: 120, pointerId: 1 })
    expect(playback.seekTo).toHaveBeenCalledExactlyOnceWith(120)
  })

  it('commits keyboard source seeking and exposes the pending global position', () => {
    playback.pendingSeekPosition = 120
    const timeline = renderPlayer()
    expect(timeline).toHaveAttribute('aria-valuenow', '120')
    fireEvent.keyDown(timeline, { key: 'ArrowRight' })
    expect(playback.seekTo).toHaveBeenCalledExactlyOnceWith(121)
  })

  it('commits a draft on blur without repeating the command on pointer release', () => {
    const timeline = renderPlayer()
    const track = trackFor(timeline)
    fireEvent.pointerDown(track, { clientX: 120, pointerId: 1 })
    fireEvent.blur(timeline)
    fireEvent.pointerUp(track, { clientX: 120, pointerId: 1 })
    expect(playback.seekTo).toHaveBeenCalledExactlyOnceWith(120)
  })

  it('abandons a cancelled pointer gesture without seeking', () => {
    const timeline = renderPlayer()
    const track = trackFor(timeline)
    fireEvent.pointerDown(track, { clientX: 120, pointerId: 1 })
    fireEvent.pointerCancel(track, { pointerId: 1 })
    expect(timeline).toHaveAttribute('aria-valuenow', '3')
    expect(playback.seekTo).not.toHaveBeenCalled()
  })

  it('previews volume throughout a drag and submits only its committed value', () => {
    renderPlayer()
    const volume = screen.getByRole('slider', { name: 'Movie volume' })
    const track = trackFor(volume, 100)
    fireEvent.pointerDown(track, { clientX: 70, pointerId: 1 })
    fireEvent.pointerMove(track, { clientX: 40, pointerId: 1 })
    expect(volume).toHaveAttribute('aria-valuenow', '0.4')
    expect(request).not.toHaveBeenCalled()
    fireEvent.pointerUp(track, { clientX: 40, pointerId: 1 })
    expect(request).toHaveBeenCalledExactlyOnceWith({ action: 'volume', volume: 0.4 })
  })

  it('commits keyboard volume access and discards a cancelled volume drag', () => {
    renderPlayer()
    const volume = screen.getByRole('slider', { name: 'Movie volume' })
    fireEvent.keyDown(volume, { key: 'ArrowLeft' })
    expect(request).toHaveBeenCalledExactlyOnceWith({ action: 'volume', volume: 0.99 })
    request.mockClear()
    const track = trackFor(volume, 100)
    fireEvent.pointerDown(track, { clientX: 30, pointerId: 1 })
    fireEvent.pointerCancel(track, { pointerId: 1 })
    fireEvent.pointerUp(track, { clientX: 30, pointerId: 1 })
    expect(request).not.toHaveBeenCalled()
    expect(volume).toHaveAttribute('aria-valuenow', '1')
  })

  it('returns the same native host to the shell when presentation closes', () => {
    const parking = document.createElement('div')
    const host = document.createElement('div')
    const video = document.createElement('video')
    host.appendChild(video)
    parking.appendChild(host)
    playback.host = host
    renderPlayer()
    expect(parking.contains(video)).toBe(false)
    expect(document.contains(video)).toBe(true)
    cleanup()
    expect(parking.firstChild).toBe(host)
    expect(host.firstChild).toBe(video)
    expect(request).not.toHaveBeenCalled()
  })

  it('scopes remote presentation close to its local incarnation without creating a video', async () => {
    session.snapshot.activeIncarnationId = 'remote'
    const onClose = vi.fn()
    render(<ThemeProvider theme={resolveTheme('dark', 'violet')}>
      <MoviePlayerOverlay title="Remote movie" videoId="video" sourceDuration={180} onClose={onClose} />
    </ThemeProvider>)
    fireEvent.click(screen.getByRole('button', { name: 'Back' }))
    await waitFor(() => expect(onClose).toHaveBeenCalledOnce())
    expect(request).toHaveBeenCalledExactlyOnceWith({ action: 'stop-local-video', incarnationId: 'local', videoId: 'video' })
    expect(document.querySelector('video')).toBeNull()
  })

  it('scopes local Back to the current incarnation and movie', async () => {
    const onClose = vi.fn()
    render(<ThemeProvider theme={resolveTheme('dark', 'violet')}>
      <MoviePlayerOverlay title="Local movie" videoId="video" onClose={onClose} />
    </ThemeProvider>)
    fireEvent.click(screen.getByRole('button', { name: 'Back' }))
    await waitFor(() => expect(onClose).toHaveBeenCalledOnce())
    expect(request).toHaveBeenCalledExactlyOnceWith({ action: 'stop-local-video', incarnationId: 'local', videoId: 'video' })
  })

  it('closes without a playback command when there is no browser endpoint', () => {
    session.endpoint = null
    const onClose = vi.fn()
    render(<ThemeProvider theme={resolveTheme('dark', 'violet')}>
      <MoviePlayerOverlay title="Movie" videoId="video" onClose={onClose} />
    </ThemeProvider>)
    fireEvent.click(screen.getByRole('button', { name: 'Back' }))
    expect(onClose).toHaveBeenCalledOnce()
    expect(request).not.toHaveBeenCalled()
  })

  it('keeps explicit Stop directed at the active destination', () => {
    session.snapshot.activeIncarnationId = 'remote'
    renderPlayer()
    fireEvent.click(screen.getByRole('button', { name: 'Stop' }))
    expect(request).toHaveBeenCalledExactlyOnceWith({ action: 'stop' })
  })

  it('discards an uncommitted drag when another movie replaces the overlay', () => {
    playback.videoId = 'first'
    session.snapshot.media.publicId = 'first'
    const theme = resolveTheme('dark', 'violet')
    const view = render(<ThemeProvider theme={theme}>
      <MoviePlayerOverlay title="First movie" videoId="first" onClose={vi.fn()} />
    </ThemeProvider>)
    const timeline = screen.getByRole('slider', { name: 'Seek movie' })
    const track = trackFor(timeline)
    fireEvent.pointerDown(track, { clientX: 120, pointerId: 1 })
    playback.videoId = 'second'
    session.snapshot.media.publicId = 'second'
    view.rerender(<ThemeProvider theme={theme}>
      <MoviePlayerOverlay title="Second movie" videoId="second" onClose={vi.fn()} />
    </ThemeProvider>)
    expect(screen.getByRole('slider', { name: 'Seek movie' })).toHaveAttribute('aria-valuenow', '3')
    fireEvent.pointerUp(track, { clientX: 120, pointerId: 1 })
    expect(playback.seekTo).not.toHaveBeenCalled()
  })
})
