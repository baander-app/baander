import { useRef, useEffect } from 'react'
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
}

/** Hook that manages a requestAnimationFrame loop reading audio analysis data.
 *  Returns nothing — the return type from the plan was for debugging/introspection only. */
export function useVisualizerLoop(options: UseVisualizerLoopOptions): void {
  const { rendererRef, canvasRef, smoothingAlpha = 0.35, compact = false, albumPublicId } = options

  const rafRef = useRef<number>(0)
  const lastTimeRef = useRef(performance.now())
  const runningRef = useRef(false)
  const ctx2dRef = useRef<CanvasRenderingContext2D | null>(null)

  const getPalette = usePaletteStore((s) => s.getPalette)

  useEffect(() => {
    runningRef.current = true
    lastTimeRef.current = performance.now()

    const draw = () => {
      if (!runningRef.current) return
      rafRef.current = requestAnimationFrame(draw)

      const renderer = rendererRef.current
      const canvas = canvasRef.current
      if (!canvas || !renderer) return

      const processor = audioService.getProcessor()

      // Always get fresh data — getAnalysisData() returns zeroed sentinel when not playing
      const data = processor?.getAnalysisData()
      if (!data) return

      // Get palette
      const palette = albumPublicId ? getPalette(albumPublicId) : null

      // Compute delta time
      const now = performance.now()
      const deltaTime = now - lastTimeRef.current
      lastTimeRef.current = now

      // Cache 2D context — getContext returns same instance after first call
      if (!ctx2dRef.current && !renderer.isWebGL) {
        ctx2dRef.current = canvas.getContext('2d')
      }
      const ctx = renderer.isWebGL ? null : ctx2dRef.current

      const context: RenderContext = {
        ctx,
        data,
        palette,
        deltaTime,
        width: canvas.width,
        height: canvas.height,
        smoothingAlpha,
        compact,
      }

      // Render frame
      try {
        renderer.render(context)
      } catch (error) {
        console.error('[Visualizer] render error:', error)
      }
    }

    rafRef.current = requestAnimationFrame(draw)

    return () => {
      runningRef.current = false
      cancelAnimationFrame(rafRef.current)
    }
  }, [canvasRef, smoothingAlpha, compact, albumPublicId, getPalette, rendererRef])
}
