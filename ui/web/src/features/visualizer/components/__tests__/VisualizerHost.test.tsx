import { StrictMode } from 'react'
import { act, cleanup, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { VisualizerHost } from '../VisualizerHost'
import { rendererRegistry } from '../../renderer-registry'
import type { VisualizerRenderer } from '../../types'

vi.mock('../../hooks/use-visualizer-loop', () => ({ useVisualizerLoop: vi.fn() }))

function renderer(init: VisualizerRenderer['init'] = vi.fn()): VisualizerRenderer {
  return { id: 'enhanced-spectrum', isWebGL: false, init, render: vi.fn(), resize: vi.fn(), destroy: vi.fn() }
}

function deferred() {
  let resolve!: () => void
  let reject!: (error: Error) => void
  const promise = new Promise<void>((yes, no) => { resolve = yes; reject = no })
  return { promise, resolve, reject }
}

describe('VisualizerHost ownership', () => {
  beforeEach(() => {
    vi.spyOn(rendererRegistry, 'has').mockReturnValue(true)
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockReturnValue({ width: 320, height: 160 } as DOMRect)
  })
  afterEach(() => {
    cleanup()
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
  })

  it('destroys a late initializer once without replacing the current mode', async () => {
    const pending = deferred()
    const old = renderer(() => pending.promise)
    const current = renderer()
    vi.spyOn(rendererRegistry, 'create').mockReturnValueOnce(old).mockReturnValueOnce(current)
    const { rerender, unmount } = render(<VisualizerHost mode="particles" />)
    const oldCanvas = document.querySelector('canvas')
    rerender(<VisualizerHost mode="circular" />)
    expect(document.querySelector('canvas')).not.toBe(oldCanvas)
    await act(async () => pending.resolve())
    expect(old.destroy).toHaveBeenCalledTimes(1)
    expect(current.destroy).not.toHaveBeenCalled()
    unmount()
    expect(current.destroy).toHaveBeenCalledTimes(1)
  })

  it.each(['resolve', 'reject'] as const)('cleans up a pending initializer after unmount and %s', async (outcome) => {
    const pending = deferred()
    const instance = renderer(() => pending.promise)
    vi.spyOn(rendererRegistry, 'create').mockReturnValue(instance)
    const { unmount } = render(<VisualizerHost mode="particles" />)
    unmount()
    await act(async () => outcome === 'resolve' ? pending.resolve() : pending.reject(new Error('WebGL unavailable')))
    expect(instance.destroy).toHaveBeenCalledTimes(1)
    expect(screen.queryByRole('status')).not.toBeInTheDocument()
  })

  it('shows initialization failure and releases partial resources', async () => {
    const instance = renderer(() => Promise.reject(new Error('WebGL unavailable')))
    vi.spyOn(rendererRegistry, 'create').mockReturnValue(instance)
    render(<VisualizerHost mode="particles" />)
    expect(await screen.findByRole('status')).toHaveTextContent('Visualization unavailable.')
    expect(instance.destroy).toHaveBeenCalledTimes(1)
  })

  it('gives async initialization the latest CSS size at activation', async () => {
    const pending = deferred()
    const instance = renderer(() => pending.promise)
    vi.spyOn(rendererRegistry, 'create').mockReturnValue(instance)
    let resized!: ResizeObserverCallback
    vi.stubGlobal('ResizeObserver', class {
      constructor(callback: ResizeObserverCallback) { resized = callback }
      observe() {}
      disconnect() {}
    })
    render(<VisualizerHost mode="particles" />)
    act(() => resized([{ contentRect: { width: 640, height: 240 } } as ResizeObserverEntry], {} as ResizeObserver))
    await act(async () => pending.resolve())
    expect(instance.resize).toHaveBeenLastCalledWith(640, 240)
  })

  it('releases each StrictMode instance exactly once', () => {
    const first = renderer()
    const second = renderer()
    vi.spyOn(rendererRegistry, 'create').mockReturnValueOnce(first).mockReturnValueOnce(second)
    const { unmount } = render(<StrictMode><VisualizerHost mode="circular" /></StrictMode>)
    expect(first.destroy).toHaveBeenCalledTimes(1)
    unmount()
    expect(second.destroy).toHaveBeenCalledTimes(1)
  })
})
