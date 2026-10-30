import { useRef, useEffect, useState, useCallback } from 'react'
import { rendererRegistry } from '../renderer-registry'
import { useVisualizerLoop } from '../hooks/use-visualizer-loop'
import type { VisualizerMode, VisualizerRenderer } from '../types'

interface VisualizerHostProps {
  mode: VisualizerMode
  albumPublicId?: string
  compact?: boolean
  className?: string
  opacity?: number
}

export function VisualizerHost(props: VisualizerHostProps) {
  return <VisualizerInstance key={`${props.mode}:${props.compact ?? false}`} {...props} />
}

function VisualizerInstance({ mode, albumPublicId, compact = false, className, opacity }: VisualizerHostProps) {
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const containerRef = useRef<HTMLDivElement>(null)
  const rendererRef = useRef<VisualizerRenderer | null>(null)
  const cancelRef = useRef<() => void>(() => {})
  const [failure, setFailure] = useState<string | null>(null)
  const onError = useCallback(() => {
    cancelRef.current()
    setFailure('Visualization unavailable.')
  }, [])

  useEffect(() => {
    const canvas = canvasRef.current
    const container = containerRef.current
    if (!canvas || !container) return
    const renderer = rendererRegistry.create(mode)
    if (!renderer) return
    let cancelled = false
    let settled = false
    let destroyed = false
    let width = 0
    let height = 0
    const destroy = () => {
      if (destroyed) return
      destroyed = true
      try { renderer.destroy() } catch (error) { console.error('[Visualizer] cleanup failed:', error) }
    }
    const cancel = () => {
      if (!cancelled) renderer.cancel?.()
      cancelled = true
      if (rendererRef.current === renderer) rendererRef.current = null
      if (settled) destroy()
    }
    cancelRef.current = cancel
    const resize = (nextWidth: number, nextHeight: number) => {
      width = nextWidth
      height = nextHeight
      const dpr = compact ? 1 : window.devicePixelRatio || 1
      canvas.width = Math.round(width * dpr)
      canvas.height = Math.round(height * dpr)
      canvas.style.width = `${width}px`
      canvas.style.height = `${height}px`
      if (rendererRef.current === renderer) {
        try { renderer.resize(width, height) } catch { onError() }
      }
    }
    const bounds = container.getBoundingClientRect()
    resize(bounds.width, bounds.height)
    const observer = new ResizeObserver(([entry]) => {
      if (!cancelled && entry) resize(entry.contentRect.width, entry.contentRect.height)
    })
    observer.observe(container)
    const onWindowResize = () => {
      if (!cancelled) resize(width, height)
    }
    window.addEventListener('resize', onWindowResize)
    const ready = () => {
      settled = true
      if (cancelled) {
        destroy()
        return
      }
      try {
        renderer.resize(width, height)
        rendererRef.current = renderer
      } catch {
        cancel()
        setFailure('Visualization unavailable.')
      }
    }
    const failed = () => {
      settled = true
      destroy()
      if (!cancelled) {
        cancelled = true
        setFailure('Visualization unavailable.')
      }
    }
    try {
      const result = renderer.init(canvas, { width, height, smoothingAlpha: 0.35, compact })
      if (result) Promise.resolve(result).then(ready, failed)
      else ready()
    } catch {
      failed()
    }
    return () => {
      observer.disconnect()
      window.removeEventListener('resize', onWindowResize)
      cancel()
    }
  }, [mode, compact, onError])

  useVisualizerLoop({ rendererRef, canvasRef, smoothingAlpha: 0.35, compact, albumPublicId, onError })

  return (
    <div ref={containerRef} className={className} style={{ position: 'absolute', inset: 0, overflow: 'hidden' }}>
      <canvas ref={canvasRef} style={{ display: 'block', opacity: opacity ?? (compact ? 0.2 : 1) }} />
      {(failure || !rendererRegistry.has(mode)) && (
        <span role="status" style={{ color: 'var(--color-destructive)', position: 'absolute', inset: 8 }}>
          {failure ?? 'Visualization unavailable.'}
        </span>
      )}
    </div>
  )
}
