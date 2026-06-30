import styled from 'styled-components'
import { useRef } from 'react'
import { X } from 'lucide-react'
import { Button } from '@/shared/components/ui/button'
import { useBaanderPlayer } from '../hooks/useBaanderPlayer'

const Overlay = styled.div`
  position: fixed;
  inset: 0;
  z-index: 50;
  display: flex;
  flex-direction: column;
  background-color: black;
`

const HeaderBar = styled.div`
  position: absolute;
  inset-inline: 0;
  top: 0;
  z-index: 10;
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: linear-gradient(to bottom, rgba(0, 0, 0, 0.8), transparent);
  padding: 0.75rem 1rem;
`

const TitleBar = styled.div`
  position: absolute;
  inset-inline: 0;
  bottom: 0;
  z-index: 10;
`

const ProgressBar = styled.div`
  height: 0.25rem;
  background-color: rgba(255, 255, 255, 0.2);
`

const ProgressFill = styled.div`
  height: 100%;
  background-color: var(--color-primary);
  transition: width 300ms;
`

const VideoContainer = styled.div`
  display: flex;
  flex: 1;
  align-items: center;
  justify-content: center;
`

interface MoviePlayerOverlayProps {
  title: string
  videoId: string
  onClose: () => void
}

export function MoviePlayerOverlay({ title, videoId, onClose }: MoviePlayerOverlayProps) {
  const containerRef = useRef<HTMLDivElement>(null)

  const { currentTime, duration } = useBaanderPlayer({
    videoId,
    containerRef,
    autoPlay: true,
  })

  const progress = duration > 0 ? (currentTime / duration) * 100 : 0
  const formatTime = (seconds: number) => {
    const h = Math.floor(seconds / 3600)
    const m = Math.floor((seconds % 3600) / 60)
    const s = Math.floor(seconds % 60)
    if (h > 0) return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
    return `${m}:${String(s).padStart(2, '0')}`
  }

  return (
    <Overlay>
      {/* Header */}
      <HeaderBar>
        <Button variant="ghost" size="sm" onClick={onClose} style={{ gap: '0.5rem', color: 'white' }}>
          <X size={16} />
          Back
        </Button>
        <span style={{ fontSize: '0.875rem', fontWeight: 500, color: 'white' }}>{title}</span>
        <span style={{ fontSize: '0.75rem', color: 'rgba(255, 255, 255, 0.5)' }}>
          {formatTime(currentTime)} / {formatTime(duration)}
        </span>
      </HeaderBar>

      {/* Video container */}
      <VideoContainer ref={containerRef} />

      {/* Progress bar */}
      <TitleBar>
        <ProgressBar>
          <ProgressFill style={{ width: `${progress}%` }} />
        </ProgressBar>
      </TitleBar>
    </Overlay>
  )
}
