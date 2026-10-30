import styled from 'styled-components'
import { useRef, useState } from 'react'
import { X } from 'lucide-react'
import { Button } from '@/shared/components/ui/button'
import { Input } from '@/shared/components/ui/input'
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
  background-color: var(--color-background);
  padding: 0.75rem 1rem;
`

const TitleBar = styled.div`
  flex-shrink: 0;
  padding: 0 1rem;
`

const SourceSeek = styled(Input)`
  height: 2rem;
  border: 0;
  padding: 0;
  accent-color: var(--color-accent-text);
`

const VideoContainer = styled.div`
  display: flex;
  flex: 1;
  min-height: 0;
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
  const [draft, setDraft] = useState<{ videoId: string, position: number } | null>(null)
  const draftPosition = draft?.videoId === videoId ? draft.position : null

  const { currentTime, duration, pendingSeekPosition, seekTo } = useBaanderPlayer({
    videoId,
    containerRef,
    autoPlay: true,
  })

  const displayedTime = draftPosition ?? pendingSeekPosition ?? currentTime
  const commitSeek = (value: string) => {
    setDraft(null)
    seekTo(Number(value))
  }
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
        <Button variant="ghost" size="sm" onClick={onClose} style={{ gap: '0.5rem', color: 'var(--color-foreground)' }}>
          <X size={16} />
          Back
        </Button>
        <span style={{ fontSize: '0.875rem', fontWeight: 500, color: 'var(--color-foreground)' }}>{title}</span>
        <span style={{ fontSize: '0.75rem', color: 'var(--color-muted-foreground)' }}>
          {formatTime(displayedTime)} / {formatTime(duration)}
        </span>
      </HeaderBar>

      {/* Video container */}
      <VideoContainer ref={containerRef} />

      <TitleBar>
        <SourceSeek
          type="range"
          aria-label="Seek movie"
          aria-valuetext={`${formatTime(displayedTime)} of ${formatTime(duration)}`}
          min={0}
          max={duration || 1}
          step={1}
          value={Math.max(0, Math.min(duration, displayedTime))}
          disabled={duration <= 0}
          onChange={event => setDraft({ videoId, position: Number(event.currentTarget.value) })}
          onPointerUp={event => { if (draftPosition !== null) commitSeek(event.currentTarget.value) }}
          onPointerCancel={() => setDraft(null)}
          onKeyUp={event => {
            if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End', 'PageUp', 'PageDown'].includes(event.key)) {
              commitSeek(event.currentTarget.value)
            }
          }}
          onBlur={event => { if (draftPosition !== null) commitSeek(event.currentTarget.value) }}
        />
      </TitleBar>
    </Overlay>
  )
}
