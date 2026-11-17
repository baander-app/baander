import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { NowPlayingBar } from '../NowPlayingBar'
import { usePlayerStore } from '../../stores/player-store'
import { useRadioStore } from '@/features/radio/stores/radio-store'
import type { RadioStation } from '@/features/radio/api/radio-api'
import { useNotificationStore } from '@/features/notification/stores/notification-store'
import '@/shared/i18n'

vi.mock('@/shared/components/cover-art', () => ({ CoverArt: () => null }))
vi.mock('@/features/radio/components/RadioPlayerBar', () => ({ RadioPlayerBar: () => <div data-testid="radio-bar" /> }))
vi.mock('@/features/session/components/PlaybackDestinationSelector', () => ({
  PlaybackDestinationSelector: ({ compact }: { compact?: boolean }) => <span data-testid="destination" data-compact={compact} />,
}))

const initialPlayer = usePlayerStore.getInitialState()
const station: RadioStation = {
  publicId: 'station', sourceId: 'source', externalId: 'external', name: 'Station', country: 'DK',
  language: null, genres: [], tags: [], streams: [], logo: null, website: null, lastCheckedAt: null,
  createdAt: '2026-11-01T00:00:00Z', updatedAt: '2026-11-01T00:00:00Z',
}
const initialRadio = useRadioStore.getInitialState()
const setVolume = vi.fn()
const toggleMute = vi.fn()

beforeEach(() => {
  vi.clearAllMocks()
  useRadioStore.setState({ activeStation: null })
  usePlayerStore.setState({ ...initialPlayer, currentTrack: { publicId: 'song', title: 'Song', available: true },
    volume: 70, muted: true, duration: 30, setVolume, toggleMute }, true)
})

afterEach(() => {
  cleanup()
  usePlayerStore.setState(initialPlayer, true)
  useRadioStore.setState(initialRadio, true)
})

describe('player volume control', () => {
  it('displays a local drag draft and submits one unmuted volume command on release', () => {
    render(<NowPlayingBar />)
    const thumb = screen.getByRole('slider', { name: 'Volume' })
    const slider = thumb.closest('[data-slot="slider"]') as HTMLElement
    vi.spyOn(slider, 'getBoundingClientRect').mockReturnValue({ left: 0, width: 100 } as DOMRect)
    slider.setPointerCapture = vi.fn()
    slider.hasPointerCapture = vi.fn(() => true)
    slider.releasePointerCapture = vi.fn()
    const pointer = (type: string, clientX: number) => {
      const event = new Event(type, { bubbles: true })
      Object.assign(event, { clientX, pointerId: 1, button: 0 })
      fireEvent(slider, event)
    }
    pointer('pointerdown', 10)
    pointer('pointermove', 20)
    pointer('pointermove', 40)
    expect(setVolume).not.toHaveBeenCalled()
    expect(thumb).toHaveAttribute('aria-valuenow', '40')
    act(() => usePlayerStore.setState({ volume: 25, muted: false }))
    expect(thumb).toHaveAttribute('aria-valuenow', '40')
    pointer('pointerup', 40)
    expect(setVolume).toHaveBeenCalledExactlyOnceWith(40)
    expect(toggleMute).not.toHaveBeenCalled()
    expect(thumb).toHaveAttribute('aria-valuenow', '25')
    expect(screen.getByTestId('destination')).toHaveAttribute('data-compact', 'true')
  })

  it('commits keyboard volume changes without a second mute command', async () => {
    const user = userEvent.setup()
    render(<NowPlayingBar />)
    act(() => screen.getByRole('slider', { name: 'Volume' }).focus())
    await user.keyboard('{ArrowRight}')
    expect(setVolume).toHaveBeenCalledExactlyOnceWith(1)
    expect(toggleMute).not.toHaveBeenCalled()
  })
})

describe('now playing text', () => {
  it('renders the title, artist and album as separate items, each with its own language', () => {
    usePlayerStore.setState({
      currentTrack: {
        publicId: 'song',
        title: '直',
        artistName: '坂本龍一',
        albumName: '音楽図鑑',
        albumLanguage: 'jpn',
        available: true,
      },
    })

    render(<NowPlayingBar />)
    const nowPlaying = screen.getByRole('group', { name: 'Now playing' })

    expect(within(nowPlaying).getByText('直')).toHaveAttribute('lang', 'ja')
    expect(within(nowPlaying).getByText('坂本龍一')).toHaveAttribute('lang', 'en')
    expect(within(nowPlaying).getByText('音楽図鑑')).toHaveAttribute('lang', 'ja')
  })
})

describe('the well', () => {
  afterEach(() => {
    useNotificationStore.getState().setPopoutOpen(false)
  })

  it('yields the well while the notification pane is open and holds it again once the pane closes', () => {
    render(<NowPlayingBar />)
    const nowPlaying = screen.getByRole('group', { name: 'Now playing' })
    expect(nowPlaying).toHaveAttribute('data-well', 'held')

    act(() => useNotificationStore.getState().setPopoutOpen(true))
    expect(nowPlaying).toHaveAttribute('data-well', 'yielded')

    act(() => useNotificationStore.getState().setPopoutOpen(false))
    expect(nowPlaying).toHaveAttribute('data-well', 'held')
  })

  it('gives the radio bar no well to yield while the pane is open', () => {
    useRadioStore.setState({ activeStation: station })
    act(() => useNotificationStore.getState().setPopoutOpen(true))

    const { container } = render(<NowPlayingBar />)

    expect(screen.getByTestId('radio-bar')).toBeInTheDocument()
    expect(container.querySelector('[data-well]')).toBeNull()
  })
})
