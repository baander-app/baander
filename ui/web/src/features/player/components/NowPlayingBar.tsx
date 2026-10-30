import { Button } from '@/shared/components/ui/button'
import { Slider } from '@/shared/components/ui/slider'
import styled from 'styled-components'
import { Play, Pause, SkipBack, SkipForward, Volume2, VolumeX } from 'lucide-react'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { useRadioStore } from '@/features/radio/stores/radio-store'
import { RadioPlayerBar } from '@/features/radio/components/RadioPlayerBar'
import { ProgressBar } from './ProgressBar'
import { PlaybackRecoveryAnnouncer } from './PlaybackRecoveryStatus'
import { CoverArt } from '@/shared/components/cover-art'

export function NowPlayingBar() {
  const activeRadioStation = useRadioStore((s) => s.activeStation)

  if (activeRadioStation) return <RadioPlayerBar />
  return <MusicPlayerBar />
}

const PlayerContainer = styled.section`
  border-top: 1px solid var(--color-border);
  background-color: var(--color-card);
  color: var(--color-card-foreground);
`

const PlayerContent = styled.div`
  display: grid;
  grid-template-columns: minmax(8.5rem, 1fr) minmax(0, 40rem) minmax(8.5rem, 1fr);
  align-items: center;
  gap: var(--space-lg);
  max-width: 76rem;
  margin: 0 auto;
  padding: 6px var(--space-lg) 8px;

  @media (max-width: 767px) {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: var(--space-sm);
    padding: 6px var(--space-md) 8px;
  }
`

const TrackDisplay = styled.div`
  --recess-shade: color-mix(in srgb, var(--color-muted) 96%, black);
  --recess-shadow: rgb(0 0 0 / 16%);
  --recess-lip: rgb(255 255 255 / 80%);
  --player-secondary-text: color-mix(in srgb, var(--color-muted-foreground) 85%, var(--color-foreground));

  display: grid;
  grid-template-columns: 3rem minmax(0, 1fr);
  align-items: center;
  gap: var(--space-md);
  min-width: 0;
  padding: var(--space-sm) var(--space-md);
  border-radius: var(--radius-lg);
  background-color: var(--color-muted);
  background-image: linear-gradient(
    to bottom,
    var(--recess-shade),
    var(--color-muted) 85%,
    color-mix(in srgb, var(--color-muted) 99%, white)
  );
  box-shadow: inset 0 1px 2px var(--recess-shadow), 0 1px 0 var(--recess-lip);
  color: var(--color-foreground);

  [data-slot='slider-track'] {
    background-color: color-mix(in srgb, var(--color-input-boundary) 90%, var(--color-foreground));
  }

  :root[data-theme='dark'] &, :root:not([data-theme]) & {
    --recess-shade: color-mix(in srgb, var(--color-muted) 80%, black);
    --recess-shadow: rgb(0 0 0 / 75%);
    --recess-lip: rgb(255 255 255 / 8%);
  }

  @media (max-width: 767px) {
    grid-column: 1 / -1;
    grid-row: 1;
  }
`

const CoverThumbnail = styled.div`
  width: 3rem;
  height: 3rem;
  overflow: hidden;
  border-radius: var(--radius-md);
`

const TrackInfo = styled.div`
  min-width: 0;
  text-align: center;
`

const TrackTitle = styled.p`
  margin: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  font-size: 0.875rem;
  font-weight: 500;
`

const TrackSubtitle = styled.p`
  margin: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  font-size: 0.75rem;
  color: var(--player-secondary-text);
`

const TransportControls = styled.div`
  display: flex;
  align-items: center;
  justify-self: end;
  gap: var(--space-md);

  @media (max-width: 767px) {
    justify-self: start;
  }
`

const PlayButton = styled(Button)`
  width: 2.5rem;
  height: 2.5rem;
`

const VolumeGroup = styled.div`
  display: flex;
  align-items: center;
  justify-self: start;
  gap: var(--space-md);

  @media (max-width: 767px) {
    justify-self: end;
  }
`

const VolumeSliderWrap = styled.div`
  display: flex;
  width: 5rem;
  align-items: center;
`

function MusicPlayerBar() {
  const currentTrack = usePlayerStore((s) => s.currentTrack)
  const isPlaying = usePlayerStore((s) => s.isPlaying)
  const setIsPlaying = usePlayerStore((s) => s.setIsPlaying)
  const playNext = usePlayerStore((s) => s.playNext)
  const playPrevious = usePlayerStore((s) => s.playPrevious)

  if (!currentTrack) return null

  return (
    <PlayerContainer aria-label="Playback">
      <PlaybackRecoveryAnnouncer />
      <PlayerContent>
        <TransportControls role="group" aria-label="Playback controls">
          <Button variant="ghost" size="icon" onClick={playPrevious} aria-label="Previous">
            <SkipBack size={16} />
          </Button>
          <PlayButton
            variant="secondary"
            size="icon"
            onClick={() => setIsPlaying(!isPlaying)}
            aria-label={isPlaying ? 'Pause' : 'Play'}
          >
            {isPlaying ? <Pause size={18} fill="currentColor" /> : <Play size={18} fill="currentColor" />}
          </PlayButton>
          <Button variant="ghost" size="icon" onClick={playNext} aria-label="Next">
            <SkipForward size={16} />
          </Button>
        </TransportControls>

        <TrackDisplay role="group" aria-label="Now playing">
          <CoverThumbnail>
            <CoverArt albumPublicId={currentTrack.albumPublicId} iconSize={18} />
          </CoverThumbnail>
          <TrackInfo>
            <TrackTitle title={currentTrack.title}>{currentTrack.title}</TrackTitle>
            <TrackSubtitle>
              {currentTrack.artistName}
              {currentTrack.artistName && currentTrack.albumName ? ' · ' : ''}
              {currentTrack.albumName}
            </TrackSubtitle>
            <ProgressBar />
          </TrackInfo>
        </TrackDisplay>

        <VolumeSlider />
      </PlayerContent>
    </PlayerContainer>
  )
}

function VolumeSlider() {
  const volume = usePlayerStore((s) => s.volume)
  const muted = usePlayerStore((s) => s.muted)
  const setVolume = usePlayerStore((s) => s.setVolume)
  const toggleMute = usePlayerStore((s) => s.toggleMute)

  return (
    <VolumeGroup>
      <Button
        variant="ghost"
        size="icon"
        onClick={toggleMute}
        aria-label={muted ? 'Unmute' : 'Mute'}
      >
        {muted || volume === 0 ? <VolumeX size={16} /> : <Volume2 size={16} />}
      </Button>
      <VolumeSliderWrap>
        <Slider
          min={0}
          max={100}
          step={1}
          value={[muted ? 0 : volume]}
          onValueChange={([value]) => {
            setVolume(value)
            if (muted && value > 0) toggleMute()
          }}
          aria-label="Volume"
        />
      </VolumeSliderWrap>
    </VolumeGroup>
  )
}
