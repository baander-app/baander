import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import userEvent from '@testing-library/user-event'
import { act, cleanup, fireEvent, screen } from '@testing-library/react'
import { render } from '../../../../../tests/test-utils'
import { MoviePlayerOverlay } from '../MoviePlayerOverlay'

const { playback, session, request } = vi.hoisted(() => ({
  request: vi.fn(),
  session: {
    snapshot: {
      media: { kind: 'video', publicId: 'video' },
      position: 3,
      activeIncarnationId: 'local',
      activeDestinationId: 'destination',
      destinations: [
        {
          destinationId: 'destination',
          incarnationId: 'local',
          capabilities: ['volume'],
          observedVolume: 1
        }
      ],
      observedState: 'playing'
    },
    endpoint: { incarnationId: 'local' } as { incarnationId: string } | null,
    connected: true
  },
  playback: {
    currentTime: 3,
    duration: 180,
    pendingSeekPosition: null as number | null,
    seekTo: vi.fn(),
    videoId: 'video',
    host: null as HTMLDivElement | null,
    error: null,
    reportError: vi.fn()
  }
}))
vi.mock('@/features/catalog/stores/video-presentation-store', () => ({
  useVideoPresentation: Object.assign(
    (selector: (state: typeof playback) => unknown) => selector(playback),
    { getState: () => playback }
  )
}))
vi.mock('@/features/session/hooks/use-playback-session', () => ({
  usePlaybackSession: () => session
}))
vi.mock('@/features/session/components/PlaybackDestinationSelector', () => ({
  PlaybackDestinationSelector: () => <span>Destinations</span>
}))
vi.mock('@/features/catalog/hooks/use-video-playback-information', () => ({
  useVideoPlaybackInformation: () => ({
    data: undefined,
    previewUrl: null,
    isPending: false,
    isError: false
  })
}))
vi.mock('@/features/session/services/playback-execution', () => ({
  requestPlayback: request
}))

afterEach(() => {
  cleanup()
  vi.useRealTimers()
  Reflect.deleteProperty(document.documentElement, 'requestFullscreen')
})
beforeEach(() => {
  request.mockReset()
  request.mockResolvedValue(undefined)
  playback.reportError.mockReset()
  session.snapshot.media.publicId = 'video'
  session.snapshot.activeIncarnationId = 'local'
  session.endpoint = { incarnationId: 'local' }
  playback.videoId = 'video'
  playback.host = null
  playback.pendingSeekPosition = null
  session.snapshot.destinations[0].capabilities = ['volume']
})

function renderOverlay(onClose: () => void = vi.fn(), startSeconds = 0) {
  render(
    <MoviePlayerOverlay
      title="Fixture movie"
      videoId="video"
      startSeconds={startSeconds}
      onClose={onClose}
    />
  )
}

function sliderRoot(name: string) {
  return screen
    .getByRole('slider', { name })
    .closest('[data-slot="slider"]') as HTMLElement
}

function controlsRow() {
  return screen.getByRole('button', { name: 'Pause' })
    .parentElement as HTMLElement
}

describe('movie overlay control layout', () => {
  it('bounds the volume control so the seek slider is the only full-width slider', () => {
    renderOverlay()
    const row = controlsRow()
    // the transport area is a single controls row
    expect(getComputedStyle(row).display).toBe('flex')

    const sliders = screen
      .getAllByRole('slider')
      .map((thumb) => thumb.closest('[data-slot="slider"]') as HTMLElement)
    expect(sliders).toHaveLength(2)

    expect(getComputedStyle(sliderRoot('Seek video')).width).toBe('100%')

    // the volume slider fills a fixed-width wrapper instead of the row
    const volume = sliderRoot('Video volume')
    const wrapper = volume.parentElement as HTMLElement
    expect(wrapper).not.toBe(row)
    // the shared player volume width is 5rem, which resolves to 80px at the default 16px root font
    expect(getComputedStyle(wrapper).width).toBe('80px')
    expect(getComputedStyle(volume).width).toBe('100%')
  })

  it('keeps labels and disabled states on the bounded controls while volume is unsupported', () => {
    session.snapshot.destinations[0].capabilities = []
    renderOverlay()
    const volume = sliderRoot('Video volume')
    const seek = sliderRoot('Seek video')
    expect(volume).toHaveAttribute('aria-disabled', 'true')
    expect(seek).toHaveAttribute('aria-disabled', 'false')
    // a destination without volume support still shows a bounded control, not a second full-width bar
    const wrapper = volume.parentElement as HTMLElement
    expect(wrapper).not.toBe(controlsRow())
    expect(getComputedStyle(wrapper).width).toBe('80px')
  })
})

describe('movie overlay close', () => {
  it("asks the session to stop this tab's video and closes once that resolves", async () => {
    // The request also resolves, without a control, while a transfer is uncertain: the
    // coordinator silences the local video and the session keeps its fence.
    request.mockResolvedValueOnce(undefined)
    const onClose = vi.fn()
    renderOverlay(onClose)

    fireEvent.click(screen.getByRole('button', { name: 'Back' }))

    await vi.waitFor(() => expect(onClose).toHaveBeenCalledOnce())
    expect(request).toHaveBeenCalledExactlyOnceWith({
      action: 'stop-local-video',
      incarnationId: 'local',
      videoId: 'video'
    })
    expect(playback.reportError).not.toHaveBeenCalled()
  })

  it('stays open and reports the reason when the stop is refused', async () => {
    const refusal = new Error('Playback revision conflict.')
    request.mockRejectedValueOnce(refusal)
    const onClose = vi.fn()
    renderOverlay(onClose)

    fireEvent.click(screen.getByRole('button', { name: 'Back' }))

    await vi.waitFor(() =>
      expect(playback.reportError).toHaveBeenCalledExactlyOnceWith(refusal)
    )
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

describe('cinema navigation', () => {
  it('bounds skip and keyboard seeks to source duration', () => {
    renderOverlay()
    fireEvent.click(screen.getByRole('button', { name: 'Back 10 seconds (←)' }))
    expect(request).toHaveBeenLastCalledWith({ action: 'seek', position: 0 })
    fireEvent.keyDown(screen.getByRole('dialog'), { key: 'ArrowRight' })
    expect(request).toHaveBeenLastCalledWith({ action: 'seek', position: 13 })
  })

  it('ignores seek shortcuts while a settings panel owns focus', () => {
    renderOverlay()
    fireEvent.click(screen.getByRole('button', { name: 'Settings' }))
    const panel = screen.getByRole('region', { name: 'Settings' })
    fireEvent.keyDown(panel, { key: 'ArrowRight' })
    expect(request).not.toHaveBeenCalled()
    expect(
      screen.getByText(
        'Track and quality controls are unavailable for this playback format.'
      )
    ).toBeVisible()
  })

  it('fades during uninterrupted playback and keeps focused controls visible', () => {
    vi.useFakeTimers()
    renderOverlay()
    const dialog = screen.getByRole('dialog')
    act(() => vi.advanceTimersByTime(3001))
    expect(dialog).toHaveAttribute('data-idle', 'true')
    fireEvent.focus(screen.getByRole('button', { name: 'Pause' }))
    act(() => vi.advanceTimersByTime(4000))
    expect(dialog).toHaveAttribute('data-idle', 'false')
    vi.useRealTimers()
  })

  it('reports fullscreen rejection', async () => {
    renderOverlay()
    const reason = new Error('Fullscreen was denied.')
    document.documentElement.requestFullscreen = vi
      .fn()
      .mockRejectedValue(reason)
    fireEvent.click(screen.getByRole('button', { name: 'Fullscreen (F)' }))
    await vi.waitFor(() =>
      expect(playback.reportError).toHaveBeenCalledWith(reason)
    )
  })

  it('returns the persistent host to its parking element', () => {
    const parking = document.createElement('div')
    playback.host = document.createElement('div')
    parking.appendChild(playback.host)
    const view = render(
      <MoviePlayerOverlay
        title="Fixture movie"
        videoId="video"
        onClose={vi.fn()}
      />
    )
    expect(parking).not.toContainElement(playback.host)
    view.unmount()
    expect(parking).toContainElement(playback.host)
  })
})


describe('video resume choice', () => {
  it('restarts from zero with the keyboard and dismisses the resume control after success', async () => {
    const user = userEvent.setup()
    renderOverlay(vi.fn(), 83)
    const startOver = screen.getByRole('button', { name: 'Start Over' })
    startOver.focus()
    await user.keyboard('{Enter}')
    expect(request).toHaveBeenCalledExactlyOnceWith({ action: 'seek', position: 0 })
    expect(screen.queryByRole('button', { name: 'Start Over' })).not.toBeInTheDocument()
  })

  it('dismisses the resume notice without changing playback', async () => {
    const user = userEvent.setup()
    renderOverlay(vi.fn(), 83)
    await user.click(screen.getByRole('button', { name: 'Dismiss resume notice' }))
    expect(request).not.toHaveBeenCalled()
    expect(screen.queryByRole('button', { name: 'Start Over' })).not.toBeInTheDocument()
  })

  it('keeps the resume choice when the seek is refused', async () => {
    const user = userEvent.setup()
    request.mockRejectedValueOnce(new Error('Seek refused'))
    renderOverlay(vi.fn(), 83)
    await user.click(screen.getByRole('button', { name: 'Start Over' }))
    expect(screen.getByRole('button', { name: 'Start Over' })).toBeInTheDocument()
    expect(playback.reportError).toHaveBeenCalledWith(expect.objectContaining({ message: 'Seek refused' }))
  })
})
