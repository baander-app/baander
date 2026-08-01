import styled from 'styled-components'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { useEqBandsStore } from '@/features/equalizer/stores/eq-bands-store'
import { VisualizerHost } from '@/features/visualizer/components/VisualizerHost'
import { isEngineMode } from '@/features/visualizer/types'
import { registerVisualizerRenderers, getCompactMode } from '@/features/visualizer/register-visualizer-renderers'
import { PlayerTrackInfo } from './PlayerTrackInfo'
import { PlayerProgress } from './PlayerProgress'
import { PlayerTransport } from './PlayerTransport'
import { PlayerVolume } from './PlayerVolume'
import { PlayerTimeLabel } from './PlayerTimeLabel'

const EmptyState = styled.div`
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0.75rem 1rem;
`

const EmptyText = styled.p`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const BarContainer = styled.div`
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
  padding: 0.5rem 0.75rem;
`

const VisualizerBg = styled.div`
  position: absolute;
  inset: 0;
  border-radius: var(--radius-md);
  overflow: hidden;
  pointer-events: none;
`

const ControlsRow = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
`

const VolumeSlot = styled.div`
  display: flex;
  width: 2rem;
  align-items: center;
  justify-content: flex-end;
`

// Module-level renderer registration (idempotent, runs once)
registerVisualizerRenderers()

export function PlayerBar() {
  const currentTrack = usePlayerStore((s) => s.currentTrack)
  const visualizerMode = useEqBandsStore((s) => s.visualizerMode)

  if (!currentTrack) {
    return (
      <EmptyState>
        <EmptyText>Nothing playing</EmptyText>
      </EmptyState>
    )
  }

  return (
    <BarContainer>
      {/* Mini visualizer background */}
      {isEngineMode(visualizerMode) && currentTrack && (
        <VisualizerBg>
          <VisualizerHost
            mode={getCompactMode(visualizerMode)}
            albumPublicId={currentTrack.albumPublicId}
            compact
            opacity={0.15}
          />
        </VisualizerBg>
      )}
      {/* Track info row */}
      <PlayerTrackInfo track={currentTrack} />

      {/* Progress bar */}
      <PlayerProgress />

      {/* Time + controls + volume */}
      <ControlsRow>
        <PlayerTimeLabel />
        <PlayerTransport />
        <VolumeSlot>
          <PlayerVolume />
        </VolumeSlot>
      </ControlsRow>
    </BarContainer>
  )
}
