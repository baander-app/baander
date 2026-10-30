import { useRef, useEffect, useContext } from 'react'
import { ThemeContext } from 'styled-components'
import type { AnalysisData } from '@/features/player/services/audio-processor'
import { frameSmoothing } from '../utils/spectrum'
import { audioService } from '@/features/player/services/audio-service'
import { usePaletteStore } from '../stores/palette-store'
import type { VisualizerRenderer, RenderContext } from '../types'

interface UseVisualizerLoopOptions {
  /** React ref to the current renderer — read .current each frame. */
  rendererRef: React.RefObject<VisualizerRenderer | null>
  /** Canvas element ref. */
  canvasRef: React.RefObject<HTMLCanvasElement | null>
  /** Smoothing alpha for EMA (default 0.35, matching EQ panel). */
  smoothingAlpha?: number
  /** Whether this is a compact/mini context. */
  compact?: boolean
  /** Album public ID for palette lookup. */
  albumPublicId?: string
  onError?: () => void
}

/** Hook that manages a requestAnimationFrame loop reading audio analysis data.
 *  Returns nothing; the loop's state is for the renderer, not the caller. */
export function useVisualizerLoop(options: UseVisualizerLoopOptions): void {
  const { rendererRef, canvasRef, smoothingAlpha = 0.35, compact = false, albumPublicId, onError } = options

  const rafRef = useRef<number>(0)
  const lastTimeRef = useRef(0)
  const runningRef = useRef(false)
  const theme = useContext(ThemeContext)
  const foreground = theme?.colors.foreground
  const accent = theme?.colors.accentText
  const background = theme?.colors.background

  const getPalette = usePaletteStore((s) => s.getPalette)

  useEffect(() => {
    runningRef.current = true
    lastTimeRef.current = performance.now()
    const style = canvasRef.current ? getComputedStyle(canvasRef.current) : null
    const colors = {
      foreground: foreground ?? (style?.getPropertyValue('--color-foreground').trim() || 'currentColor'),
      accent: accent ?? (style?.getPropertyValue('--color-accent-text').trim() || 'currentColor'),
      background: background ?? (style?.getPropertyValue('--color-background').trim() || 'transparent'),
    }
    let lastData: AnalysisData | undefined

    const draw = () => {
      if (!runningRef.current) return
      rafRef.current = requestAnimationFrame(draw)

      // Advance on every frame so unavailable inputs do not accumulate elapsed time.
      const now = performance.now()
      const deltaTime = now - lastTimeRef.current
      lastTimeRef.current = now

      const renderer = rendererRef.current
      const canvas = canvasRef.current
      if (!canvas || !renderer) return

      const processor = audioService.getProcessor()
      const freshData = processor?.getAnalysisData()
      const data = freshData ?? lastData
      if (freshData) lastData = freshData
      const ctx = renderer.isWebGL ? null : canvas.getContext('2d')
      const width = parseFloat(canvas.style.width) || canvas.clientWidth || canvas.width
      const height = parseFloat(canvas.style.height) || canvas.clientHeight || canvas.height
      if (ctx) ctx.setTransform(width > 0 ? canvas.width / width : 1, 0, 0, height > 0 ? canvas.height / height : 1, 0, 0)
      if (!data) {
        ctx?.clearRect(0, 0, width, height)
        return
      }
      const palette = albumPublicId ? getPalette(albumPublicId) : null

      const context: RenderContext = {
        ctx,
        data,
        active: Boolean(freshData && processor?.playing),
        colors,
        palette,
        deltaTime,
        width,
        height,
        smoothingAlpha: frameSmoothing(smoothingAlpha, deltaTime),
        compact,
      }

      // Render frame
      try {
        renderer.render(context)
      } catch (error) {
        console.error('[Visualizer] render error:', error)
        onError?.()
      }
    }

    rafRef.current = requestAnimationFrame(draw)

    return () => {
      runningRef.current = false
      cancelAnimationFrame(rafRef.current)
    }
  }, [canvasRef, smoothingAlpha, compact, albumPublicId, getPalette, rendererRef, onError, foreground, accent, background])
}
