import type { VisualizerRenderer, RenderContext } from '../types'
import { aggregateSpectrum } from '../utils/spectrum'

/** Low-to-high logarithmic bands run clockwise from the top of the circle. */
export class CircularSpectrumRenderer implements VisualizerRenderer {
  readonly id = 'circular' as const
  readonly isWebGL = false
  private readonly smoothedBars = new Float32Array(64)

  init(): void {}

  render({ ctx, data, active, colors, width, height, smoothingAlpha }: RenderContext): void {
    if (!ctx || width <= 0 || height <= 0) return
    ctx.clearRect(0, 0, width, height)
    if (!active) {
      this.smoothedBars.fill(0)
      return
    }

    const bands = aggregateSpectrum(data, this.smoothedBars.length)
    const radius = Math.min(width, height) * 0.2
    const maxLength = Math.min(width, height) * 0.28
    ctx.strokeStyle = colors.accent
    ctx.lineWidth = Math.max(1, 2 * Math.PI * radius / bands.length * 0.6)
    ctx.lineCap = 'round'
    for (let i = 0; i < bands.length; i++) {
      this.smoothedBars[i] += (bands[i]! - this.smoothedBars[i]!) * smoothingAlpha
      const length = this.smoothedBars[i]! * maxLength
      if (length < 0.1) continue
      const angle = i / bands.length * 2 * Math.PI - Math.PI / 2
      ctx.beginPath()
      ctx.moveTo(width / 2 + Math.cos(angle) * radius, height / 2 + Math.sin(angle) * radius)
      ctx.lineTo(width / 2 + Math.cos(angle) * (radius + length), height / 2 + Math.sin(angle) * (radius + length))
      ctx.stroke()
    }
  }

  resize(): void {}

  destroy(): void {
    this.smoothedBars.fill(0)
  }
}
