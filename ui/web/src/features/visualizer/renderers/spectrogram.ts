import type { VisualizerRenderer, RenderContext, InitContext } from '../types'
import { aggregateSpectrum } from '../utils/spectrum'

/** Logarithmic frequency history, advancing at sixty CSS pixels per second. */
export class SpectrogramRenderer implements VisualizerRenderer {
  readonly id = 'spectrogram' as const
  readonly isWebGL = false
  private history: HTMLCanvasElement | null = null
  private historyCtx: CanvasRenderingContext2D | null = null
  private pendingScroll = 0
  private previousColors = ''

  init(_canvas: HTMLCanvasElement, { width, height }: InitContext): void {
    this.history = document.createElement('canvas')
    this.historyCtx = this.history.getContext('2d')
    if (!this.historyCtx) throw new Error('Canvas rendering is unavailable')
    this.resize(width, height)
  }

  render({ ctx, data, active, colors, width, height, deltaTime, compact }: RenderContext): void {
    if (!ctx || !this.history || !this.historyCtx || width <= 0 || height <= 0) return
    ctx.clearRect(0, 0, width, height)
    const colorKey = `${colors.background}/${colors.accent}`
    if (!active || colorKey !== this.previousColors) {
      this.historyCtx.clearRect(0, 0, this.history.width, this.history.height)
      this.pendingScroll = 0
      this.previousColors = colorKey
    }
    if (!active) return

    this.pendingScroll += deltaTime * 60 / 1000
    const scroll = Math.min(this.history.width, Math.floor(this.pendingScroll))
    if (scroll > 0) {
      this.pendingScroll -= Math.floor(this.pendingScroll)
      this.historyCtx.drawImage(this.history, -scroll, 0)
      this.historyCtx.clearRect(this.history.width - scroll, 0, scroll, this.history.height)
      const bands = aggregateSpectrum(data)
      const rowHeight = this.history.height / bands.length
      this.historyCtx.fillStyle = colors.accent
      for (let i = 0; i < bands.length; i++) {
        this.historyCtx.globalAlpha = bands[i]!
        this.historyCtx.fillRect(this.history.width - scroll, this.history.height - (i + 1) * rowHeight, scroll, rowHeight)
      }
      this.historyCtx.globalAlpha = 1
    }
    ctx.drawImage(this.history, 0, 0, width, height)
    if (!compact && data.peakFrequency > 0) {
      ctx.fillStyle = colors.foreground
      ctx.font = '10px monospace'
      ctx.textAlign = 'right'
      const label = data.peakFrequency >= 1000
        ? `${(data.peakFrequency / 1000).toFixed(1)} kHz`
        : `${Math.round(data.peakFrequency)} Hz`
      ctx.fillText(`Peak: ${label}`, width - 8, 16)
    }
  }

  resize(width: number, height: number): void {
    if (!this.history) return
    this.history.width = Math.max(1, Math.round(width))
    this.history.height = Math.max(1, Math.round(height))
    this.pendingScroll = 0
  }

  destroy(): void {
    this.history = null
    this.historyCtx = null
  }
}
