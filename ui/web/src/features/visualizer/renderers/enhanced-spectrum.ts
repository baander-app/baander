import type { VisualizerRenderer, RenderContext } from '../types'
import { aggregateSpectrum } from '../utils/spectrum'

/** Logarithmic spectrum bars, with peak-preserving frequency bands. */
export class EnhancedSpectrumRenderer implements VisualizerRenderer {
  readonly id = 'enhanced-spectrum' as const
  readonly isWebGL = false
  private readonly smoothedBars = new Float32Array(64)

  init(): void {}

  render({ ctx, data, active, colors, width, height, smoothingAlpha, compact }: RenderContext): void {
    if (!ctx || width <= 0 || height <= 0) return
    ctx.clearRect(0, 0, width, height)
    if (!active) {
      this.smoothedBars.fill(0)
      return
    }

    const bands = aggregateSpectrum(data, this.smoothedBars.length)
    const gap = compact ? 1 : 2
    const barWidth = Math.max(0, (width - gap * (bands.length - 1)) / bands.length)
    ctx.fillStyle = colors.accent
    for (let i = 0; i < bands.length; i++) {
      this.smoothedBars[i] += (bands[i]! - this.smoothedBars[i]!) * smoothingAlpha
      const barHeight = this.smoothedBars[i]! * height * 0.9
      if (barHeight < 0.1) continue
      ctx.fillRect(i * (barWidth + gap), height - barHeight, barWidth, barHeight)
    }
  }

  resize(): void {}

  destroy(): void {
    this.smoothedBars.fill(0)
  }
}
