import type { VisualizerRenderer, RenderContext } from '../types'

/** Spectrogram heatmap renderer — scrolling frequency vs time visualization. */
export class SpectrogramRenderer implements VisualizerRenderer {
  readonly id = 'spectrogram' as const
  readonly isWebGL = false

  private width = 0
  private height = 0
  private compact = false
  // Offscreen canvas for scrolling history
  private historyCanvas: OffscreenCanvas | null = null
  private historyCtx: OffscreenCanvasRenderingContext2D | null = null
  private readonly barCount = 64

  init(_canvas: HTMLCanvasElement, context: { width: number; height: number; compact: boolean }): void {
    this.width = context.width
    this.height = context.height
    this.compact = context.compact
    this.initHistory()
  }

  private initHistory(): void {
    if (this.width === 0 || this.height === 0) return
    this.historyCanvas = new OffscreenCanvas(this.width, this.height)
    this.historyCtx = this.historyCanvas.getContext('2d')
  }

  render(context: RenderContext): void {
    const { ctx, data, palette, width, height } = context
    if (!ctx || width === 0 || height === 0) return
    if (!this.historyCanvas || !this.historyCtx) return

    // Scroll existing content left by 1 pixel
    this.historyCtx.drawImage(this.historyCanvas, -1, 0)

    // Clear the rightmost column
    this.historyCtx.clearRect(width - 1, 0, 1, height)

    // Draw new column on the right edge
    const barHeight = height / this.barCount
    for (let i = 0; i < this.barCount; i++) {
      // Map bins bottom-to-top (low freq at bottom)
      const dataIndex = Math.floor((i / this.barCount) * data.frequencyData.length)
      const value = data.frequencyData[dataIndex]! / 255

      // Color mapping: palette-aware or default heatmap
      const color = this.valueToColor(value, palette)
      this.historyCtx.fillStyle = color
      this.historyCtx.fillRect(width - 1, height - (i + 1) * barHeight, 1, barHeight)
    }

    // Composite history onto main canvas
    ctx.clearRect(0, 0, width, height)
    ctx.drawImage(this.historyCanvas, 0, 0)

    // Overlay: subtle gradient overlay for depth
    if (!this.compact) {
      const overlay = ctx.createLinearGradient(0, 0, width * 0.3, 0)
      overlay.addColorStop(0, 'rgba(0, 0, 0, 0.6)')
      overlay.addColorStop(1, 'rgba(0, 0, 0, 0)')
      ctx.fillStyle = overlay
      ctx.fillRect(0, 0, width, height)
    }

    // Frequency scale labels (skip in compact)
    if (!this.compact && data.peakFrequency > 0) {
      ctx.fillStyle = 'rgba(255, 255, 255, 0.5)'
      ctx.font = '10px monospace'
      ctx.textAlign = 'right'
      const freqLabel = data.peakFrequency > 1000
        ? `${(data.peakFrequency / 1000).toFixed(1)}kHz`
        : `${Math.round(data.peakFrequency)}Hz`
      ctx.fillText(`Peak: ${freqLabel}`, width - 8, 16)
    }
  }

  /** Map a 0-1 value to a heatmap color. */
  private valueToColor(value: number, palette: RenderContext['palette']): string {
    if (palette) {
      // Use palette colors: background → primary → accent
      if (value < 0.3) {
        return this.lerpColor(palette.background, palette.primary, value / 0.3)
      } else if (value < 0.7) {
        return this.lerpColor(palette.primary, palette.secondary, (value - 0.3) / 0.4)
      } else {
        return this.lerpColor(palette.secondary, palette.accent, (value - 0.7) / 0.3)
      }
    }
    // Default heatmap: black → blue → cyan → yellow → white
    if (value < 0.25) {
      return this.lerpColor('#000000', '#0000ff', value / 0.25)
    } else if (value < 0.5) {
      return this.lerpColor('#0000ff', '#00ffff', (value - 0.25) / 0.25)
    } else if (value < 0.75) {
      return this.lerpColor('#00ffff', '#ffff00', (value - 0.5) / 0.25)
    } else {
      return this.lerpColor('#ffff00', '#ffffff', (value - 0.75) / 0.25)
    }
  }

  /** Linear interpolation between two hex colors. */
  private lerpColor(a: string, b: string, t: number): string {
    const ar = parseInt(a.slice(1, 3), 16)
    const ag = parseInt(a.slice(3, 5), 16)
    const ab = parseInt(a.slice(5, 7), 16)
    const br = parseInt(b.slice(1, 3), 16)
    const bg = parseInt(b.slice(3, 5), 16)
    const bb = parseInt(b.slice(5, 7), 16)
    const r = Math.round(ar + (br - ar) * t)
    const g = Math.round(ag + (bg - ag) * t)
    const bv = Math.round(ab + (bb - ab) * t)
    return `#${r.toString(16).padStart(2, '0')}${g.toString(16).padStart(2, '0')}${bv.toString(16).padStart(2, '0')}`
  }

  resize(width: number, height: number): void {
    this.width = width
    this.height = height
    this.initHistory()
  }

  destroy(): void {
    this.historyCanvas = null
    this.historyCtx = null
  }
}
