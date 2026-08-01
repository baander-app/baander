import type { VisualizerRenderer, RenderContext } from '../types'

/** Enhanced spectrum bars renderer — gradient-filled bars with reflection and palette-aware coloring. */
export class EnhancedSpectrumRenderer implements VisualizerRenderer {
  readonly id = 'enhanced-spectrum' as const
  readonly isWebGL = false

  private compact = false
  private readonly smoothedBars = new Float32Array(64)

  init(_canvas: HTMLCanvasElement, context: { width: number; height: number; compact: boolean }): void {
    this.compact = context.compact
    this.compact = context.compact
  }

  render(context: RenderContext): void {
    const { ctx, data, palette, width, height, smoothingAlpha } = context
    if (!ctx || width === 0 || height === 0) return

    // Clear
    ctx.clearRect(0, 0, width, height)

    // Map frequency data to 64 bars with in-renderer smoothing
    const barCount = 64
    const alpha = smoothingAlpha
    const decay = 0.92

    // Detect low-energy state (paused or silent): getAnalysisData() fills with 20 when paused
    let totalEnergy = 0
    for (let i = 0; i < barCount; i++) {
      const dataIndex = Math.floor((i / barCount) * data.frequencyData.length)
      totalEnergy += data.frequencyData[dataIndex]! ?? 0
    }
    const isLowEnergy = totalEnergy / barCount < 25 // floor is 20, active audio is >>25

    if (!isLowEnergy) {
      for (let i = 0; i < barCount; i++) {
        const dataIndex = Math.floor((i / barCount) * data.frequencyData.length)
        const target = (data.frequencyData[dataIndex]! / 255) * 100
        this.smoothedBars[i] = this.smoothedBars[i]! * (1 - alpha) + target * alpha
      }
    } else {
      for (let i = 0; i < barCount; i++) {
        this.smoothedBars[i] = this.smoothedBars[i]! * decay
      }
    }

    // Bar geometry
    const gap = this.compact ? 1 : 2
    const barWidth = (width - gap * (barCount - 1)) / barCount
    const maxBarHeight = height * (this.compact ? 0.4 : 0.7)

    // Draw bars with gradient
    for (let i = 0; i < barCount; i++) {
      const barHeight = Math.max(1, (this.smoothedBars[i]! / 100) * maxBarHeight)
      const x = i * (barWidth + gap)
      const y = height - barHeight

      // Gradient: palette-aware or fallback to primary
      const gradient = ctx.createLinearGradient(x, height, x, y)
      if (palette) {
        gradient.addColorStop(0, palette.primary)
        gradient.addColorStop(0.6, palette.secondary)
        gradient.addColorStop(1, palette.accent)
      } else {
        gradient.addColorStop(0, 'rgba(255, 255, 255, 0.3)')
        gradient.addColorStop(0.5, 'rgba(255, 255, 255, 0.5)')
        gradient.addColorStop(1, 'rgba(255, 255, 255, 0.8)')
      }

      ctx.fillStyle = gradient
      ctx.fillRect(x, y, barWidth, barHeight)

      // Reflection (mirrored, faded) — skip in compact mode
      if (!this.compact) {
        const reflectionHeight = barHeight * 0.3
        const reflectionGradient = ctx.createLinearGradient(x, height, x, height + reflectionHeight)
        if (palette) {
          reflectionGradient.addColorStop(0, palette.primary + '40')
          reflectionGradient.addColorStop(1, palette.primary + '00')
        } else {
          reflectionGradient.addColorStop(0, 'rgba(255, 255, 255, 0.1)')
          reflectionGradient.addColorStop(1, 'rgba(255, 255, 255, 0)')
        }
        ctx.fillStyle = reflectionGradient
        ctx.fillRect(x, height, barWidth, reflectionHeight)
      }
    }

    // Bottom line
    if (!this.compact) {
      ctx.strokeStyle = palette ? palette.primary + '60' : 'rgba(255, 255, 255, 0.15)'
      ctx.lineWidth = 1
      ctx.beginPath()
      ctx.moveTo(0, height)
      ctx.lineTo(width, height)
      ctx.stroke()
    }
  }

  resize(_width: number, _height: number): void {
    // No-op — width/height come from RenderContext each frame
  }

  destroy(): void {
    this.smoothedBars.fill(0)
  }
}
