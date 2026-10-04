import { StrictMode, createRef } from 'react'
import { cleanup, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { AnalysisData } from '@/features/player/services/audio-processor'
import type { RenderContext, VisualizerRenderer } from '../../types'
import { useVisualizerLoop } from '../use-visualizer-loop'

const { getProcessor, getAnalysisData } = vi.hoisted(() => ({
  getProcessor: vi.fn(),
  getAnalysisData: vi.fn(),
}))

vi.mock('@/features/player/services/audio-service', () => ({
  audioService: { getProcessor },
}))

const analysisData: AnalysisData = {
  phase: null,
  frequencyData: new Uint8Array(8),
  timeDomainData: new Uint8Array(8),
  leftChannel: 0,
  rightChannel: 0,
  lufs: 0,
  peakFrequency: 0,
  spectralCentroid: 0,
  spectralRolloff: 0,
  spectralFlux: 0,
  spectralFlatness: 0,
  rms: 0,
}

describe('useVisualizerLoop', () => {
  let now: number
  let nextFrameId: number
  let frames: Map<number, FrameRequestCallback>

  beforeEach(() => {
    now = 100
    nextFrameId = 0
    frames = new Map()
    vi.spyOn(performance, 'now').mockImplementation(() => now)
    vi.stubGlobal('requestAnimationFrame', vi.fn((callback: FrameRequestCallback) => {
      const id = ++nextFrameId
      frames.set(id, callback)
      return id
    }))
    vi.stubGlobal('cancelAnimationFrame', vi.fn((id: number) => frames.delete(id)))
    getProcessor.mockReturnValue({ getAnalysisData })
    getAnalysisData.mockReturnValue(analysisData)
  })

  afterEach(() => {
    cleanup()
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
    vi.clearAllMocks()
  })

  function runFrame(time: number) {
    now = time
    const frame = frames.entries().next().value
    if (!frame) throw new Error('No animation frame queued')
    const [id, callback] = frame
    frames.delete(id)
    callback(time)
  }

  function createOptions() {
    const render = vi.fn<(context: RenderContext) => void>()
    const renderer: VisualizerRenderer = {
      id: 'particles',
      isWebGL: true,
      init: vi.fn(),
      render,
      resize: vi.fn(),
      destroy: vi.fn(),
    }
    const rendererRef = createRef<VisualizerRenderer>()
    rendererRef.current = renderer
    const canvasRef = createRef<HTMLCanvasElement>()
    canvasRef.current = document.createElement('canvas')
    return { rendererRef, canvasRef, render }
  }

  it('reads the clock in the effect and frames, without reading it during rerenders', () => {
    const options = createOptions()
    let rendering = false
    let renderClockReads = 0
    vi.mocked(performance.now).mockImplementation(() => {
      if (rendering) renderClockReads++
      return now
    })
    const { rerender } = renderHook(() => {
      rendering = true
      useVisualizerLoop(options)
      rendering = false
    })
    expect(renderClockReads).toBe(0)

    runFrame(116)
    runFrame(132)
    expect(options.render.mock.calls.map(([context]) => context.deltaTime)).toEqual([16, 16])
    rerender()
    expect(renderClockReads).toBe(0)
    expect(frames.size).toBe(1)
  })

  it.each(['processor', 'data', 'canvas', 'renderer'] as const)(
    'keeps the frame clock current while %s is unavailable',
    (missing) => {
      const options = createOptions()
      const renderer = options.rendererRef.current
      const canvas = options.canvasRef.current
      renderHook(() => useVisualizerLoop(options))
      runFrame(116)

      if (missing === 'processor') getProcessor.mockReturnValue(null)
      if (missing === 'data') getAnalysisData.mockReturnValue(undefined)
      if (missing === 'canvas') options.canvasRef.current = null
      if (missing === 'renderer') options.rendererRef.current = null
      runFrame(10_000)
      expect(options.render).toHaveBeenCalledTimes(1)

      getProcessor.mockReturnValue({ getAnalysisData })
      getAnalysisData.mockReturnValue(analysisData)
      options.rendererRef.current = renderer
      options.canvasRef.current = canvas
      runFrame(10_016)
      expect(options.render.mock.lastCall?.[0].deltaTime).toBe(16)
      expect(frames.size).toBe(1)
    },
  )

  it('cancels the old loop and resets elapsed time when the effect restarts', () => {
    const options = createOptions()
    const { rerender, unmount } = renderHook(
      ({ compact }) => useVisualizerLoop({ ...options, compact }),
      { initialProps: { compact: false } },
    )
    runFrame(116)
    now = 10_000
    rerender({ compact: true })
    expect(cancelAnimationFrame).toHaveBeenCalledTimes(1)
    expect(frames.size).toBe(1)

    runFrame(10_016)
    expect(options.render.mock.lastCall?.[0]).toMatchObject({ deltaTime: 16, compact: true })
    unmount()
    expect(frames.size).toBe(0)
  })

  it('has one loop after StrictMode replay and cancels it on unmount', () => {
    const options = createOptions()
    const { unmount } = renderHook(() => useVisualizerLoop(options), { wrapper: StrictMode })
    expect(cancelAnimationFrame).toHaveBeenCalledTimes(1)
    expect(frames.size).toBe(1)
    runFrame(116)
    expect(options.render).toHaveBeenCalledTimes(1)

    unmount()
    expect(frames.size).toBe(0)
    expect(cancelAnimationFrame).toHaveBeenCalledTimes(2)
  })
})
