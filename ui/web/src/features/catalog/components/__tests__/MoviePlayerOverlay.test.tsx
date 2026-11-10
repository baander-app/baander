import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, screen } from '@testing-library/react'
import { render } from '../../../../../tests/test-utils'
import { MoviePlayerOverlay } from '../MoviePlayerOverlay'

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

afterEach(() => { cleanup() })
beforeEach(() => {
  request.mockReset()
  playback.reportError.mockReset()
  session.snapshot.media.publicId = 'video'
  session.snapshot.activeIncarnationId = 'local'
  session.endpoint = { incarnationId: 'local' }
  playback.videoId = 'video'
  playback.host = null
  playback.pendingSeekPosition = null
  session.snapshot.destinations[0].capabilities = ['volume']
})

function renderOverlay(onClose: () => void = vi.fn()) {
  render(<MoviePlayerOverlay title="Fixture movie" videoId="video" onClose={onClose} />)
}

function sliderRoot(name: string) {
  return screen.getByRole('slider', { name }).closest('[data-slot="slider"]') as HTMLElement
}

function controlsRow() {
  return screen.getByRole('button', { name: 'Pause' }).parentElement as HTMLElement
}

describe('movie overlay control layout', () => {
  it('bounds the volume control so the seek slider is the only full-width slider', () => {
    renderOverlay()
    const row = controlsRow()
    // the transport area is a single controls row
    expect(getComputedStyle(row).display).toBe('flex')

    const sliders = screen.getAllByRole('slider')
      .map(thumb => thumb.closest('[data-slot="slider"]') as HTMLElement)
    expect(sliders).toHaveLength(2)

    // only the seek slider resolves its width against the controls row itself
    const rowSliders = sliders.filter(slider => slider.parentElement === row)
    expect(rowSliders).toHaveLength(1)
    expect(rowSliders[0]).toBe(sliderRoot('Seek movie'))
    expect(getComputedStyle(rowSliders[0]).width).toBe('100%')

    // the volume slider fills a fixed-width wrapper instead of the row
    const volume = sliderRoot('Movie volume')
    const wrapper = volume.parentElement as HTMLElement
    expect(wrapper).not.toBe(row)
    // the shared player volume width is 5rem, which resolves to 80px at the default 16px root font
    expect(getComputedStyle(wrapper).width).toBe('80px')
    expect(getComputedStyle(volume).width).toBe('100%')
  })

  it('keeps labels and disabled states on the bounded controls while volume is unsupported', () => {
    session.snapshot.destinations[0].capabilities = []
    renderOverlay()
    const volume = sliderRoot('Movie volume')
    const seek = sliderRoot('Seek movie')
    expect(volume).toHaveAttribute('aria-disabled', 'true')
    expect(seek).toHaveAttribute('aria-disabled', 'false')
    // a destination without volume support still shows a bounded control, not a second full-width bar
    const wrapper = volume.parentElement as HTMLElement
    expect(wrapper).not.toBe(controlsRow())
    expect(getComputedStyle(wrapper).width).toBe('80px')
  })
})

describe('movie overlay close', () => {
  it('asks the session to stop this tab\'s video and closes once that resolves', async () => {
    // The request also resolves, without a control, while a transfer is uncertain: the
    // coordinator silences the local video and the session keeps its fence.
    request.mockResolvedValueOnce(undefined)
    const onClose = vi.fn()
    renderOverlay(onClose)

    fireEvent.click(screen.getByRole('button', { name: 'Back' }))

    await vi.waitFor(() => expect(onClose).toHaveBeenCalledOnce())
    expect(request).toHaveBeenCalledExactlyOnceWith({ action: 'stop-local-video', incarnationId: 'local', videoId: 'video' })
    expect(playback.reportError).not.toHaveBeenCalled()
  })

  it('stays open and reports the reason when the stop is refused', async () => {
    const refusal = new Error('Playback revision conflict.')
    request.mockRejectedValueOnce(refusal)
    const onClose = vi.fn()
    renderOverlay(onClose)

    fireEvent.click(screen.getByRole('button', { name: 'Back' }))

    await vi.waitFor(() => expect(playback.reportError).toHaveBeenCalledExactlyOnceWith(refusal))
    expect(onClose).not.toHaveBeenCalled()
  })

  it('closes without a request while this tab has no playback endpoint', async () => {
    session.endpoint = null
    const onClose = vi.fn()
    renderOverlay(onClose)

    fireEvent.click(screen.getByRole('button', { name: 'Back' }))

    await vi.waitFor(() => expect(onClose).toHaveBeenCalledOnce())
    expect(request).not.toHaveBeenCalled()
  })
})
