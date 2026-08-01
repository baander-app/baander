import { useEffect, useRef, useState } from 'react'
import { audioService } from '@/features/player/services/audio-service'
import type { AudioSystemInfo, AnalysisData } from '@/features/player/services/audio-processor'
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/components/ui/card'
import styled, { css } from 'styled-components'

interface LiveState {
  system: AudioSystemInfo | null
  analysis: Pick<AnalysisData, 'spectralCentroid' | 'spectralRolloff' | 'spectralFlux' | 'spectralFlatness' | 'rms'> | null
}

const TwoColGrid = styled.div`
  display: grid;
  grid-template-columns: 1fr 1fr;
  column-gap: 1.5rem;
  row-gap: 0.75rem;
`

const ColHeader = styled.p`
  font-size: 11px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
  margin-bottom: 0.5rem;
`

const StatusList = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
  padding-left: 0.25rem;
`

const StatusItem = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
`

const Dot = styled.div<{ $on: boolean }>`
  height: 0.375rem;
  width: 0.375rem;
  border-radius: 9999px;
  flex-shrink: 0;

  ${(p) => p.$on
    ? css`background-color: #34d399; box-shadow: 0 0 4px rgba(52, 211, 153, 0.5);`
    : css`background-color: rgba(var(--color-muted-foreground-rgb, 128 128 128), 0.3);`
  }
`

const StatusLabel = styled.span`
  font-size: 11px;
  color: var(--color-muted-foreground);
`

const KvRow = styled.div`
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 1rem;
`

const KvLabel = styled.span`
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
  flex-shrink: 0;
`

const KvValue = styled.span<{ $mono?: boolean }>`
  font-size: 11px;
  color: var(--color-foreground);
  ${(p) => p.$mono && css`font-variant-numeric: tabular-nums;`}
`

const MiniBarTrack = styled.div`
  height: 0.25rem;
  width: 100%;
  border-radius: 9999px;
  background-color: var(--color-muted);
  overflow: hidden;
`

const MiniBarFill = styled.div<{ $color: string }>`
  height: 100%;
  border-radius: 9999px;
  transition: width 150ms;
  background-color: ${(p) => p.$color};
`

const HeaderRow = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
`

const HeaderStatus = styled.div`
  display: flex;
  align-items: center;
  gap: 0.375rem;
`

const StateText = styled.span<{ $color: string }>`
  font-size: 11px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: ${(p) => p.$color};
`

const MetricGroup = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
`

const MetricWithBar = styled.div`
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  margin-bottom: 0.25rem;
`

const NoProcessorText = styled.p`
  font-size: 11px;
  color: var(--color-muted-foreground);
`

const SectionGap = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
`

const MetricList = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
`

function StatusDotComponent({ on, label }: { on: boolean; label: string }) {
  return (
    <StatusItem>
      <Dot $on={on} />
      <StatusLabel>{label}</StatusLabel>
    </StatusItem>
  )
}

function KvRowComponent({ label, value, mono }: { label: string; value: string; mono?: boolean }) {
  return (
    <KvRow>
      <KvLabel>{label}</KvLabel>
      <KvValue $mono={mono}>{value}</KvValue>
    </KvRow>
  )
}

function MiniBarComponent({ value, max, color }: { value: number; max: number; color: string }) {
  const pct = Math.min(100, (value / max) * 100)
  return (
    <MiniBarTrack>
      <MiniBarFill $color={color} style={{ width: `${pct}%` }} />
    </MiniBarTrack>
  )
}

const stateColors: Record<string, string> = {
  running: '#34d399',
  suspended: '#fbbf24',
  closed: 'var(--color-destructive)',
}

export function AudioSystemPanel({ className }: { className?: string }) {
  const intervalRef = useRef<number | null>(null)

  const [live, setLive] = useState<LiveState>({
    system: null,
    analysis: null,
  })

  useEffect(() => {
    const poll = () => {
      const processor = audioService.getProcessor()
      if (!processor) return

      const system = processor.getSystemInfo()
      const data = processor.getAnalysisData()
      const analysis: LiveState['analysis'] = {
        spectralCentroid: data.spectralCentroid,
        spectralRolloff: data.spectralRolloff,
        spectralFlux: data.spectralFlux,
        spectralFlatness: data.spectralFlatness,
        rms: data.rms,
      }
      setLive({ system, analysis })
    }

    // Poll immediately, then every 250ms (no need for 40ms for system info)
    poll()
    intervalRef.current = window.setInterval(poll, 250)

    return () => {
      if (intervalRef.current) {
        clearInterval(intervalRef.current)
        intervalRef.current = null
      }
    }
  }, [])

  const sys = live.system
  if (!sys) {
    return (
      <Card className={className}>
        <CardHeader><CardTitle>Audio System</CardTitle></CardHeader>
        <CardContent>
          <NoProcessorText>No audio processor available</NoProcessorText>
        </CardContent>
      </Card>
    )
  }

  const centroidKHz = sys.sampleRate > 0
    ? (live.analysis?.spectralCentroid ?? 0) > 0
      ? `${((live.analysis?.spectralCentroid ?? 0) / 1000).toFixed(1)} kHz`
      : '—'
    : '—'

  const rolloffKHz = (live.analysis?.spectralRolloff ?? 0) > 0
    ? `${((live.analysis?.spectralRolloff ?? 0) / 1000).toFixed(1)} kHz`
    : '—'

  const flux = live.analysis?.spectralFlux ?? 0
  const flatness = live.analysis?.spectralFlatness ?? 0
  const rms = live.analysis?.rms ?? 0

  return (
    <Card className={className}>
      <CardHeader>
        <HeaderRow>
          <CardTitle>Audio System</CardTitle>
          <HeaderStatus>
            <Dot $on={sys.connected && sys.contextState === 'running'} />
            <StateText $color={stateColors[sys.contextState] ?? 'var(--color-muted-foreground)'}>
              {sys.contextState}
            </StateText>
          </HeaderStatus>
        </HeaderRow>
      </CardHeader>
      <CardContent>
        <TwoColGrid>
          {/* Left column: Pipeline status */}
          <SectionGap>
            <ColHeader>Pipeline</ColHeader>
            <StatusList>
              <StatusDotComponent on={sys.connected} label="Audio Source" />
              <StatusDotComponent on={sys.dspReady} label="WASM DSP" />
              <StatusDotComponent on={sys.wasmSpectrumReady} label="WASM Spectrum" />
              <StatusDotComponent on={sys.workerReady} label="Analysis Worker" />
              <StatusDotComponent on={sys.workletActive} label="LUFS Worklet" />
              <StatusDotComponent on={sys.filterCount === 10} label={`EQ Filters (${sys.filterCount}/10)`} />
              <StatusDotComponent on={sys.compressorActive} label="Compressor" />
            </StatusList>
          </SectionGap>

          {/* Right column: Metrics + Info */}
          <SectionGap>
            <MetricGroup>
              <ColHeader>Spectral</ColHeader>
              <MetricList>
                <KvRowComponent label="Centroid" value={centroidKHz} mono />
                <KvRowComponent label="Rolloff" value={rolloffKHz} mono />
                <div>
                  <MetricWithBar>
                    <KvLabel>Flux</KvLabel>
                    <KvValue $mono>{flux.toFixed(2)}</KvValue>
                  </MetricWithBar>
                  <MiniBarComponent value={flux} max={50} color="rgba(var(--color-primary-rgb, 0 0 0), 0.6)" />
                </div>
                <div>
                  <MetricWithBar>
                    <KvLabel>Flatness</KvLabel>
                    <KvValue $mono>{flatness.toFixed(3)}</KvValue>
                  </MetricWithBar>
                  <MiniBarComponent value={flatness} max={1} color="rgba(var(--color-primary-rgb, 0 0 0), 0.4)" />
                </div>
                <div>
                  <MetricWithBar>
                    <KvLabel>RMS</KvLabel>
                    <KvValue $mono>{(rms * 100).toFixed(1)}%</KvValue>
                  </MetricWithBar>
                  <MiniBarComponent value={rms} max={0.5} color="rgba(var(--color-primary-rgb, 0 0 0), 0.5)" />
                </div>
              </MetricList>
            </MetricGroup>

            <MetricGroup>
              <ColHeader>Context</ColHeader>
              <MetricList>
                <KvRowComponent label="Rate" value={`${sys.sampleRate} Hz`} mono />
                <KvRowComponent label="FFT" value={`${sys.fftSize}`} mono />
                {sys.baseLatency != null && (
                  <KvRowComponent label="Base Lat" value={`${(sys.baseLatency * 1000).toFixed(1)} ms`} mono />
                )}
                {sys.outputLatency != null && (
                  <KvRowComponent label="Out Lat" value={`${(sys.outputLatency * 1000).toFixed(1)} ms`} mono />
                )}
                <KvRowComponent label="Time" value={`${sys.currentTime.toFixed(1)}s`} mono />
                <KvRowComponent label="Mode" value={sys.passive ? 'Passive' : 'Active'} />
              </MetricList>
            </MetricGroup>
          </SectionGap>
        </TwoColGrid>
      </CardContent>
    </Card>
  )
}
