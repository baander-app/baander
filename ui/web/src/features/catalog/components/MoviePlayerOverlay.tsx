import styled from 'styled-components'
import { useEffect, useRef, useState } from 'react'
import { Dialog as DialogPrimitive } from 'radix-ui'
import {
  X,
  Play,
  Pause,
  Square,
  RotateCcw,
  RotateCw,
  Volume2,
  VolumeX,
  Maximize,
  Settings2,
  ListVideo
} from 'lucide-react'
import { Button } from '@/shared/components/ui/button'
import { Dialog } from '@/shared/components/ui/dialog'
import { Slider } from '@/shared/components/ui/slider'
import {
  requestPlayback,
  type PlaybackIntent
} from '@/features/session/services/playback-execution'
import { usePlaybackSession } from '@/features/session/hooks/use-playback-session'
import { PlaybackDestinationSelector } from '@/features/session/components/PlaybackDestinationSelector'
import { useVideoPresentation } from '../stores/video-presentation-store'
import { useVideoPresentationError } from '../hooks/use-video-presentation-error'
import { useVideoPlaybackInformation } from '../hooks/use-video-playback-information'
import { textRole } from '@/shared/theme'
import { ContentText } from '@/shared/typography'
import { CinemaButton } from './CinemaControls'
import { formatVideoTime } from '../utils/video-time'
import { CinemaSettings } from './CinemaSettings'
import { CinemaTimeline } from './CinemaTimeline'

const Overlay = styled(DialogPrimitive.Content)`
  position: fixed;
  inset: 0;
  z-index: 50;
  display: flex;
  flex-direction: column;
  background: black;
  color: var(--color-foreground);
  outline: none;
  overflow: hidden;
  [data-cinema-chrome] {
    opacity: 1;
    transition: opacity 100ms ease-out;
  }
  &[data-idle='true'] {
    cursor: none;
  }
  &[data-idle='true'] [data-cinema-chrome] {
    opacity: 0;
    pointer-events: none;
  }
  @media (prefers-reduced-motion: reduce) {
    [data-cinema-chrome] {
      transition: none;
    }
  }
`
const Header = styled.header`
  position: absolute;
  inset: 0 0 auto;
  z-index: 2;
  display: flex;
  align-items: center;
  gap: var(--space-md);
  background: var(--color-card);
  border-bottom: 1px solid var(--color-border);
  padding: var(--space-sm) var(--space-md);
`
const ResumeControls = styled.div`
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-sm);
`
const MovieTitle = styled(ContentText)`
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0;
`
const VideoContainer = styled.div`
  position: relative;
  display: flex;
  flex: 1;
  min-width: 0;
  min-height: 0;
  align-items: center;
  justify-content: center;
  > div {
    position: absolute;
    inset: 0;
  }
  video {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: contain;
  }
  [role='status'] {
    padding: var(--space-lg);
    ${textRole('body')}
    color: var(--color-muted-foreground);
    background: var(--color-card);
  }
`
const Console = styled.footer`
  position: absolute;
  inset: auto 0 0;
  z-index: 2;
  padding: var(--space-sm) var(--space-lg);
  border-top: 1px solid var(--color-border);
  background: var(--color-card);
  @media (max-width: 640px) {
    padding: var(--space-sm);
  }
`
const Row = styled.div`
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: var(--space-sm);
`
const Readout = styled.div`
  display: flex;
  justify-content: space-between;
  gap: var(--space-md);
  ${textRole('timecode')}
  color: var(--color-muted-foreground);
  span:first-child {
    ${textRole('caption')}
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  span:last-child {
    flex-shrink: 0;
  }
`
const VolumeWrap = styled.div`
  display: flex;
  width: 5rem;
  align-items: center;
`
const Panel = styled.section`
  position: absolute;
  right: var(--space-md);
  bottom: 100%;
  width: min(26rem, calc(100vw - 2rem));
  max-height: calc(100dvh - 15rem);
  overflow-y: auto;
  padding: var(--space-md);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-popover);
  color: var(--color-popover-foreground);
  h2 {
    ${textRole('subtitle')}
    margin: 0 0 var(--space-md);
  }
  p {
    ${textRole('body')}
  }
`
const Notice = styled.p`
  ${textRole('caption')}
  margin: var(--space-sm) 0;
  color: var(--color-foreground);
`

interface MoviePlayerOverlayProps {
  title: string
  videoId: string
  sourceDuration?: number
  startSeconds?: number
  onClose: () => void
}

function remotePlaybackStatus(
  connected: boolean,
  preparing: boolean,
  playing: boolean
) {
  if (!connected) {
    return 'Connection lost. Playback may still be active.'
  }
  if (preparing) {
    return 'Preparing video playback…'
  }
  if (playing) {
    return 'Playing on the selected destination'
  }
  return 'Paused on the selected destination'
}

export function MoviePlayerOverlay({
  title,
  videoId,
  sourceDuration = 0,
  startSeconds = 0,
  onClose
}: MoviePlayerOverlayProps) {
  const overlayRef = useRef<HTMLDivElement>(null)
  const containerRef = useRef<HTMLDivElement>(null)
  const restoreFocus = useRef<HTMLElement | null>(null)
  const lastVolume = useRef(1)
  const cancelledVolume = useRef(false)
  const [volumeDraft, setVolumeDraft] = useState<{
    scope: string
    value: number
  } | null>(null)
  const [dismissedResume, setDismissedResume] = useState<string | null>(null)
  const resumeScope = `${videoId}:${startSeconds}:${title}`
  const [panel, setPanel] = useState<'settings' | 'chapters' | null>(null)
  const [idle, setIdle] = useState(false)
  const [fullscreenActive, setFullscreenActive] = useState(false)
  const [focused, setFocused] = useState(false)
  const [activity, setActivity] = useState(0)
  const { snapshot, endpoint, connected } = usePlaybackSession()
  const host = useVideoPresentation((state) => state.host)
  const localTime = useVideoPresentation((state) => state.currentTime)
  const localDuration = useVideoPresentation((state) => state.duration)
  const buffered = useVideoPresentation((state) => state.bufferedUntil)
  const pendingSeekPosition = useVideoPresentation(
    (state) => state.pendingSeekPosition
  )
  const localVideoId = useVideoPresentation((state) => state.videoId)
  const error = useVideoPresentationError(snapshot)
  const information = useVideoPlaybackInformation(videoId)
  const local =
    !!endpoint && snapshot?.activeIncarnationId === endpoint.incarnationId
  const selected =
    snapshot?.media?.kind === 'video' && snapshot.media.publicId === videoId
  const currentTime =
    local && localVideoId === videoId
      ? localTime
      : selected
        ? snapshot.position
        : 0
  const duration =
    local && localVideoId === videoId ? localDuration : sourceDuration
  const displayedTime = (local ? pendingSeekPosition : null) ?? currentTime
  const chapter = information.data?.chapters.find(
    (item) => currentTime >= item.start && currentTime < item.end
  )
  const destination = snapshot?.destinations.find(
    (item) => item.destinationId === snapshot.activeDestinationId
  )
  const volumeSupported = destination?.capabilities.includes('volume') ?? false
  const volumeScope = `${videoId}:${destination?.incarnationId ?? ''}`
  const volume = Math.max(
    0,
    Math.min(
      1,
      volumeDraft?.scope === volumeScope
        ? volumeDraft.value
        : (destination?.observedVolume ?? 1)
    )
  )
  const playing = selected && snapshot?.observedState === 'playing'
  const disabled = !connected || !selected
  const submit = (intent: PlaybackIntent) =>
    requestPlayback(intent).catch(useVideoPresentation.getState().reportError)
  const seekTo = (position: number) => {
    submit({
      action: 'seek',
      position: Math.max(0, Math.min(duration, position))
    })
  }
  const wake = () => {
    setIdle(false)
    setActivity((value) => value + 1)
  }
  const close = async () => {
    if (endpoint) {
      try {
        await requestPlayback({
          action: 'stop-local-video',
          incarnationId: endpoint.incarnationId,
          videoId
        })
      } catch (reason) {
        useVideoPresentation.getState().reportError(reason)
        return
      }
    }
    onClose()
  }
  const fullscreen = async () => {
    try {
      if (document.fullscreenElement) await document.exitFullscreen()
      else if (document.documentElement.requestFullscreen)
        await document.documentElement.requestFullscreen()
      else throw new Error('Fullscreen is unavailable in this browser.')
    } catch (reason) {
      useVideoPresentation.getState().reportError(reason)
    }
  }
  const mute = () => {
    if (volume > 0) lastVolume.current = volume
    submit({ action: 'volume', volume: volume > 0 ? 0 : lastVolume.current })
  }

  useEffect(() => {
    if (!local || localVideoId !== videoId || !host || !containerRef.current)
      return
    const parking = host.parentElement
    containerRef.current.appendChild(host)
    return () => {
      parking?.appendChild(host)
    }
  }, [local, localVideoId, videoId, host])

  useEffect(() => {
    if (!playing || focused || panel || error || !connected) return
    const timer = setTimeout(() => setIdle(true), 3000)
    return () => clearTimeout(timer)
  }, [playing, focused, panel, error, connected, activity])

  useEffect(() => {
    const updateFullscreen = () => {
      setFullscreenActive(
        document.fullscreenElement === document.documentElement
      )
    }
    document.addEventListener('fullscreenchange', updateFullscreen)
    return () =>
      document.removeEventListener('fullscreenchange', updateFullscreen)
  }, [])

  const showResume = startSeconds > 0 && dismissedResume !== resumeScope
  const startOver = () => {
    requestPlayback({ action: 'seek', position: 0 })
      .then(() => setDismissedResume(resumeScope))
      .catch(useVideoPresentation.getState().reportError)
  }
  const hidden = idle && playing && !focused && !panel && !error && connected
  return (
    <Dialog open>
      <Overlay
        ref={overlayRef}
        data-idle={hidden}
        aria-describedby={undefined}
        onOpenAutoFocus={(event) => {
          restoreFocus.current =
            document.activeElement instanceof HTMLElement
              ? document.activeElement
              : null
          event.preventDefault()
          overlayRef.current?.focus()
        }}
        onCloseAutoFocus={(event) => {
          event.preventDefault()
          restoreFocus.current?.focus()
        }}
        onEscapeKeyDown={(event) => {
          event.preventDefault()
          if (panel) setPanel(null)
          else if (!document.fullscreenElement) {
            close()
          }
        }}
        onPointerDownOutside={(event) => event.preventDefault()}
        onInteractOutside={(event) => event.preventDefault()}
        onPointerMove={wake}
        onPointerDown={wake}
        onFocusCapture={(event) => {
          if (event.target !== overlayRef.current) setFocused(true)
          wake()
        }}
        onBlurCapture={(event) => {
          if (!event.currentTarget.contains(event.relatedTarget))
            setFocused(false)
        }}
        onKeyDown={(event) => {
          wake()
          const target = event.target as HTMLElement
          if (
            event.altKey ||
            event.ctrlKey ||
            event.metaKey ||
            target.closest(
              'input, textarea, select, [contenteditable="true"], [role="slider"], [role="combobox"], [role="listbox"], [role="menu"], [data-cinema-panel]'
            )
          )
            return
          if (target.closest('button') && [' ', 'Enter'].includes(event.key))
            return
          const key = event.key.toLowerCase()
          if (
            [
              ' ',
              'k',
              'arrowleft',
              'arrowright',
              'arrowup',
              'arrowdown',
              'm',
              'f'
            ].includes(key)
          )
            event.preventDefault()
          if (key === 'f') {
            fullscreen()
            return
          }
          if (disabled) return
          if (key === ' ' || key === 'k')
            submit({ action: playing ? 'pause' : 'play' })
          if (key === 'arrowleft') seekTo(displayedTime - 10)
          if (key === 'arrowright') seekTo(displayedTime + 10)
          if (key === 'm' && volumeSupported) mute()
          if ((key === 'arrowup' || key === 'arrowdown') && volumeSupported)
            submit({
              action: 'volume',
              volume: Math.max(
                0,
                Math.min(1, volume + (key === 'arrowup' ? 0.05 : -0.05))
              )
            })
        }}
      >
        <Header data-cinema-chrome>
          <CinemaButton
            label="Back"
            onClick={() => {
              close()
            }}
          >
            <X size={20} />
          </CinemaButton>
          <DialogPrimitive.Title asChild>
            <MovieTitle textRole="body" text={title} />
          </DialogPrimitive.Title>
          {showResume ? (
            <ResumeControls aria-label="Playback resume">
              <ContentText textRole="caption" text={`Resumed at ${formatVideoTime(startSeconds)}`} element="span" />
              <Button variant="ghost" size="sm" disabled={disabled} onClick={startOver}>
                Start Over
              </Button>
              <CinemaButton label="Dismiss resume notice" onClick={() => setDismissedResume(resumeScope)}>
                <X size={16} />
              </CinemaButton>
            </ResumeControls>
          ) : null}
        </Header>
        <VideoContainer ref={containerRef}>
          {!local ? (
            <span role="status">
              {remotePlaybackStatus(
                connected,
                !selected || !!snapshot?.transfer,
                playing
              )}
            </span>
          ) : null}
        </VideoContainer>
        <Console data-cinema-chrome>
          {panel ? (
            <Panel
              data-cinema-panel
              aria-label={panel === 'settings' ? 'Settings' : 'Chapters'}
            >
              <Row>
                <h2>{panel === 'settings' ? 'Settings' : 'Chapters'}</h2>
                <CinemaButton
                  label="Close panel"
                  onClick={() => setPanel(null)}
                >
                  <X size={18} />
                </CinemaButton>
              </Row>
              {panel === 'settings' ? (
                <CinemaSettings
                  information={information.data}
                  local={local && selected && connected}
                />
              ) : information.data?.chapters.length ? (
                information.data.chapters.map((item) => (
                  <Button
                    key={item.start}
                    aria-current={
                      currentTime >= item.start && currentTime < item.end
                        ? 'true'
                        : undefined
                    }
                    variant="ghost"
                    disabled={disabled}
                    onClick={() => {
                      seekTo(item.start)
                      setPanel(null)
                    }}
                  >
                    {formatVideoTime(item.start)} · {item.title}
                  </Button>
                ))
              ) : (
                <p>
                  {information.isPending
                    ? 'Loading chapters…'
                    : 'No chapters are available for this video.'}
                </p>
              )}
              {information.isError ? (
                <Notice role="status">
                  Video information could not be loaded.{' '}
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                      information.refetch()
                    }}
                  >
                    Retry
                  </Button>
                </Notice>
              ) : null}
            </Panel>
          ) : null}
          <Readout>
            <span>{chapter?.title ?? title}</span>
            <span>
              {formatVideoTime(displayedTime)} / {formatVideoTime(duration)}
              {` · −${formatVideoTime(duration - displayedTime)} remaining`}
            </span>
          </Readout>
          <CinemaTimeline
            videoId={videoId}
            time={displayedTime}
            duration={duration}
            buffered={local ? (buffered ?? 0) : 0}
            disabled={disabled}
            information={information.data}
            previewUrl={information.previewUrl}
            onSeek={seekTo}
          />
          <Row>
            <CinemaButton
              label="Back 10 seconds (←)"
              disabled={disabled}
              onClick={() => seekTo(displayedTime - 10)}
            >
              <RotateCcw size={20} />
            </CinemaButton>
            <CinemaButton
              label={playing ? 'Pause' : 'Play'}
              disabled={disabled}
              onClick={() => {
                submit({ action: playing ? 'pause' : 'play' })
              }}
            >
              {playing ? <Pause size={22} /> : <Play size={22} />}
            </CinemaButton>
            <CinemaButton
              label="Forward 10 seconds (→)"
              disabled={disabled}
              onClick={() => seekTo(displayedTime + 10)}
            >
              <RotateCw size={20} />
            </CinemaButton>
            <CinemaButton
              label="Stop"
              disabled={disabled}
              onClick={() => {
                submit({ action: 'stop' })
              }}
            >
              <Square size={18} />
            </CinemaButton>
            <CinemaButton
              label={volume ? 'Mute (M)' : 'Unmute (M)'}
              disabled={disabled || !volumeSupported}
              onClick={mute}
            >
              {volume ? <Volume2 size={20} /> : <VolumeX size={20} />}
            </CinemaButton>
            <VolumeWrap>
              <Slider
                key={volumeScope}
                aria-label="Video volume"
                aria-valuetext={`${Math.round(volume * 100)}%`}
                min={0}
                max={1}
                step={0.01}
                value={[volume]}
                disabled={disabled || !volumeSupported}
                onValueChange={([value]) =>
                  setVolumeDraft({ scope: volumeScope, value })
                }
                onValueCommit={([value]) => {
                  setVolumeDraft(null)
                  if (!cancelledVolume.current)
                    submit({ action: 'volume', volume: value })
                }}
                onPointerDown={() => {
                  cancelledVolume.current = false
                }}
                onPointerCancel={() => {
                  cancelledVolume.current = true
                  setVolumeDraft(null)
                }}
                onKeyDown={() => {
                  cancelledVolume.current = false
                }}
              />
            </VolumeWrap>
            <PlaybackDestinationSelector />
            <CinemaButton
              label="Chapters"
              aria-expanded={panel === 'chapters'}
              onClick={() => {
                wake()
                setPanel(panel === 'chapters' ? null : 'chapters')
              }}
            >
              <ListVideo size={20} />
            </CinemaButton>
            <CinemaButton
              label="Settings"
              aria-expanded={panel === 'settings'}
              onClick={() => {
                wake()
                setPanel(panel === 'settings' ? null : 'settings')
              }}
            >
              <Settings2 size={20} />
            </CinemaButton>
            <CinemaButton
              label={
                fullscreenActive ? 'Exit fullscreen (F)' : 'Fullscreen (F)'
              }
              onClick={() => {
                fullscreen()
              }}
            >
              <Maximize size={20} />
            </CinemaButton>
          </Row>
          {error ? <Notice role="alert">{error}</Notice> : null}
          {!volumeSupported ? (
            <Notice>Volume is controlled on the selected destination.</Notice>
          ) : null}
        </Console>
      </Overlay>
    </Dialog>
  )
}
