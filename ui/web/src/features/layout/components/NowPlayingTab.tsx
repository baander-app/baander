import styled from 'styled-components'
import { Play, Pause, SkipBack, SkipForward, Volume2, VolumeX, Music, Shuffle, Repeat, Repeat1 } from 'lucide-react'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { useCurrentTime } from '@/features/player/stores/player-time-tracker'
import { ProgressBar } from '@/features/player/components/ProgressBar'
import { Button } from '@/shared/components/ui/button'
import { Slider } from '@/shared/components/ui/slider'
import { formatDuration } from '@/shared/utils/format-duration'
import { CoverArt } from '@/shared/components/cover-art'

const SliderWrapper = styled.div`
  flex: 1;
`

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1rem;
`

const TrackInfoSection = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.75rem;
  text-align: center;
`

const CoverWrapper = styled.div`
  height: 12rem;
  width: 12rem;
  overflow: hidden;
  border-radius: var(--radius-lg);
`

const TrackTitle = styled.h3`
  font-size: 1rem;
  font-weight: 500;
  color: var(--color-foreground);
  margin: 0;
`

const TrackArtist = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
  margin: 0;
`

const TrackAlbum = styled.p`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
  margin: 0;
`

const ProgressSection = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
`

const TimeRow = styled.div`
  display: flex;
  justify-content: space-between;
  font-size: 0.75rem;
  font-variant-numeric: tabular-nums;
  color: var(--color-muted-foreground);
`

const TransportRow = styled.div`
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
`

const VolumeRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0 0.5rem;
`

const EmptyCover = styled.div`
  display: flex;
  height: 12rem;
  width: 12rem;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-lg);
  background-color: var(--color-secondary);
`

const EmptyText = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const EmptyState = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  padding: 3rem 0;
`

export function NowPlayingTab() {
  const currentTrack = usePlayerStore((s) => s.currentTrack)
  const isPlaying = usePlayerStore((s) => s.isPlaying)
  const setIsPlaying = usePlayerStore((s) => s.setIsPlaying)
  const playNext = usePlayerStore((s) => s.playNext)
  const playPrevious = usePlayerStore((s) => s.playPrevious)
  const volume = usePlayerStore((s) => s.volume)
  const muted = usePlayerStore((s) => s.muted)
  const setVolume = usePlayerStore((s) => s.setVolume)
  const toggleMute = usePlayerStore((s) => s.toggleMute)
  const duration = usePlayerStore((s) => s.duration)
  const currentTime = useCurrentTime()
  const shuffle = usePlayerStore((s) => s.shuffle)
  const repeat = usePlayerStore((s) => s.repeat)
  const toggleShuffle = usePlayerStore((s) => s.toggleShuffle)
  const toggleRepeat = usePlayerStore((s) => s.toggleRepeat)

  if (!currentTrack) {
    return (
      <EmptyState>
        <EmptyCover>
          <Music size={48} style={{ color: 'color-mix(in srgb, var(--color-muted-foreground) 30%, transparent)' }} />
        </EmptyCover>
        <EmptyText>Nothing playing</EmptyText>
      </EmptyState>
    )
  }

  return (
    <Container>
      {/* Track info */}
      <TrackInfoSection>
        <CoverWrapper>
          <CoverArt albumPublicId={currentTrack.albumPublicId} iconSize={48} />
        </CoverWrapper>
        <div>
          <TrackTitle>{currentTrack.title}</TrackTitle>
          <TrackArtist>{currentTrack.artistName}</TrackArtist>
          {currentTrack.albumName && (
            <TrackAlbum>{currentTrack.albumName}</TrackAlbum>
          )}
        </div>
      </TrackInfoSection>

      {/* Progress */}
      <ProgressSection>
        <ProgressBar />
        <TimeRow>
          <span>{formatDuration(currentTime)}</span>
          <span>{formatDuration(duration)}</span>
        </TimeRow>
      </ProgressSection>

      {/* Transport controls */}
      <TransportRow>
        <Button variant={shuffle ? 'secondary' : 'ghost'} size="icon" onClick={toggleShuffle} aria-label="Shuffle">
          <Shuffle size={16} />
        </Button>
        <Button variant="ghost" size="icon" onClick={playPrevious} aria-label="Previous">
          <SkipBack size={18} />
        </Button>
        <Button
          size="icon-lg"
          onClick={() => setIsPlaying(!isPlaying)}
          aria-label={isPlaying ? 'Pause' : 'Play'}
        >
          {isPlaying ? <Pause size={20} fill="currentColor" /> : <Play size={20} fill="currentColor" />}
        </Button>
        <Button variant="ghost" size="icon" onClick={playNext} aria-label="Next">
          <SkipForward size={18} />
        </Button>
        <Button variant={repeat !== 'off' ? 'secondary' : 'ghost'} size="icon" onClick={toggleRepeat} aria-label="Repeat">
          {repeat === 'one' ? <Repeat1 size={16} /> : <Repeat size={16} />}
        </Button>
      </TransportRow>

      {/* Volume */}
      <VolumeRow>
        <Button variant="ghost" size="icon-xs" onClick={toggleMute} aria-label={muted ? 'Unmute' : 'Mute'}>
          {muted || volume === 0 ? <VolumeX size={14} /> : <Volume2 size={14} />}
        </Button>
        <SliderWrapper>
          <Slider
            value={[muted ? 0 : volume]}
            onValueChange={([v]) => setVolume(v)}
            max={100}
            step={1}
            aria-label="Volume"
          />
        </SliderWrapper>
      </VolumeRow>
    </Container>
  )
}
