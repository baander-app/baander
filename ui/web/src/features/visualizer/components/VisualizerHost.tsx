import { useRef, useEffect } from 'react'
import { rendererRegistry } from '../renderer-registry'
import { useVisualizerLoop } from '../hooks/use-visualizer-loop'
import type { VisualizerMode, VisualizerRenderer } from '../types'

interface VisualizerHostProps {
  /** Active visualizer mode. */
  mode: VisualizerMode
  /** Album public ID for palette extraction. */
  albumPublicId?: string
  /** Whether this is a compact/mini context. */
  compact?: boolean
  /** Additional CSS class names. */
  className?: string
  /** Opacity for the canvas (default 1.0 for fullscreen, 0.2 for mini). */
  opacity?: number
}

export function VisualizerHost({
  mode,
  albumPublicId,
  compact = false,
  className,
  opacity,
}: VisualizerHostProps) {
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const containerRef = useRef<HTMLDivElement>(null)
  const rendererRef = useRef<VisualizerRenderer | null>(null)
  const effectiveOpacity = opacity ?? (compact ? 0.2 : 1.0)

  // Create/destroy renderer on mode change
  useEffect(() => {
    // Destroy previous renderer
    if (rendererRef.current) {
      try { rendererRef.current.destroy() } catch { /* swallow */ }
      rendererRef.current = null
    }

    // Create new renderer
    const newRenderer = rendererRegistry.create(mode)
    if (!newRenderer) return

    const canvas = canvasRef.current
    if (!canvas) return

    // Initialize with current canvas dimensions
    const initResult = newRenderer.init(canvas, {
      width: canvas.width,
      height: canvas.height,
      smoothingAlpha: 0.35,
      compact,
    })

    // Handle async init (e.g., Three.js lazy load)
    if (initResult instanceof Promise) {
      initResult.then(() => {
        rendererRef.current = newRenderer
      })
    } else {
      rendererRef.current = newRenderer
    }

    return () => {
      if (rendererRef.current) {
        try { rendererRef.current.destroy() } catch { /* swallow */ }
        rendererRef.current = null
      }
    }
  }, [mode, compact])

  // Resize handling
  useEffect(() => {
    const container = containerRef.current
    const canvas = canvasRef.current
    if (!container || !canvas) return

    const observer = new ResizeObserver(([entry]) => {
      const { width, height } = entry.contentRect
      const dpr = compact ? 1 : window.devicePixelRatio || 1
      canvas.width = Math.floor(width * dpr)
      canvas.height = Math.floor(height * dpr)
      canvas.style.width = `${width}px`
      canvas.style.height = `${height}px`

      if (rendererRef.current) {
        rendererRef.current.resize(canvas.width, canvas.height)
      }
    })

    observer.observe(container)
    return () => observer.disconnect()
  }, [compact])

  // rAF loop — pass the ref itself so the hook reads .current each frame
  useVisualizerLoop({
    rendererRef,
    canvasRef,
    smoothingAlpha: 0.35,
    compact,
    albumPublicId,
  })

  return (
    <div ref={containerRef} className={className} style={{ position: 'absolute', inset: 0, overflow: 'hidden' }}>
      <canvas
        ref={canvasRef}
        style={{
          display: 'block',
          opacity: effectiveOpacity,
        }}
      />
    </div>
  )
}
