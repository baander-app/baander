import { useEffect, useRef, useState } from 'react'
import type { PhaseAnalysis } from '@/features/player/services/audio-processor'
import { audioService } from '@/features/player/services/audio-service'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { EQ_BANDS, type BandConfig, type VisualizerMode, useEqBandsStore } from '../stores/eq-bands-store'
import { isEngineMode } from '@/features/visualizer/types'
import { VisualizerHost } from '@/features/visualizer/components/VisualizerHost'
import { registerVisualizerRenderers } from '@/features/visualizer/register-visualizer-renderers'
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/components/ui/card'
import { Button } from '@/shared/components/ui/button'
import { aggregateSpectrum, frequencyToPosition, getFrequencyRange, frameSmoothing } from '@/features/visualizer/utils/spectrum'
import styled from 'styled-components'

interface DisplayData {
  leftChannel: number
  rightChannel: number
  lufs: number
  loudnessAvailable: boolean
  sampleRate: number | null
  peakFrequency: number
  rms: number
  frequencyBars: number[]
  normalizationGainDb: number
  phase: PhaseAnalysis | null
  trackId: string | null
}

interface EqualizerAnalyzerProps {
  bands: BandConfig[]
  masterGain: number
  normalizationEnabled: boolean
}

// --- Spectrum Visualizer ---

const SpectrumWrapper = styled.div`
  position: relative;
  display: flex;
  height: 100%;
  align-items: flex-end;
  gap: 1px;
  padding: 0;
`

const SpectrumBar = styled.div`
  flex: 1;
  border-top-left-radius: 2px;
  border-top-right-radius: 2px;
  background-color: color-mix(in srgb, var(--color-accent-text) 60%, transparent);
  transition: height 100ms ease-out;
`

const CurveSvg = styled.svg`
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  pointer-events: none;
`

function SpectrumVisualizer({ bars, bands, sampleRate }: { bars: number[]; bands: BandConfig[]; sampleRate: number | null }) {
  const range = sampleRate ? getFrequencyRange(sampleRate) : null
  const curvePoints = sampleRate && range ? bands.flatMap((band, i) => {
    const frequency = EQ_BANDS[i].frequency
    if (frequency < range.min || frequency > range.max) return []
    return [{ x: frequencyToPosition(frequency, sampleRate) * 100, y: 50 - (band.gain / 12) * 40 }]
  }) : []

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
    <SpectrumWrapper role="img" aria-label="Input spectrum with configured EQ response">
      {bars.map((height, i) => {
        const pct = Math.max(0, Math.min(100, height))
        return (
          <SpectrumBar
            key={i}
            style={{ height: `${pct}%` }}
          />
        )
      })}
      {curvePath && (
        <CurveSvg
          viewBox="0 0 100 100"
          preserveAspectRatio="none"
        >
          <path
            d={curvePath}
            stroke="var(--color-accent-text)"
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

const MeterFill = styled.div`
  height: 100%;
  border-radius: 9999px;
  transition: width 100ms ease-out;
  background-color: var(--color-accent-text);
`

const MeterValue = styled.span`
  width: 6rem;
  font-family: var(--font-mono);
  text-align: right;
  font-variant-numeric: tabular-nums;
  font-size: 11px;
  color: var(--color-muted-foreground);
`

function MeterBar({ label, level }: { label: string; level: number }) {
  const decibels = level > 0 ? 20 * Math.log10(level / 100) : -Infinity
  const bounded = Math.max(-60, Math.min(0, decibels))
  const value = Number.isFinite(decibels) ? decibels.toFixed(1) : '−∞'
  return (
    <MeterWrapper>
      <MeterLabel>{label}</MeterLabel>
      <MeterTrack
        role="meter"
        aria-label={label === 'L' ? 'Left input RMS' : 'Right input RMS'}
        aria-valuemin={-60}
        aria-valuemax={0}
        aria-valuenow={bounded}
        aria-valuetext={`${value} dBFS RMS`}
      >
        <MeterFill style={{ width: `${((bounded + 60) / 60) * 100}%` }} />
      </MeterTrack>
      <MeterValue>{value} dBFS</MeterValue>
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
  min-height: 0;
  border-radius: var(--radius-md);
  background-color: var(--color-muted);
  overflow: hidden;

  svg {
    color: var(--color-accent-text);
  }
`

const PhaseReadout = styled.div`
  text-align: center;
  font-size: 11px;
  color: var(--color-muted-foreground);
  font-variant-numeric: tabular-nums;
`

function PhaseVisualizer({ phase }: { phase: PhaseAnalysis | null }) {
  const points: string[] = []
  if (phase) {
    for (let i = 0; i < phase.samples.length; i += 2) {
      const left = Math.max(-1, Math.min(1, phase.samples[i]))
      const right = Math.max(-1, Math.min(1, phase.samples[i + 1]))
      points.push(`${(50 + (left - right) * 22.5).toFixed(2)},${(50 - (left + right) * 22.5).toFixed(2)}`)
    }
  }
  return (
    <PhaseWrapper>
      <PhaseCanvas>
        <svg width="100%" height="100%" viewBox="0 0 100 100" role="img" aria-label="Stereo phase goniometer">
          <line x1="50" y1="5" x2="50" y2="95" stroke="currentColor" strokeWidth="0.5" opacity={0.2} />
          <line x1="5" y1="50" x2="95" y2="50" stroke="currentColor" strokeWidth="0.5" opacity={0.2} />
          <text x="52" y="10" fill="currentColor" fontSize="5">In phase</text>
          <text x="65" y="48" fill="currentColor" fontSize="5">Opposite phase</text>
          {phase && <polyline aria-label="Captured stereo samples" points={points.join(' ')} stroke="currentColor" strokeWidth="0.75" fill="none" opacity={0.8} />}
        </svg>
      </PhaseCanvas>
      <PhaseReadout>
        {phase ? `Correlation: ${phase.correlation === null ? 'unavailable' : phase.correlation.toFixed(2)}` : 'Phase unavailable'}
      </PhaseReadout>
    </PhaseWrapper>
  )
}

// --- Analyzer layout styled components ---

const HeaderRow = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 1rem;
`

const ModeButtons = styled.div`
  display: flex;
  align-items: center;
  gap: 0.25rem;
  flex-wrap: wrap;
`

const VisualizerArea = styled.div`
  position: relative;
  height: 12rem;
  border-radius: var(--radius-lg);
  background-color: var(--color-muted);
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
  flex-wrap: wrap;
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
  color: var(--color-foreground);
`

const VisualizationNote = styled.p`
  margin: 0.5rem 0 0;
  font-size: 0.6875rem;
  color: var(--color-muted-foreground);
`

function emptyDisplayData(sampleRate: number | null = null): DisplayData {
  return {
    leftChannel: 0, rightChannel: 0, lufs: 0, loudnessAvailable: false, sampleRate,
    peakFrequency: 0, rms: 0, frequencyBars: new Array(64).fill(0), phase: null,
    normalizationGainDb: 0, trackId: null,
  }
}

export function EqualizerAnalyzer({ bands, masterGain, normalizationEnabled }: EqualizerAnalyzerProps) {
  const smoothedBarsRef = useRef(new Float32Array(64))
  const isPlaying = usePlayerStore((s) => s.isPlaying)
  const trackId = usePlayerStore((s) => s.currentTrack?.publicId ?? null)
  const albumPublicId = usePlayerStore((s) => s.currentTrack?.albumPublicId)
  const visualizerMode = useEqBandsStore((s) => s.visualizerMode)
  const setVisualizerMode = useEqBandsStore((s) => s.setVisualizerMode)
  const [displayData, setDisplayData] = useState<DisplayData>(() => emptyDisplayData())

  useEffect(() => usePlayerStore.subscribe((state, previous) => {
    if ((previous.isPlaying && !state.isPlaying) || state.currentTrack?.publicId !== previous.currentTrack?.publicId) {
      setDisplayData((data) => emptyDisplayData(data.sampleRate))
    }
  }), [])

  useEffect(() => {
    smoothedBarsRef.current.fill(0)
    if (!isPlaying || !trackId) return
    let previousTime = performance.now()
    const interval = window.setInterval(() => {
      const processor = audioService.getProcessor()
      const data = processor?.getAnalysisData()
      if (!processor || !data) {
        setDisplayData(emptyDisplayData())
        return
      }
      const now = performance.now()
      const alpha = frameSmoothing(0.35, now - previousTime)
      previousTime = now
      const bars = Array.from(aggregateSpectrum(data), (peak, index) => {
        const previous = smoothedBarsRef.current[index]
        const next = previous + (peak * 100 - previous) * alpha
        smoothedBarsRef.current[index] = next
        return next
      })
      setDisplayData({
        leftChannel: data.leftChannel, rightChannel: data.rightChannel,
        lufs: data.lufs, loudnessAvailable: data.loudnessAvailable, sampleRate: data.sampleRate,
        peakFrequency: data.peakFrequency, rms: data.rms, frequencyBars: bars,
        phase: data.phase, normalizationGainDb: processor.getNormalizationGainDb(), trackId,
      })
    }, 40)
    return () => window.clearInterval(interval)
  }, [isPlaying, trackId])

  const display = isPlaying && displayData.trackId === trackId
    ? displayData
    : emptyDisplayData(displayData.sampleRate)
  const currentGain = normalizationEnabled ? display.normalizationGainDb : 0
  const totalGain = (masterGain + currentGain).toFixed(1)
  const formattedPeakFreq = display.peakFrequency > 1000
    ? `${(display.peakFrequency / 1000).toFixed(1)} kHz`
    : `${Math.round(display.peakFrequency)} Hz`
  const upperFrequency = display.sampleRate ? getFrequencyRange(display.sampleRate).max : null

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
    <Card role="region" aria-label="Input analyzer">
      <CardHeader>
        <HeaderRow>
          <CardTitle>Input analyzer</CardTitle>
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
            <SpectrumVisualizer bars={display.frequencyBars} bands={bands} sampleRate={display.sampleRate} />
          )}
          {visualizerMode === 'meters' && (
            <MetersContent>
              <MeterBar label="L" level={display.leftChannel} />
              <MeterBar label="R" level={display.rightChannel} />
              <PeakRow>
                <PeakLabel>Peak</PeakLabel>
                <PeakValue>{display.peakFrequency > 0 ? formattedPeakFreq : '—'}</PeakValue>
              </PeakRow>
            </MetersContent>
          )}
          {visualizerMode === 'phase' && (
            <PhaseVisualizer phase={display.phase} />
          )}
          {isEngineMode(visualizerMode) && (
            <VisualizerHost
              mode={visualizerMode}
              albumPublicId={albumPublicId}
            />
          )}
        </VisualizerArea>

        <VisualizationNote>
          Input before EQ and gain. {visualizerMode === 'spectrum' && upperFrequency
            ? '20 Hz–' + (upperFrequency / 1000).toFixed(1) + ' kHz · spectrum −100–0 dB · configured EQ curve ±12 dB'
            : visualizerMode === 'meters' ? 'Meters show RMS levels in dBFS.'
              : visualizerMode === 'phase' ? 'Phase uses captured stereo samples.' : ''}
        </VisualizationNote>

        {/* Readouts */}
        <Readouts>
          <ReadoutItem>
            <span>Momentary LUFS</span>
            <ReadoutValue>
              {display.loudnessAvailable ? display.lufs.toFixed(1) : 'Unavailable'}
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
