import styled from 'styled-components'
import { Slider } from '@/shared/components/ui/slider'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { useCurrentTime } from '@/features/player/stores/player-time-tracker'
import { PlaybackClock } from './PlaybackRecoveryStatus'

function formatTime(seconds: number): string {
  if (!Number.isFinite(seconds) || seconds < 0) return '0:00'
  const mins = Math.floor(seconds / 60)
  const secs = Math.floor(seconds % 60)
  return `${mins}:${secs.toString().padStart(2, '0')}`
}

const ProgressRow = styled.div`
  display: grid;
  grid-template-columns: auto minmax(2rem, 1fr) auto;
  align-items: center;
  gap: var(--space-md);
  min-height: 1.5rem;
`

const TimeLabel = styled.span`
  white-space: nowrap;
  font-family: var(--font-mono);
  font-size: 0.6875rem;
  font-variant-numeric: tabular-nums;
  color: var(--player-secondary-text, var(--color-muted-foreground));
`

export function ProgressBar() {
  const currentTime = useCurrentTime()
  const duration = usePlayerStore((s) => s.duration)
  const seekTo = usePlayerStore((s) => s.seekTo)
  const canSeek = Number.isFinite(duration) && duration > 0
  const position = canSeek && Number.isFinite(currentTime)
    ? Math.max(0, Math.min(currentTime, duration))
    : 0

  return (
    <ProgressRow>
      <TimeLabel>
        <PlaybackClock format={formatTime} />
      </TimeLabel>
      <Slider
        aria-label="Seek"
        min={0}
        max={canSeek ? duration : 1}
        step={1}
        value={[position]}
        disabled={!canSeek}
        onValueChange={([value]) => {
          if (canSeek) seekTo(value)
        }}
      />
      <TimeLabel>{formatTime(duration)}</TimeLabel>
    </ProgressRow>
  )
}
