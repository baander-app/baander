import styled from 'styled-components'
import { Play, Pause, SkipBack, SkipForward, Volume2, VolumeX } from 'lucide-react'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { useRadioStore } from '@/features/radio/stores/radio-store'
import { RadioPlayerBar } from '@/features/radio/components/RadioPlayerBar'
import { ProgressBar } from './ProgressBar'
import { CoverArt } from '@/shared/components/cover-art'

function formatTime(seconds: number): string {
  if (isNaN(seconds) || !isFinite(seconds) || seconds < 0) return '0:00'
  const mins = Math.floor(seconds / 60)
  const secs = Math.floor(seconds % 60)
  return `${mins}:${secs.toString().padStart(2, '0')}`
}

export function NowPlayingBar() {
  const activeRadioStation = useRadioStore((s) => s.activeStation)

  // Radio mode takes priority
  if (activeRadioStation) {
    return <RadioPlayerBar />
  }

  return <MusicPlayerBar />
}

const PlayerContainer = styled.div`
  border-top: 1px solid var(--color-border);
  background-color: var(--color-background);
`

const PlayerContent = styled.div`
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.5rem 1rem;
`

const CoverThumbnail = styled.div`
  width: 2.5rem;
  height: 2.5rem;
  flex-shrink: 0;
  overflow: hidden;
  border-radius: var(--radius-md);
`

const TrackInfo = styled.div`
  min-width: 0;
  flex: 1;
`

const TrackTitle = styled.p`
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  font-size: 0.875rem;
  font-weight: 500;
`

const TrackSubtitle = styled.p`
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const TransportControls = styled.div`
  display: flex;
  align-items: center;
  gap: 0.25rem;
`

const DurationVolume = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
`

const DurationText = styled.span`
  font-size: 0.75rem;
  font-variant-numeric: tabular-nums;
  color: var(--color-muted-foreground);
`

const VolumeGroup = styled.div`
  display: flex;
  align-items: center;
  gap: 0.25rem;
`

const VolumeButton = styled.button`
  display: flex;
  align-items: center;
  justify-content: center;
  height: 2rem;
  width: 2rem;
  border-radius: 9999px;
  background: none;
  border: none;
  cursor: pointer;
  color: inherit;
  transition: background-color 0.15s;

  &:hover {
    background-color: var(--color-accent);
  }
`

const VolumeSliderWrap = styled.div`
  display: flex;
  width: 5rem;
  align-items: center;

  input[type='range'] {
    height: 0.25rem;
    width: 100%;
    cursor: pointer;
    appearance: none;
    border-radius: 9999px;
    background: var(--color-muted);
    accent-color: var(--color-foreground);

    &::-webkit-slider-thumb {
      appearance: none;
    }
  }
`

function MusicPlayerBar() {
  const currentTrack = usePlayerStore((s) => s.currentTrack)
  const isPlaying = usePlayerStore((s) => s.isPlaying)
  const setIsPlaying = usePlayerStore((s) => s.setIsPlaying)
  const playNext = usePlayerStore((s) => s.playNext)
  const playPrevious = usePlayerStore((s) => s.playPrevious)

  if (!currentTrack) return null

  return (
    <PlayerContainer>
      {/* Progress bar at top of player */}
      <ProgressBar />

      <PlayerContent>
        {/* Cover art */}
        <CoverThumbnail>
          <CoverArt albumPublicId={currentTrack.albumPublicId} iconSize={14} />
        </CoverThumbnail>

        {/* Track info */}
        <TrackInfo>
          <TrackTitle>{currentTrack.title}</TrackTitle>
          <TrackSubtitle>
            {currentTrack.artistName}
            {currentTrack.artistName && currentTrack.albumName ? ' · ' : ''}
            {currentTrack.albumName}
          </TrackSubtitle>
        </TrackInfo>

        {/* Transport controls */}
        <TransportControls>
          <TransportButton
            onClick={playPrevious}
            label="Previous"
          >
            <SkipBack size={16} />
          </TransportButton>

          <TransportButton
            onClick={() => setIsPlaying(!isPlaying)}
            label={isPlaying ? 'Pause' : 'Play'}
            large
          >
            {isPlaying ? <Pause size={18} fill="currentColor" /> : <Play size={18} fill="currentColor" />}
          </TransportButton>

          <TransportButton
            onClick={playNext}
            label="Next"
          >
            <SkipForward size={16} />
          </TransportButton>
        </TransportControls>

        {/* Duration + volume */}
        <DurationVolume>
          <DurationText>
            {formatTime(usePlayerStore.getState().duration)}
          </DurationText>
          <VolumeSlider />
        </DurationVolume>
      </PlayerContent>
    </PlayerContainer>
  )
}

const StyledTransportButton = styled.button<{ $large?: boolean }>`
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 9999px;
  background: none;
  border: none;
  cursor: pointer;
  color: inherit;
  transition: background-color 0.15s;

  height: ${({ $large }) => ($large ? '2.5rem' : '2rem')};
  width: ${({ $large }) => ($large ? '2.5rem' : '2rem')};

  &:hover {
    background-color: var(--color-accent);
  }
`

function TransportButton({
  onClick,
  label,
  large,
  children,
}: {
  onClick: () => void
  label: string
  large?: boolean
  children: React.ReactNode
}) {
  return (
    <StyledTransportButton
      onClick={onClick}
      aria-label={label}
      $large={large}
    >
      {children}
    </StyledTransportButton>
  )
}

function VolumeSlider() {
  const volume = usePlayerStore((s) => s.volume)
  const muted = usePlayerStore((s) => s.muted)
  const setVolume = usePlayerStore((s) => s.setVolume)
  const toggleMute = usePlayerStore((s) => s.toggleMute)

  return (
    <VolumeGroup>
      <VolumeButton
        onClick={toggleMute}
        aria-label={muted ? 'Unmute' : 'Mute'}
      >
        {muted || volume === 0 ? <VolumeX size={16} /> : <Volume2 size={16} />}
      </VolumeButton>
      <VolumeSliderWrap>
        <input
          type="range"
          min="0"
          max="100"
          value={muted ? 0 : volume}
          onChange={(e) => {
            if (muted) setVolume(Number(e.target.value))
            else setVolume(Number(e.target.value))
          }}
          aria-label="Volume"
        />
      </VolumeSliderWrap>
    </VolumeGroup>
  )
}
