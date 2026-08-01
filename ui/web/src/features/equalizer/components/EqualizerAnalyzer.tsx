import { useEffect, useMemo, useRef, useState } from 'react'
import { audioService } from '@/features/player/services/audio-service'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { EQ_BANDS, type BandConfig, type VisualizerMode, useEqBandsStore } from '../stores/eq-bands-store'
import { isEngineMode } from '@/features/visualizer/types'
import { VisualizerHost } from '@/features/visualizer/components/VisualizerHost'
import { registerVisualizerRenderers, getCompactMode } from '@/features/visualizer/register-visualizer-renderers'
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/components/ui/card'
import { Button } from '@/shared/components/ui/button'
import styled from 'styled-components'

interface DisplayData {
  leftChannel: number
  rightChannel: number
  lufs: number
  peakFrequency: number
  rms: number
  frequencyBars: number[]
}

interface EqualizerAnalyzerProps {
  bands: BandConfig[]
  masterGain: number
  normalizationEnabled: boolean
  targetLufs: number
}

// --- Spectrum Visualizer ---

const SpectrumWrapper = styled.div`
  position: relative;
  display: flex;
  height: 100%;
  align-items: flex-end;
  gap: 1px;
  padding: 0 0.25rem;

  & > div:first-child {
    /* bar container fills width */
  }
`

const SpectrumBar = styled.div`
  flex: 1;
  border-top-left-radius: 2px;
  border-top-right-radius: 2px;
  background-color: rgba(var(--color-primary-rgb, 0 0 0), 0.6);
  transition: height 100ms ease-out;
`

const CurveSvg = styled.svg`
  position: absolute;
  inset: 0;
  pointer-events: none;
`

function SpectrumVisualizer({ bars, bands, showCurve }: { bars: number[]; bands: BandConfig[]; showCurve: boolean }) {
  // Map EQ bands to SVG path: X = log frequency position, Y = gain (-12 to +12)
  const curvePoints = bands.map((band, i) => {
    const freq = EQ_BANDS[i].frequency
    const x = (Math.log10(freq / 20) / Math.log10(80000 / 20)) * 100
    const y = 50 - (band.gain / 12) * 40 // map -12..+12 to 90..10
    return { x, y }
  })

  let curvePath = ''
  if (curvePoints.length > 0) {
    curvePath = `M ${curvePoints[0].x},${curvePoints[0].y}`
    for (let i = 1; i < curvePoints.length; i++) {
      const prev = curvePoints[i - 1]
      const curr = curvePoints[i]
      const cpx = (prev.x + curr.x) / 2
      curvePath += ` C ${cpx},${prev.y} ${cpx},${curr.y} ${curr.x},${curr.y}`
    }
  }

  return (
    <SpectrumWrapper>
      {bars.map((height, i) => {
        const pct = Math.max(1, height)
        return (
          <SpectrumBar
            key={i}
            style={{ height: `${pct}%` }}
          />
        )
      })}
      {showCurve && curvePath && (
        <CurveSvg
          viewBox="0 0 100 100"
          preserveAspectRatio="none"
        >
          <path
            d={curvePath}
            stroke="hsl(var(--primary))"
            strokeWidth="1.5"
            fill="none"
            opacity={0.8}
            vectorEffect="non-scaling-stroke"
          />
          {/* Zero line */}
          <line x1="0" y1="50" x2="100" y2="50" stroke="currentColor" strokeWidth="0.5" opacity={0.2} vectorEffect="non-scaling-stroke" />
        </CurveSvg>
      )}
    </SpectrumWrapper>
  )
}

// --- Meter Bar ---

const MeterWrapper = styled.div`
  display: flex;
  align-items: center;
  gap: 0.75rem;
`

const MeterLabel = styled.span`
  width: 0.75rem;
  text-align: right;
  font-size: 11px;
  font-weight: 500;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const MeterTrack = styled.div`
  flex: 1;
  height: 0.375rem;
  border-radius: 9999px;
  background-color: var(--color-muted);
  overflow: hidden;
`

const MeterFill = styled.div<{ $level: number }>`
  height: 100%;
  border-radius: 9999px;
  transition: width 100ms ease-out;
  background-color: ${(p) => {
    if (p.$level > 80) return 'var(--color-destructive)'
    if (p.$level > 60) return 'rgba(var(--color-primary-rgb, 0 0 0), 0.8)'
    return 'rgba(var(--color-primary-rgb, 0 0 0), 0.5)'
  }};
`

const MeterValue = styled.span`
  width: 2rem;
  text-align: right;
  font-variant-numeric: tabular-nums;
  font-size: 11px;
  color: var(--color-muted-foreground);
`

function MeterBar({ label, level }: { label: string; level: number }) {
  return (
    <MeterWrapper>
      <MeterLabel>{label}</MeterLabel>
      <MeterTrack>
        <MeterFill $level={level} style={{ width: `${Math.min(100, level)}%` }} />
      </MeterTrack>
      <MeterValue>{Math.round(level)}</MeterValue>
    </MeterWrapper>
  )
}

// --- Phase Visualizer ---

const PhaseWrapper = styled.div`
  display: flex;
  height: 100%;
  flex-direction: column;
  gap: 0.5rem;
  padding: 0.5rem;
`

const PhaseCanvas = styled.div`
  flex: 1;
  border-radius: var(--radius-md);
  background-color: rgba(var(--color-muted-rgb, 128 128 128), 0.5);
  overflow: hidden;

  svg {
    color: var(--color-primary);
  }
`

function PhaseVisualizer({ path }: { path: string }) {
  return (
    <PhaseWrapper>
      <PhaseCanvas>
        <svg width="100%" height="100%" viewBox="0 0 200 100" aria-hidden="true">
          <path d={path} stroke="currentColor" strokeWidth="1.5" fill="none" opacity={0.6} />
        </svg>
      </PhaseCanvas>
    </PhaseWrapper>
  )
}

// --- Analyzer layout styled components ---

const HeaderRow = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
`

const ModeButtons = styled.div`
  display: flex;
  align-items: center;
  gap: 0.25rem;
`

const VisualizerArea = styled.div`
  position: relative;
  height: 12rem;
  border-radius: var(--radius-lg);
  background-color: rgba(var(--color-muted-rgb, 128 128 128), 0.3);
  overflow: hidden;
`

const MetersContent = styled.div`
  display: flex;
  height: 100%;
  flex-direction: column;
  justify-content: center;
  gap: 0.75rem;
  padding: 1rem;
`

const PeakRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-top: 0.5rem;
`

const PeakLabel = styled.span`
  font-size: 11px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const PeakValue = styled.span`
  font-variant-numeric: tabular-nums;
  font-size: 0.875rem;
`

const Readouts = styled.div`
  margin-top: 0.75rem;
  display: flex;
  align-items: center;
  gap: 1.5rem;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const ReadoutItem = styled.div`
  display: flex;
  align-items: center;
  gap: 0.375rem;
`

const ReadoutValue = styled.span`
  font-variant-numeric: tabular-nums;
  font-weight: 500;
  color: var(--color-foreground)
`

export function EqualizerAnalyzer({ bands, masterGain, normalizationEnabled, targetLufs }: EqualizerAnalyzerProps) {
  const intervalRef = useRef<number | null>(null)
  const smoothedBarsRef = useRef(new Float32Array(64))

  const isPlaying = usePlayerStore((s) => s.isPlaying)
  const albumPublicId = usePlayerStore((s) => s.currentTrack?.albumPublicId)
  const visualizerMode = useEqBandsStore((s) => s.visualizerMode)
  const setVisualizerMode = useEqBandsStore((s) => s.setVisualizerMode)

  const [displayData, setDisplayData] = useState<DisplayData>({
    leftChannel: 0,
    rightChannel: 0,
    lufs: -30,
    peakFrequency: 0,
    rms: 0,
    frequencyBars: new Array(64).fill(0),
  })

  // Poll analysis data from processor
  useEffect(() => {
    if (!isPlaying) {
      if (intervalRef.current) {
        clearInterval(intervalRef.current)
        intervalRef.current = null
      }
      return
    }

    intervalRef.current = window.setInterval(() => {
      try {
        const processor = audioService.getProcessor()
        if (!processor) return

        const data = processor.getAnalysisData()
        if (!data) return

        const bars: number[] = []
        const barCount = 64
        const alpha = 0.35
        const decay = 0.92

        if (data.frequencyData && data.frequencyData.length > 0) {
          for (let i = 0; i < barCount; i++) {
            const dataIndex = Math.floor((i / barCount) * data.frequencyData.length)
            const target = (data.frequencyData[dataIndex] / 255) * 100
            const prev = smoothedBarsRef.current[i] || 0
            smoothedBarsRef.current[i] = prev * (1 - alpha) + target * alpha
            bars.push(smoothedBarsRef.current[i])
          }
        } else {
          for (let i = 0; i < barCount; i++) {
            smoothedBarsRef.current[i] = (smoothedBarsRef.current[i] || 0) * decay
            bars.push(smoothedBarsRef.current[i])
          }
        }

        setDisplayData({
          leftChannel: data.leftChannel,
          rightChannel: data.rightChannel,
          lufs: data.lufs,
          peakFrequency: data.peakFrequency,
          rms: data.rms,
          frequencyBars: bars,
        })

        if (normalizationEnabled && data.lufs !== 0 && !isNaN(data.lufs)) {
          processor.applyVolumeNormalization(targetLufs, data.lufs)
        }
      } catch (error) {
        console.error('[Equalizer] analysis update error:', error)
      }
    }, 40)

    return () => {
      if (intervalRef.current) {
        clearInterval(intervalRef.current)
        intervalRef.current = null
      }
    }
  }, [isPlaying, normalizationEnabled, targetLufs])

  const currentGain = normalizationEnabled ? targetLufs - displayData.lufs : 0
  const totalGain = (masterGain + currentGain).toFixed(1)
  const formattedPeakFreq = displayData.peakFrequency > 1000
    ? `${(displayData.peakFrequency / 1000).toFixed(1)}K`
    : `${Math.round(displayData.peakFrequency)}`

  const phasePath = useMemo(() => {
    const points = 24
    const pf = displayData.peakFrequency || 0
    const rms = displayData.rms || 0.1
    let path = 'M 0,50'
    for (let i = 0; i < points; i++) {
      const x = (i / (points - 1)) * 200
      const phaseCorrelation = Math.sin((pf / 1000) * i * 0.1) * 30
      const y = Math.max(10, Math.min(90, 50 + phaseCorrelation * rms))
      path += ` L ${x},${y}`
    }
    return path
  }, [displayData.peakFrequency, displayData.rms])

  // Register renderers once (idempotent)
  registerVisualizerRenderers()

  const visualizerModes: { value: VisualizerMode; label: string }[] = [
    { value: 'spectrum', label: 'Spectrum' },
    { value: 'meters', label: 'Meters' },
    { value: 'phase', label: 'Phase' },
    { value: 'enhanced-spectrum', label: 'Enhanced' },
    { value: 'circular', label: 'Circular' },
    { value: 'spectrogram', label: 'Spectrogram' },
    { value: 'particles', label: 'Particles' },
  ]

  return (
    <Card>
      <CardHeader>
        <HeaderRow>
          <CardTitle>Analyzer</CardTitle>
          <ModeButtons>
            {visualizerModes.map((mode) => (
              <Button
                key={mode.value}
                variant={visualizerMode === mode.value ? 'secondary' : 'ghost'}
                size="xs"
                onClick={() => setVisualizerMode(mode.value)}
                aria-pressed={visualizerMode === mode.value}
              >
                {mode.label}
              </Button>
            ))}
          </ModeButtons>
        </HeaderRow>
      </CardHeader>
      <CardContent>
        {/* Visualizer area */}
        <VisualizerArea>
          {visualizerMode === 'spectrum' && (
            <SpectrumVisualizer bars={displayData.frequencyBars} bands={bands} showCurve={visualizerMode === 'spectrum'} />
          )}
          {visualizerMode === 'meters' && (
            <MetersContent>
              <MeterBar label="L" level={displayData.leftChannel} />
              <MeterBar label="R" level={displayData.rightChannel} />
              <PeakRow>
                <PeakLabel>Peak</PeakLabel>
                <PeakValue>{formattedPeakFreq} Hz</PeakValue>
              </PeakRow>
            </MetersContent>
          )}
          {visualizerMode === 'phase' && (
            <PhaseVisualizer path={phasePath} />
          )}
          {isEngineMode(visualizerMode) && (
            <VisualizerHost
              mode={getCompactMode(visualizerMode)}
              albumPublicId={albumPublicId}
              compact
            />
          )}
        </VisualizerArea>

        {/* Readouts */}
        <Readouts>
          <ReadoutItem>
            <span>LUFS</span>
            <ReadoutValue>
              {(displayData.lufs ?? -30).toFixed(1)}
            </ReadoutValue>
          </ReadoutItem>
          <ReadoutItem>
            <span>Gain</span>
            <ReadoutValue>
              {masterGain >= 0 ? '+' : ''}{masterGain.toFixed(1)} dB
            </ReadoutValue>
          </ReadoutItem>
          <ReadoutItem>
            <span>Total</span>
            <ReadoutValue>
              {totalGain} dB
            </ReadoutValue>
          </ReadoutItem>
        </Readouts>
      </CardContent>
    </Card>
  )
}
