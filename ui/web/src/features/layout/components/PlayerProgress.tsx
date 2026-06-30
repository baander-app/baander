import styled from 'styled-components'
import { useCallback } from 'react'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { useCurrentTime } from '@/features/player/stores/player-time-tracker'

const ProgressContainer = styled.div`
  display: flex;
  align-items: center;
  position: relative;
  height: 1rem;
  cursor: pointer;

  &:hover .progress-track {
    height: 0.375rem;
  }

  &:hover .progress-fill {
    background-color: color-mix(in srgb, var(--color-foreground) 80%, transparent);
  }

  &:hover .progress-thumb {
    opacity: 1;
  }
`

const ProgressTrack = styled.div`
  position: relative;
  height: 0.25rem;
  width: 100%;
  border-radius: 9999px;
  background-color: var(--color-muted);
  transition: height 150ms ease;
`

const ProgressFill = styled.div`
  position: absolute;
  top: 0;
  bottom: 0;
  left: 0;
  border-radius: 9999px;
  background-color: color-mix(in srgb, var(--color-foreground) 60%, transparent);
  transition: background-color 150ms ease;
`

const ProgressThumb = styled.div`
  position: absolute;
  top: 50%;
  width: 0.75rem;
  height: 0.75rem;
  transform: translate(-50%, -50%);
  border-radius: 9999px;
  background-color: var(--color-foreground);
  opacity: 0;
  transition: opacity 150ms ease;
`

export function PlayerProgress() {
  const currentTime = useCurrentTime()
  const duration = usePlayerStore((s) => s.duration)
  const seekTo = usePlayerStore((s) => s.seekTo)

  const progress = duration > 0 ? (currentTime / duration) * 100 : 0

  const handleProgressClick = useCallback((e: React.MouseEvent<HTMLDivElement>) => {
    const rect = e.currentTarget.getBoundingClientRect()
    const x = e.clientX - rect.left
    const pct = Math.max(0, Math.min(1, x / rect.width))
    seekTo(pct * usePlayerStore.getState().duration)
  }, [seekTo])

  const handleProgressDrag = useCallback((e: React.MouseEvent<HTMLDivElement>) => {
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
  }, [seekTo])

  return (
    <ProgressContainer
      onClick={handleProgressClick}
      onMouseDown={handleProgressDrag}
      role="slider"
      aria-label="Seek"
      aria-valuenow={Math.round(currentTime)}
      aria-valuemin={0}
      aria-valuemax={Math.round(duration)}
    >
      <ProgressTrack className="progress-track">
        <ProgressFill className="progress-fill" style={{ width: `${progress}%` }} />
        <ProgressThumb className="progress-thumb" style={{ left: `${progress}%` }} />
      </ProgressTrack>
    </ProgressContainer>
  )
}
