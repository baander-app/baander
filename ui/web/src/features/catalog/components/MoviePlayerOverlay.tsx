import styled from 'styled-components'
import { useEffect, useRef, useState } from 'react'
import { X } from 'lucide-react'
import { Button } from '@/shared/components/ui/button'
import { Slider } from '@/shared/components/ui/slider'
import { requestPlayback, type PlaybackIntent } from '@/features/session/services/playback-execution'
import { usePlaybackSession } from '@/features/session/hooks/use-playback-session'
import { PlaybackDestinationSelector } from '@/features/session/components/PlaybackDestinationSelector'
import { useVideoPresentation } from '../stores/video-presentation-store'

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

const SourceSeek = styled(Slider)`
  height: 2rem;
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
  sourceDuration?: number
  onClose: () => void
}

export function MoviePlayerOverlay({ title, videoId, sourceDuration = 0, onClose }: MoviePlayerOverlayProps) {
  const containerRef = useRef<HTMLDivElement>(null)
  const [draft, setDraft] = useState<{ videoId: string, position: number } | null>(null)
  const draftPosition = draft?.videoId === videoId ? draft.position : null
  const cancelledSeek = useRef(false)
  const cancelledVolume = useRef(false)
  const [volumeDraft, setVolumeDraft] = useState<{ scope: string; value: number } | null>(null)

  const { snapshot, endpoint, connected } = usePlaybackSession()
  const host = useVideoPresentation(state => state.host)
  const localTime = useVideoPresentation(state => state.currentTime)
  const localDuration = useVideoPresentation(state => state.duration)
  const pendingSeekPosition = useVideoPresentation(state => state.pendingSeekPosition)
  const error = useVideoPresentation(state => state.error)
  const localVideoId = useVideoPresentation(state => state.videoId)
  const local = !!endpoint && snapshot?.activeIncarnationId === endpoint.incarnationId
  const selected = snapshot?.media?.kind === 'video' && snapshot.media.publicId === videoId
  const currentTime = local && localVideoId === videoId ? localTime : selected ? snapshot.position : 0
  const duration = local && localVideoId === videoId ? localDuration : sourceDuration
  const destination = snapshot?.destinations.find(item => item.destinationId === snapshot.activeDestinationId)
  const volumeSupported = destination?.capabilities.includes('volume') ?? false
  const volumeScope = `${videoId}:${destination?.incarnationId ?? ''}`
  const displayedVolume = volumeDraft?.scope === volumeScope ? volumeDraft.value : destination?.observedVolume ?? 1
  const playing = selected && snapshot?.observedState === 'playing'
  const remoteStatus = !selected || snapshot?.transfer ? 'Preparing movie playback…'
    : snapshot?.observedState === 'playing' ? 'Playing on the selected destination'
      : snapshot?.observedState === 'paused' ? 'Paused on the selected destination'
        : 'Playback status is unknown.'
  const submit = (intent: PlaybackIntent) => requestPlayback(intent).catch(useVideoPresentation.getState().reportError)
  const seekTo = (position: number) => { submit({ action: 'seek', position }) }

  useEffect(() => {
    if (!local || localVideoId !== videoId || !host || !containerRef.current) return
    const parking = host.parentElement
    containerRef.current.appendChild(host)
    return () => { parking?.appendChild(host) }
  }, [local, localVideoId, videoId, host])

  const close = async () => {
    if (endpoint) {
      try { await requestPlayback({ action: 'stop-local-video', incarnationId: endpoint.incarnationId, videoId }) }
      catch (reason) { useVideoPresentation.getState().reportError(reason); return }
    }
    onClose()
  }

  const displayedTime = draftPosition ?? (local ? pendingSeekPosition : null) ?? currentTime
  const commitSeek = (value: number) => {
    setDraft(null)
    if (!cancelledSeek.current) seekTo(value)
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
        <Button variant="ghost" size="sm" onClick={() => { close() }} style={{ gap: '0.5rem', color: 'var(--color-foreground)' }}>
          <X size={16} />
          Back
        </Button>
        <span style={{ fontSize: '0.875rem', fontWeight: 500, color: 'var(--color-foreground)' }}>{title}</span>
        <span style={{ fontSize: '0.75rem', color: 'var(--color-muted-foreground)' }}>
          {formatTime(displayedTime)} / {formatTime(duration)}
        </span>
      </HeaderBar>

      {/* Video container */}
      <VideoContainer ref={containerRef}>
        {!local ? <span role="status" style={{ color: 'var(--color-foreground)' }}>{connected ? remoteStatus : 'Connection lost. Playback may still be active.'}</span> : null}
      </VideoContainer>

      <TitleBar>
        <Button variant="ghost" size="sm" disabled={!connected || !selected} onClick={() => { submit({ action: playing ? 'pause' : 'play' }) }}>
          {playing ? 'Pause' : 'Play'}
        </Button>
        <Button variant="ghost" size="sm" disabled={!connected || !selected} onClick={() => { submit({ action: 'stop' }) }}>Stop</Button>
        <PlaybackDestinationSelector />
        <Slider
          key={volumeScope}
          aria-label="Movie volume"
          aria-valuetext={`${Math.round(displayedVolume * 100)}%`}
          min={0}
          max={1}
          step={0.01}
          value={[displayedVolume]}
          disabled={!connected || !selected || !volumeSupported}
          onValueChange={([value]) => setVolumeDraft({ scope: volumeScope, value })}
          onValueCommit={([volume]) => {
            setVolumeDraft(null)
            if (!cancelledVolume.current) submit({ action: 'volume', volume })
          }}
          onPointerDown={() => { cancelledVolume.current = false }}
          onPointerCancel={() => { cancelledVolume.current = true; setVolumeDraft(null) }}
          onKeyDown={() => { cancelledVolume.current = false }}
        />
        {error ? <p role="alert">{error}</p> : null}
        <SourceSeek
          key={videoId}
          aria-label="Seek movie"
          aria-valuetext={`${formatTime(displayedTime)} of ${formatTime(duration)}`}
          min={0}
          max={duration || 1}
          step={1}
          value={[Math.max(0, Math.min(duration, displayedTime))]}
          disabled={duration <= 0 || !connected || !selected}
          onValueChange={([position]) => setDraft({ videoId, position })}
          onValueCommit={([position]) => commitSeek(position)}
          onPointerDown={() => { cancelledSeek.current = false }}
          onPointerCancel={() => { cancelledSeek.current = true; setDraft(null) }}
          onKeyDown={() => { cancelledSeek.current = false }}
          onBlur={() => {
            if (draftPosition !== null) {
              commitSeek(draftPosition)
              cancelledSeek.current = true
            }
          }}
        />
      </TitleBar>
    </Overlay>
  )
}
