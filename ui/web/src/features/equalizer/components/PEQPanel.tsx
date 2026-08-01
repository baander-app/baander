import { useCallback, useEffect, useRef, useState } from 'react'
import { useEqBandsStore, EQ_BANDS, DEFAULT_Q } from '../stores/eq-bands-store'
import styled, { css } from 'styled-components'

const MAX_PEQ_POINTS = 15
const FREQ_MIN = 20
const FREQ_MAX = 20000

interface PEQPoint {
  frequency: number
  gain: number
  q: number
}

function logFreqToX(freq: number, width: number): number {
  return ((Math.log10(freq) - Math.log10(FREQ_MIN)) / (Math.log10(FREQ_MAX) - Math.log10(FREQ_MIN))) * width
}

function xToLogFreq(x: number, width: number): number {
  const ratio = x / width
  return Math.pow(10, Math.log10(FREQ_MIN) + ratio * (Math.log10(FREQ_MAX) - Math.log10(FREQ_MIN)))
}

function gainToY(gain: number, height: number): number {
  return height / 2 - (gain / 24) * height / 2 // ±12 dB range (using 24 for margin)
}

function yToGain(y: number, height: number): number {
  return Math.max(-12, Math.min(12, -((y - height / 2) / (height / 2)) * 12))
}

function computeResponseCurve(points: PEQPoint[], width: number): string {
  const steps = 200
  const pathParts: string[] = []

  for (let i = 0; i <= steps; i++) {
    const x = (i / steps) * width
    const freq = xToLogFreq(x, width)

    // Sum contributions from all peaking filters
    let totalGainDb = 0
    for (const point of points) {
      const bw = point.frequency / point.q
      const dist = Math.log2(freq / point.frequency)
      const sigma = Math.log2((point.frequency + bw / 2) / (point.frequency - bw / 2)) / 2
      const response = Math.exp(-(dist * dist) / (2 * sigma * sigma))
      totalGainDb += point.gain * response
    }

    const y = gainToY(totalGainDb, 200)
    pathParts.push(i === 0 ? `M ${x},${y}` : `L ${x},${y}`)
  }

  return pathParts.join(' ')
}

const Wrapper = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`

const Canvas = styled.div`
  position: relative;
  border-radius: var(--radius-md);
  background-color: rgba(var(--color-muted-rgb, 128 128 128), 0.3);
  border: 1px solid rgba(var(--color-border-rgb, 128 128 128), 0.5);
  user-select: none;
  touch-action: none;
`

const SvgOverlay = styled.svg`
  position: absolute;
  inset: 0;
`

const DraggablePoint = styled.div<{ $selected: boolean }>`
  position: absolute;
  width: 0.75rem;
  height: 0.75rem;
  border-radius: 9999px;
  transform: translate(-50%, -50%);
  cursor: grab;
  background-color: var(--color-primary);
  border: 2px solid var(--color-background);
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
  transition: box-shadow 0.15s;

  &:active { cursor: grabbing; }

  ${(p) => p.$selected && css`
    box-shadow: 0 0 0 2px rgba(var(--color-primary-rgb, 0 0 0), 0.5);
  `}
`

const PointInfo = styled.div`
  display: flex;
  align-items: center;
  gap: 1rem;
  font-size: 11px;
`

const InfoLabel = styled.span`
  color: var(--color-muted-foreground);
`

const GainLabel = styled.span<{ $positive: boolean | null }>`
  ${(p) => {
    if (p.$positive === true) return css`color: var(--color-primary);`
    if (p.$positive === false) return css`color: var(--color-muted-foreground);`
    return css`color: var(--color-foreground);`
  }}
`

const HelpText = styled.p`
  font-size: 10px;
  color: var(--color-muted-foreground);
  text-align: center;
`

const PEQWrapper = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
`

export function PEQGraph() {
  const canvasRef = useRef<HTMLDivElement>(null)
  const [points, setPoints] = useState<PEQPoint[]>(() =>
    EQ_BANDS.map((b) => ({
      frequency: b.frequency,
      gain: 0,
      q: DEFAULT_Q,
    }))
  )
  const [dragIndex, setDragIndex] = useState<number | null>(null)
  const [selectedPoint, setSelectedPoint] = useState<number | null>(null)
  const setBand = useEqBandsStore((s) => s.setBand)

  const updatePoint = useCallback((index: number, freq: number, gain: number) => {
    setPoints((prev) => {
      const next = [...prev]
      next[index] = { ...next[index], frequency: freq, gain }
      return next
    })
  }, [])

  const syncingRef = useRef(false)

  // Sync points → store when not dragging
  useEffect(() => {
    if (dragIndex !== null) return
    syncingRef.current = true
    points.forEach((p, i) => {
      setBand(i, p.gain, p.q)
    })
    // Reset flag after React finishes the batch
    queueMicrotask(() => { syncingRef.current = false })
  }, [points, dragIndex, setBand])

  // Sync store → points on external changes only
  const bands = useEqBandsStore((s) => s.bands)
  useEffect(() => {
    if (dragIndex !== null || syncingRef.current) return
    setPoints((prev) =>
      prev.map((p, i) =>
        i < bands.length
          ? { ...p, gain: bands[i].gain, q: bands[i].q }
          : p
      )
    )
  }, [bands, dragIndex])

  const handlePointerDown = useCallback((e: React.PointerEvent, index: number) => {
    e.preventDefault()
    e.stopPropagation()
    setDragIndex(index)
    setSelectedPoint(index)
    ;(e.target as HTMLElement).setPointerCapture(e.pointerId)
  }, [])

  const handlePointerMove = useCallback((e: React.PointerEvent) => {
    if (dragIndex === null || !canvasRef.current) return
    const rect = canvasRef.current.getBoundingClientRect()
    const x = e.clientX - rect.left
    const y = e.clientY - rect.top
    const freq = Math.max(FREQ_MIN, Math.min(FREQ_MAX, xToLogFreq(x, rect.width)))
    const gain = yToGain(y, rect.height)
    updatePoint(dragIndex, freq, gain)
  }, [dragIndex, updatePoint])

  const handlePointerUp = useCallback(() => {
    setDragIndex(null)
  }, [])

  const width = canvasRef.current?.clientWidth ?? 600
  const height = 200
  const curvePath = computeResponseCurve(points, width)

  return (
    <Wrapper>
      <Canvas
        ref={canvasRef}
        style={{ height }}
        onPointerMove={handlePointerMove}
        onPointerUp={handlePointerUp}
        onPointerLeave={handlePointerUp}
      >
        {/* Grid lines */}
        <SvgOverlay viewBox={`0 0 ${width} ${height}`} preserveAspectRatio="none">
          {/* 0 dB line */}
          <line x1="0" y1={height / 2} x2={width} y2={height / 2} stroke="currentColor" strokeWidth="0.5" opacity={0.15} />
          {/* Frequency markers */}
          {[100, 1000, 10000].map((f) => {
            const x = logFreqToX(f, width)
            return <line key={f} x1={x} y1="0" x2={x} y2={height} stroke="currentColor" strokeWidth="0.5" opacity={0.1} />
          })}
          {/* Response curve */}
          <path d={curvePath} stroke="hsl(var(--primary))" strokeWidth="2" fill="none" opacity={0.7} vectorEffect="non-scaling-stroke" />
          {/* Fill under curve */}
          <path
            d={`${curvePath} L ${width},${height / 2} L 0,${height / 2} Z`}
            fill="hsl(var(--primary))"
            opacity={0.08}
          />
        </SvgOverlay>

        {/* Draggable points */}
        {points.map((point, i) => {
          const x = logFreqToX(point.frequency, width)
          const y = gainToY(point.gain, height)
          return (
            <DraggablePoint
              key={i}
              $selected={selectedPoint === i}
              style={{ left: x, top: y }}
              onPointerDown={(e) => handlePointerDown(e, i)}
              role="slider"
              aria-label={`Band ${i + 1}: ${point.frequency.toFixed(0)}Hz ${point.gain >= 0 ? '+' : ''}${point.gain.toFixed(1)}dB`}
              aria-valuenow={point.gain}
              aria-valuemin={-12}
              aria-valuemax={12}
              tabIndex={0}
            />
          )
        })}
      </Canvas>

      {/* Selected point info */}
      {selectedPoint !== null && (
        <PointInfo>
          <InfoLabel>
            {points[selectedPoint].frequency >= 1000
              ? `${(points[selectedPoint].frequency / 1000).toFixed(1)}K`
              : points[selectedPoint].frequency.toFixed(0)}Hz
          </InfoLabel>
          <GainLabel $positive={points[selectedPoint].gain > 0 ? true : points[selectedPoint].gain < 0 ? false : null}>
            {points[selectedPoint].gain >= 0 ? '+' : ''}{points[selectedPoint].gain.toFixed(1)}dB
          </GainLabel>
          <InfoLabel>
            Q: {points[selectedPoint].q.toFixed(2)}
          </InfoLabel>
        </PointInfo>
      )}
    </Wrapper>
  )
}

export function PEQPanel() {
  return (
    <PEQWrapper>
      <PEQGraph />
      <HelpText>
        Drag points to adjust frequency and gain. Up to {MAX_PEQ_POINTS} parametric bands.
      </HelpText>
    </PEQWrapper>
  )
}
