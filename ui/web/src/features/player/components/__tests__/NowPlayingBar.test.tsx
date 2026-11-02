import { act, cleanup, fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { NowPlayingBar } from '../NowPlayingBar'
import { usePlayerStore } from '../../stores/player-store'
import { useRadioStore } from '@/features/radio/stores/radio-store'

vi.mock('@/shared/components/cover-art', () => ({ CoverArt: () => null }))
vi.mock('@/features/session/components/PlaybackDestinationSelector', () => ({
  PlaybackDestinationSelector: ({ compact }: { compact?: boolean }) => <span data-testid="destination" data-compact={compact} />,
}))

const initialPlayer = usePlayerStore.getInitialState()
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
