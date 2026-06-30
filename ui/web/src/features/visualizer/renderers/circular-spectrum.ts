import type { VisualizerRenderer, RenderContext } from '../types'

/** Circular/radial spectrum renderer — bars emanate from center in a circle. */
export class CircularSpectrumRenderer implements VisualizerRenderer {
  readonly id = 'circular' as const
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

    ctx.clearRect(0, 0, width, height)

    const barCount = 64
    const alpha = smoothingAlpha
    const decay = 0.92

    // Low-energy detection
    let totalEnergy = 0
    for (let i = 0; i < barCount; i++) {
      const dataIndex = Math.floor((i / barCount) * data.frequencyData.length)
      totalEnergy += data.frequencyData[dataIndex]! ?? 0
    }
    const isLowEnergy = totalEnergy / barCount < 25

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

    // Circle geometry
    const centerX = width / 2
    const centerY = height / 2
    const minDim = Math.min(width, height)
    const innerRadius = minDim * (this.compact ? 0.15 : 0.2)
    const maxBarLength = minDim * (this.compact ? 0.15 : 0.3)

    // Draw bars radiating outward
    for (let i = 0; i < barCount; i++) {
      const angle = (i / barCount) * Math.PI * 2 - Math.PI / 2
      const barLength = Math.max(2, (this.smoothedBars[i]! / 100) * maxBarLength)

      const x1 = centerX + Math.cos(angle) * innerRadius
      const y1 = centerY + Math.sin(angle) * innerRadius
      const x2 = centerX + Math.cos(angle) * (innerRadius + barLength)
      const y2 = centerY + Math.sin(angle) * (innerRadius + barLength)

      const gradient = ctx.createLinearGradient(x1, y1, x2, y2)
      if (palette) {
        gradient.addColorStop(0, palette.primary + '80')
        gradient.addColorStop(1, palette.accent)
      } else {
        gradient.addColorStop(0, 'rgba(255, 255, 255, 0.3)')
        gradient.addColorStop(1, 'rgba(255, 255, 255, 0.8)')
      }

      ctx.strokeStyle = gradient
      ctx.lineWidth = Math.max(1, (2 * Math.PI * innerRadius) / barCount * 0.6)
      ctx.lineCap = 'round'
      ctx.beginPath()
      ctx.moveTo(x1, y1)
      ctx.lineTo(x2, y2)
      ctx.stroke()
    }

    // Center circle glow
    if (!this.compact && palette) {
      const glowGradient = ctx.createRadialGradient(centerX, centerY, 0, centerX, centerY, innerRadius * 1.5)
      glowGradient.addColorStop(0, palette.primary + '30')
      glowGradient.addColorStop(1, palette.primary + '00')
      ctx.fillStyle = glowGradient
      ctx.beginPath()
      ctx.arc(centerX, centerY, innerRadius * 1.5, 0, Math.PI * 2)
      ctx.fill()
    }

    // RMS-driven inner pulse
    const rms = data.rms
    if (rms > 0.01) {
      const pulseRadius = innerRadius * (0.5 + rms * 2)
      const pulseGradient = ctx.createRadialGradient(centerX, centerY, 0, centerX, centerY, pulseRadius)
      if (palette) {
        pulseGradient.addColorStop(0, palette.accent + '40')
        pulseGradient.addColorStop(1, palette.accent + '00')
      } else {
        pulseGradient.addColorStop(0, 'rgba(255, 255, 255, 0.15)')
        pulseGradient.addColorStop(1, 'rgba(255, 255, 255, 0)')
      }
      ctx.fillStyle = pulseGradient
      ctx.beginPath()
      ctx.arc(centerX, centerY, pulseRadius, 0, Math.PI * 2)
      ctx.fill()
    }
  }

  resize(_width: number, _height: number): void {
    // No-op — width/height come from RenderContext each frame
  }

  destroy(): void {
    this.smoothedBars.fill(0)
  }
}
