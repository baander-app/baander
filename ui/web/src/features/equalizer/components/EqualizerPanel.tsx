import { useState } from 'react'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { EQ_BANDS, EQ_PRESETS, type EqPresetName, useEqBandsStore } from '../stores/eq-bands-store'
import { useEqProcessingStore } from '../stores/eq-processing-store'
import { AudioSystemPanel } from './AudioSystemPanel'
import { ProcessingPanel } from './ProcessingPanel'
import { ComparePanel } from './ComparePanel'
import { PEQPanel } from './PEQPanel'
import { ProfileSelector } from './ProfileSelector'
import { EqualizerAnalyzer } from './EqualizerAnalyzer'
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/components/ui/card'
import { Button } from '@/shared/components/ui/button'
import { Slider } from '@/shared/components/ui/slider'
import styled from 'styled-components'

// --- Main Panel Styled Components ---

const Root = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
`

const TopGrid = styled.div`
  display: grid;
  grid-template-columns: 1fr 280px 200px;
  gap: 1.5rem;
`

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

const PresetGrid = styled.div`
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 0.375rem;
`

const EqBandRow = styled.div`
  display: flex;
  gap: 1rem;
`

const EqBandCol = styled.div`
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
`

const BandLabel = styled.span`
  font-size: 11px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const BandSliderWrap = styled.div`
  display: flex;
  height: 10rem;
  align-items: center;
`

const BandGain = styled.span<{ $gain: number }>`
  font-variant-numeric: tabular-nums;
  font-size: 11px;
  color: ${(p) => {
    if (p.$gain > 0) return 'var(--color-primary)'
    if (p.$gain < 0) return 'var(--color-muted-foreground)'
    return 'var(--color-foreground)'
  }};
`

const BottomGrid = styled.div`
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1.5rem;
`

const VolumeLabel = styled.span`
  font-variant-numeric: tabular-nums;
  font-size: 0.875rem;
`

const VolumeRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.75rem;
`

const InfoButton = styled(Button)`
  color: var(--color-muted-foreground);
`

// --- Main Component ---

export function EqualizerPanel({ className }: { className?: string }) {
  const [peqMode, setPeqMode] = useState(false)

  const isMuted = usePlayerStore((s) => s.muted)
  const volume = usePlayerStore((s) => s.volume)
  const setVolume = usePlayerStore((s) => s.setVolume)
  const toggleMute = usePlayerStore((s) => s.toggleMute)

  const bands = useEqBandsStore((s) => s.bands)
  const preset = useEqBandsStore((s) => s.preset)
  const setBandGain = useEqBandsStore((s) => s.setBandGain)
  const setPreset = useEqBandsStore((s) => s.setPreset)
  const showSystemPanel = useEqBandsStore((s) => s.showSystemPanel)
  const toggleSystemPanel = useEqBandsStore((s) => s.toggleSystemPanel)

  const masterGain = useEqProcessingStore((s) => s.masterGain)
  const normalizationEnabled = useEqProcessingStore((s) => s.normalizationEnabled)
  const targetLufs = useEqProcessingStore((s) => s.targetLufs)

  return (
    <Root className={className}>
      {/* Top row: Visualizer + Presets + Profiles */}
      <TopGrid>
        {/* Analyzer (owns its 25fps polling + display state) */}
        <EqualizerAnalyzer
          bands={bands}
          masterGain={masterGain}
          normalizationEnabled={normalizationEnabled}
          targetLufs={targetLufs}
        />

        {/* Presets */}
        <Card>
          <CardHeader>
            <CardTitle>Presets</CardTitle>
          </CardHeader>
          <CardContent>
            <PresetGrid>
              {(Object.keys(EQ_PRESETS) as EqPresetName[]).map((p) => (
                <Button
                  key={p}
                  variant={preset === p ? 'secondary' : 'ghost'}
                  size="xs"
                  onClick={() => setPreset(p)}
                  aria-pressed={preset === p}
                  style={{ justifyContent: 'flex-start' }}
                >
                  {p}
                </Button>
              ))}
            </PresetGrid>
          </CardContent>
        </Card>

        {/* Device Profiles */}
        <ProfileSelector />
      </TopGrid>

      {/* 10-Band EQ */}
      <Card>
        <CardHeader>
          <HeaderRow>
            <CardTitle>Equalizer</CardTitle>
            <ModeButtons>
              <Button
                variant={!peqMode ? 'secondary' : 'ghost'}
                size="xs"
                onClick={() => setPeqMode(false)}
                aria-pressed={!peqMode}
              >
                Simple
              </Button>
              <Button
                variant={peqMode ? 'secondary' : 'ghost'}
                size="xs"
                onClick={() => setPeqMode(true)}
                aria-pressed={peqMode}
              >
                Parametric
              </Button>
            </ModeButtons>
          </HeaderRow>
        </CardHeader>
        <CardContent>
          {peqMode ? (
            <PEQPanel />
          ) : (
          <EqBandRow>
            {EQ_BANDS.map((band, index) => (
              <EqBandCol key={band.frequency}>
                <BandLabel>
                  {band.label}
                </BandLabel>
                <BandSliderWrap>
                  <Slider
                    orientation="vertical"
                    min={-12}
                    max={12}
                    step={0.5}
                    value={[bands[index].gain]}
                    onValueChange={([val]) => setBandGain(index, val)}
                    aria-label={`${band.label}Hz equalizer band`}
                    style={{ height: '100%' }}
                  />
                </BandSliderWrap>
                <BandGain $gain={bands[index].gain}>
                  {bands[index].gain >= 0 ? '+' : ''}{bands[index].gain.toFixed(1)}
                </BandGain>
              </EqBandCol>
            ))}
          </EqBandRow>
          )}
        </CardContent>
      </Card>

      {/* A/B Comparison */}
      <Card>
        <CardHeader>
          <CardTitle>A/B Compare</CardTitle>
        </CardHeader>
        <CardContent>
          <ComparePanel />
        </CardContent>
      </Card>

      {/* Bottom row: Volume + Processing */}
      <BottomGrid>
        {/* Volume */}
        <Card>
          <CardHeader>
            <HeaderRow>
              <CardTitle>Volume</CardTitle>
              <VolumeLabel>{volume}%</VolumeLabel>
            </HeaderRow>
          </CardHeader>
          <CardContent>
            <VolumeRow>
              <Button
                variant={isMuted ? 'destructive' : 'ghost'}
                size="icon-sm"
                onClick={toggleMute}
                aria-label={isMuted ? 'Unmute' : 'Mute'}
              >
                {isMuted ? (
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
                    <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5" />
                    <line x1="23" y1="9" x2="17" y2="15" />
                    <line x1="17" y1="9" x2="23" y2="15" />
                  </svg>
                ) : (
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
                    <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5" />
                    <path d="M19.07 4.93a10 10 0 0 1 0 14.14" />
                    <path d="M15.54 8.46a5 5 0 0 1 0 7.07" />
                  </svg>
                )}
              </Button>
              <Slider
                min={0}
                max={100}
                step={1}
                value={[volume]}
                onValueChange={([val]) => setVolume(val)}
                aria-label="Volume"
                style={{ flex: 1 }}
              />
            </VolumeRow>
          </CardContent>
        </Card>

        {/* Processing */}
        <Card>
          <CardHeader>
            <HeaderRow>
              <CardTitle>Processing</CardTitle>
              <InfoButton
                variant="ghost"
                size="xs"
                onClick={toggleSystemPanel}
                aria-pressed={showSystemPanel}
                aria-label="Toggle audio system info"
              >
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
                  <circle cx="12" cy="12" r="10" />
                  <line x1="12" y1="16" x2="12" y2="12" />
                  <line x1="12" y1="8" x2="12.01" y2="8" />
                </svg>
              </InfoButton>
            </HeaderRow>
          </CardHeader>
          <CardContent>
            <ProcessingPanel />
          </CardContent>
        </Card>
      </BottomGrid>

      {/* Audio System (toggleable) */}
      {showSystemPanel && <AudioSystemPanel />}
    </Root>
  )
}
