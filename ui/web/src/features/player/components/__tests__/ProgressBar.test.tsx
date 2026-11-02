import { act, render, screen, fireEvent } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ProgressBar } from '../ProgressBar'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { updateTime } from '@/features/player/stores/player-time-tracker'

const originalSeek = usePlayerStore.getState().seekTo
const seekTo = vi.fn()

describe('progress control', () => {
  beforeEach(() => {
    seekTo.mockClear()
    usePlayerStore.setState({ duration: 30, playbackRecovery: 'none', seekTo })
    updateTime(10)
  })

  afterEach(() => {
    act(() => {
      updateTime(0)
      usePlayerStore.setState({ duration: 0, seekTo: originalSeek })
    })
  })

  it('observes clock updates without issuing a seek', () => {
    render(<ProgressBar />)
    act(() => updateTime(12))
    expect(screen.getByRole('slider', { name: 'Seek' })).toHaveAttribute('aria-valuenow', '12')
    expect(screen.getByText('0:12')).toBeVisible()
    expect(seekTo).not.toHaveBeenCalled()
  })

  it('keeps a drag draft across clock updates and commits one seek on release', () => {
    render(<ProgressBar />)
    const thumb = screen.getByRole('slider', { name: 'Seek' })
    const slider = thumb.closest('[data-slot="slider"]') as HTMLElement
    vi.spyOn(slider, 'getBoundingClientRect').mockReturnValue({ left: 0, width: 300 } as DOMRect)
    slider.setPointerCapture = vi.fn()
    slider.hasPointerCapture = vi.fn(() => true)
    slider.releasePointerCapture = vi.fn()
    const pointer = (type: string, clientX: number) => {
      const event = new Event(type, { bubbles: true })
      Object.assign(event, { clientX, pointerId: 1, button: 0 })
      fireEvent(slider, event)
    }
    pointer('pointerdown', 100)
    pointer('pointermove', 150)
    pointer('pointermove', 200)
    expect(seekTo).not.toHaveBeenCalled()
    expect(thumb).toHaveAttribute('aria-valuenow', '20')
    expect(screen.getByText('0:20')).toBeVisible()
    act(() => updateTime(11))
    expect(thumb).toHaveAttribute('aria-valuenow', '20')
    pointer('pointerup', 200)
    expect(seekTo).toHaveBeenCalledExactlyOnceWith(20)
    act(() => updateTime(22))
    expect(thumb).toHaveAttribute('aria-valuenow', '22')
  })

  it('seeks through keyboard input within the track duration', async () => {
    const user = userEvent.setup()
    render(<ProgressBar />)
    act(() => screen.getByRole('slider', { name: 'Seek' }).focus())
    await user.keyboard('{ArrowRight}')
    expect(seekTo).toHaveBeenLastCalledWith(11)
    await user.keyboard('{Home}')
    expect(seekTo).toHaveBeenLastCalledWith(0)
    await user.keyboard('{End}')
    expect(seekTo).toHaveBeenLastCalledWith(30)
  })

  it.each([0, -1, NaN, Infinity])('prevents seeking with unusable duration %s', async (duration) => {
    const user = userEvent.setup()
    usePlayerStore.setState({ duration })
    render(<ProgressBar />)
    const slider = screen.getByRole('slider', { name: 'Seek' })
    expect(slider).toHaveAttribute('data-disabled')
    expect(slider).toHaveAttribute('aria-valuenow', '0')
    await user.tab()
    await user.keyboard('{ArrowRight}')
    expect(seekTo).not.toHaveBeenCalled()
  })
})
