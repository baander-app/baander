import styled from 'styled-components'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { useCurrentTime } from '@/features/player/stores/player-time-tracker'

function formatTime(seconds: number): string {
  if (isNaN(seconds) || !isFinite(seconds) || seconds < 0) return '0:00'
  const mins = Math.floor(seconds / 60)
  const secs = Math.floor(seconds % 60)
  return `${mins}:${secs.toString().padStart(2, '0')}`
}

const ProgressRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0 0.75rem;

  &:hover ${() => ProgressTrack} {
    height: 0.375rem;
  }

  &:hover ${() => ProgressFill} {
    background-color: rgb(from var(--color-foreground) r g b / 0.8);
  }

  &:hover ${() => ProgressThumb} {
    opacity: 1;
  }
`

const TimeLabel = styled.span`
  width: 2.5rem;
  font-size: 0.75rem;
  font-variant-numeric: tabular-nums;
  color: var(--color-muted-foreground);
`

const TimeLabelRight = styled(TimeLabel)`
  text-align: right;
`

const ProgressTrack = styled.div`
  position: relative;
  height: 0.25rem;
  width: 100%;
  cursor: pointer;
  border-radius: 9999px;
  background: var(--color-muted);
  transition: height 0.15s;
`

const ProgressFill = styled.div`
  position: absolute;
  top: 0;
  bottom: 0;
  left: 0;
  border-radius: 9999px;
  background-color: rgb(from var(--color-foreground) r g b / 0.6);
  transition: background-color 0.15s;
`

const ProgressThumb = styled.div`
  position: absolute;
  top: 50%;
  width: 0.75rem;
  height: 0.75rem;
  border-radius: 9999px;
  background: var(--color-foreground);
  opacity: 0;
  transition: opacity 0.15s;
  transform: translate(-50%, -50%);
`

export function ProgressBar() {
  const currentTime = useCurrentTime()
  const duration = usePlayerStore((s) => s.duration)
  const seekTo = usePlayerStore((s) => s.seekTo)

  const progress = duration > 0 ? (currentTime / duration) * 100 : 0

  const handleClick = (e: React.MouseEvent<HTMLDivElement>) => {
    const rect = e.currentTarget.getBoundingClientRect()
    const x = e.clientX - rect.left
    const pct = Math.max(0, Math.min(1, x / rect.width))
    seekTo(pct * usePlayerStore.getState().duration)
  }

  const handleMouseDown = (e: React.MouseEvent<HTMLDivElement>) => {
    e.preventDefault()
    const rect = e.currentTarget.getBoundingClientRect()
    const onMove = (moveEvent: MouseEvent) => {
      const x = moveEvent.clientX - rect.left
      const pct = Math.max(0, Math.min(1, x / rect.width))
      seekTo(pct * usePlayerStore.getState().duration)
    }
    const onUp = () => {
      document.removeEventListener('mousemove', onMove)
      document.removeEventListener('mouseup', onUp)
    }
    document.addEventListener('mousemove', onMove)
    document.addEventListener('mouseup', onUp)
  }

  return (
    <ProgressRow>
      <TimeLabelRight>
        {formatTime(currentTime)}
      </TimeLabelRight>
      <ProgressTrack
        onClick={handleClick}
        onMouseDown={handleMouseDown}
        role="slider"
        aria-label="Seek"
        aria-valuenow={Math.round(currentTime)}
        aria-valuemin={0}
        aria-valuemax={Math.round(duration)}
      >
        <ProgressFill style={{ width: `${progress}%` }} />
        <ProgressThumb style={{ left: `${progress}%` }} />
      </ProgressTrack>
      <TimeLabel>
        {formatTime(duration)}
      </TimeLabel>
    </ProgressRow>
  )
}
