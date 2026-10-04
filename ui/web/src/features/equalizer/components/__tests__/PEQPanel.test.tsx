import { StrictMode } from 'react'
import { act, screen, fireEvent } from '@testing-library/react'
import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest'
import { render } from '../../../../../tests/test-utils'
import { PEQGraph } from '../PEQPanel'
import { useEqBandsStore } from '../../stores/eq-bands-store'

vi.mock('@/features/player/services/audio-service', () => ({
  audioService: {
    getProcessor: () => null,
  },
}))

let resize: (width: number) => void
const disconnect = vi.fn()

beforeEach(() => {
  useEqBandsStore.getState().setPreset('ROCK')
  disconnect.mockClear()

  vi.stubGlobal('ResizeObserver', class {
    constructor(callback: ResizeObserverCallback) {
      resize = (width) => callback([{ contentRect: { width } } as ResizeObserverEntry], this)
    }

    observe() {}
    unobserve() {}
    disconnect = disconnect
  })
})

afterEach(() => vi.unstubAllGlobals())

describe('PEQ graph ownership', () => {
  it('preserves the selected preset when mounted in StrictMode', () => {
    const bands = useEqBandsStore.getState().bands

    render(
      <StrictMode>
        <PEQGraph />
      </StrictMode>,
    )

    expect(useEqBandsStore.getState().bands).toBe(bands)
    expect(screen.getAllByRole('slider')[0]).toHaveAttribute('aria-valuenow', '4')
  })

  it('reflects external band changes without writing other bands', () => {
    render(<PEQGraph />)

    act(() => {
      useEqBandsStore.getState().setBandGain(0, 6)
    })

    expect(screen.getAllByRole('slider')[0]).toHaveAttribute('aria-valuenow', '6')
    expect(useEqBandsStore.getState().bands[1].gain).toBe(3)
  })

  it('previews a drag locally and commits only its band on release', () => {
    vi.stubGlobal('PointerEvent', MouseEvent)
    const { container } = render(<PEQGraph />)
    const point = screen.getAllByRole('slider')[0]
    point.setPointerCapture = vi.fn()
    const canvas = container.querySelector('svg')!.parentElement!
    vi.spyOn(canvas, 'getBoundingClientRect').mockReturnValue(new DOMRect(0, 0, 600, 200))
    const initial = useEqBandsStore.getState().bands

    fireEvent.pointerDown(point, { pointerId: 1 })
    fireEvent.pointerMove(canvas, { clientX: 80, clientY: 50 })

    expect(point).toHaveAttribute('aria-valuenow', '6')
    expect(point).toHaveStyle({ top: '50px' })
    expect(useEqBandsStore.getState().bands).toBe(initial)

    fireEvent.pointerUp(canvas)

    expect(useEqBandsStore.getState().bands[0].gain).toBe(6)
    expect(useEqBandsStore.getState().bands.slice(1)).toEqual(initial.slice(1))
  })

  it('updates coordinates on resize and releases its observer', () => {
    const { container, unmount } = render(<PEQGraph />)

    act(() => {
      resize(320)
    })

    expect(container.querySelector('svg')).toHaveAttribute('viewBox', '0 0 320 200')

    act(() => {
      resize(840)
    })

    expect(container.querySelector('svg')).toHaveAttribute('viewBox', '0 0 840 200')

    unmount()

    expect(disconnect).toHaveBeenCalledOnce()
  })
})
