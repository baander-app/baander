import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { AnalysisData } from '@/features/player/services/audio-processor'
import type { RenderContext } from '../../types'
import { EnhancedSpectrumRenderer } from '../enhanced-spectrum'
import { CircularSpectrumRenderer } from '../circular-spectrum'
import { SpectrogramRenderer } from '../spectrogram'
import { frameSmoothing } from '../../utils/spectrum'

function drawingContext() {
  return {
    clearRect: vi.fn(), fillRect: vi.fn(), drawImage: vi.fn(), fillText: vi.fn(),
    beginPath: vi.fn(), moveTo: vi.fn(), lineTo: vi.fn(), stroke: vi.fn(),
    fillStyle: '', strokeStyle: '', globalAlpha: 1,
  }
}

const data: AnalysisData = {
  sampleRate: 48000, fftSize: 2048, minDecibels: -100, maxDecibels: 0,
  loudnessAvailable: false, phase: null, frequencyData: new Uint8Array(1024),
  timeDomainData: new Uint8Array(2048), leftChannel: 0, rightChannel: 0,
  lufs: 0, peakFrequency: 0, spectralCentroid: 0, spectralRolloff: 0,
  spectralFlux: 0, spectralFlatness: 0, rms: 0,
}

function context(ctx: ReturnType<typeof drawingContext>, deltaTime = 1000 / 60): RenderContext {
  return {
    ctx: ctx as unknown as CanvasRenderingContext2D,
    data, active: true, colors: { accent: '#234567', foreground: '#102030', background: '#ffffff' },
    width: 320, height: 160, deltaTime, smoothingAlpha: frameSmoothing(0.35, deltaTime),
    compact: false, palette: null,
  }
}

describe('calibrated renderers', () => {
  beforeEach(() => {
    data.frequencyData.fill(0)
    data.frequencyData[19] = 12
  })

  it('draws a quiet narrow tone with the selected theme color and clears on pause', () => {
    const ctx = drawingContext()
    const renderer = new EnhancedSpectrumRenderer()
    renderer.render(context(ctx))
    expect(ctx.fillRect).toHaveBeenCalledTimes(1)
    expect(ctx.fillStyle).toBe('#234567')
    const firstHeight = ctx.fillRect.mock.calls[0]![3]
    renderer.render({ ...context(ctx), active: false })
    expect(ctx.fillRect).toHaveBeenCalledTimes(1)
    renderer.render(context(ctx))
    expect(ctx.fillRect.mock.lastCall![3]).toBe(firstHeight)
  })

  it('draws the same bar heights for equal elapsed time at different frame rates', () => {
    function height(frames: number) {
      const ctx = drawingContext()
      const renderer = new EnhancedSpectrumRenderer()
      for (let i = 0; i < frames; i++) renderer.render(context(ctx, 100 / frames))
      return ctx.fillRect.mock.lastCall![3] as number
    }
    expect(height(3)).toBeCloseTo(height(12), 6)
  })

  it('keeps the circular view distinct and retains quiet narrow tones', () => {
    const ctx = drawingContext()
    new CircularSpectrumRenderer().render(context(ctx))
    expect(ctx.stroke).toHaveBeenCalledTimes(1)
    expect(ctx.lineTo).toHaveBeenCalledTimes(1)
    expect(ctx.strokeStyle).toBe('#234567')
  })

  it('scales circular stroke length with two known encoded spectrum levels', () => {
    function strokeLength(level: number) {
      data.frequencyData[19] = level
      const ctx = drawingContext()
      new CircularSpectrumRenderer().render({ ...context(ctx), smoothingAlpha: 1 })
      const [x1, y1] = ctx.moveTo.mock.lastCall!
      const [x2, y2] = ctx.lineTo.mock.lastCall!
      return Math.hypot(Number(x2) - Number(x1), Number(y2) - Number(y1))
    }
    expect(strokeLength(64)).toBeCloseTo(64 / 255 * 160 * 0.28, 5)
    expect(strokeLength(128)).toBeCloseTo(128 / 255 * 160 * 0.28, 5)
  })

  it('advances spectrogram history at the same rate and clears it on pause', () => {
    function run(frames: number) {
      const history = drawingContext()
      const spy = vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(history as unknown as CanvasRenderingContext2D)
      const renderer = new SpectrogramRenderer()
      renderer.init(document.createElement('canvas'), { width: 320, height: 160, compact: false, smoothingAlpha: 0.35 })
      const ctx = drawingContext()
      for (let i = 0; i < frames; i++) renderer.render(context(ctx, 1000 / frames))
      const distance = history.drawImage.mock.calls.reduce((sum, call) => sum - Number(call[1]), 0)
      renderer.render({ ...context(ctx), active: false })
      expect(history.clearRect).toHaveBeenLastCalledWith(0, 0, 320, 160)
      expect(history.fillStyle).toBe('#234567')
      spy.mockRestore()
      return distance
    }
    expect(run(30)).toBe(60)
    expect(run(120)).toBe(60)
  })
})
