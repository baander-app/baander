import styled from 'styled-components'
import { Square, Radio, AlertTriangle, Volume2, VolumeX } from 'lucide-react'
import { useRadioStore } from '@/features/radio/stores/radio-store'
import { useRadioPlayback } from '@/features/radio/hooks/use-radio-playback'
import { usePlayerStore } from '@/features/player/stores/player-store'

const BarContainer = styled.div`
  border-top: 1px solid var(--color-border);
  background-color: var(--color-background);
`

const BarInner = styled.div`
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.5rem 1rem;
`

const StationInfo = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  min-width: 0;
  flex: 1;
`

const LogoContainer = styled.div`
  display: flex;
  height: 2.25rem;
  width: 2.25rem;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-md);
  background-color: var(--color-muted);
`

const StationLogo = styled.img`
  height: 2.25rem;
  width: 2.25rem;
  border-radius: var(--radius-md);
  object-fit: cover;
`

const StationText = styled.div`
  min-width: 0;
`

const StationNameRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.375rem;
`

const StationName = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.875rem;
  font-weight: 500;
`

const LiveBadge = styled.span`
  flex-shrink: 0;
  border-radius: 9999px;
  background-color: color-mix(in srgb, #ef4444 10%, transparent);
  padding: 0.125rem 0.375rem;
  font-size: 10px;
  font-weight: 500;
  color: #ef4444;
`

const StationMeta = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const FailureIndicator = styled.div`
  display: flex;
  align-items: center;
  gap: 0.25rem;
  font-size: 0.75rem;
  color: var(--color-destructive);
`

const FallbackText = styled.span`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const StopButton = styled.button`
  display: flex;
  height: 2rem;
  width: 2rem;
  align-items: center;
  justify-content: center;
  border-radius: 9999px;
  border: none;
  background: none;
  cursor: pointer;
  transition: background-color 0.15s;

  &:hover {
    background-color: var(--color-accent);
  }
`

const VolumeGroup = styled.div`
  display: flex;
  align-items: center;
  gap: 0.25rem;
`

const MuteButton = styled.button`
  display: flex;
  height: 2rem;
  width: 2rem;
  align-items: center;
  justify-content: center;
  border-radius: 9999px;
  border: none;
  background: none;
  cursor: pointer;
  transition: background-color 0.15s;

  &:hover {
    background-color: var(--color-accent);
  }
`

const VolumeSlider = styled.input`
  height: 0.25rem;
  width: 5rem;
  cursor: pointer;
  appearance: none;
  border-radius: 9999px;
  background-color: var(--color-muted);
  accent-color: var(--color-foreground);
`

export function RadioPlayerBar() {
  const activeStation = useRadioStore((s) => s.activeStation)
  const isPlaying = useRadioStore((s) => s.isPlaying)
  const allStreamsFailed = useRadioStore((s) => s.allStreamsFailed)
  const streamFallbackIndex = useRadioStore((s) => s.streamFallbackIndex)
  const { stop } = useRadioPlayback()

  const volume = usePlayerStore((s) => s.volume)
  const muted = usePlayerStore((s) => s.muted)
  const setVolume = usePlayerStore((s) => s.setVolume)
  const toggleMute = usePlayerStore((s) => s.toggleMute)

  if (!activeStation) return null

  return (
    <BarContainer>
      <BarInner>
        {/* Station info */}
        <StationInfo>
          <LogoContainer>
            {activeStation.logo ? (
              <StationLogo src={activeStation.logo} alt="" />
            ) : (
              <Radio size={16} style={{ color: 'var(--color-muted-foreground)' }} />
            )}
          </LogoContainer>

          <StationText>
            <StationNameRow>
              <StationName>{activeStation.name}</StationName>
              {isPlaying && !allStreamsFailed && (
                <LiveBadge>LIVE</LiveBadge>
              )}
            </StationNameRow>
            <StationMeta>
              {activeStation.country}
              {activeStation.genres.length > 0 ? ` · ${activeStation.genres.slice(0, 2).join(', ')}` : ''}
            </StationMeta>
          </StationText>
        </StationInfo>

        {/* Stream failure indicator */}
        {allStreamsFailed && (
          <FailureIndicator>
            <AlertTriangle size={14} />
            <span>Unavailable</span>
          </FailureIndicator>
        )}
        {!allStreamsFailed && streamFallbackIndex > 0 && (
          <FallbackText>Trying stream {streamFallbackIndex + 1}...</FallbackText>
        )}

        {/* Stop button */}
        <StopButton
          onClick={stop}
          aria-label="Stop radio"
        >
          <Square size={16} fill="currentColor" />
        </StopButton>

        {/* Volume */}
        <VolumeGroup>
          <MuteButton
            onClick={toggleMute}
            aria-label={muted ? 'Unmute' : 'Mute'}
          >
            {muted || volume === 0 ? <VolumeX size={16} /> : <Volume2 size={16} />}
          </MuteButton>
          <VolumeSlider
            type="range"
            min="0"
            max="100"
            value={muted ? 0 : volume}
            onChange={(e) => setVolume(Number(e.target.value))}
            aria-label="Volume"
          />
        </VolumeGroup>
      </BarInner>
    </BarContainer>
  )
}
