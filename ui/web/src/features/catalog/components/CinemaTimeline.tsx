import { useRef, useState } from 'react'
import styled from 'styled-components'
import { Slider } from '@/shared/components/ui/slider'
import { textRole } from '@/shared/theme'
import type { VideoPlaybackInformation } from '../hooks/use-video-playback-information'
import { formatVideoTime } from '../utils/video-time'

const Timeline = styled.div`
  position: relative;
  width: 100%;
  min-width: 0;
  padding: var(--space-sm) 0;
  &::before {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    top: calc(50% - 0.125rem);
    height: 0.25rem;
    background: var(--color-input-boundary);
  }
  [data-slot='slider-track'] {
    background: transparent;
  }
  [data-slot='slider'] {
    height: 1.5rem;
  }
`
const Marks = styled.div`
  position: absolute;
  inset: 0;
  pointer-events: none;
  span {
    position: absolute;
    top: calc(50% - 0.3rem);
    height: 0.6rem;
    width: 2px;
    background: var(--color-foreground);
  }
`
const Preview = styled.div`
  position: absolute;
  bottom: 100%;
  transform: translateX(-50%);
  max-width: 100%;
  padding: var(--space-sm);
  background: var(--color-popover);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  color: var(--color-popover-foreground);
  text-align: center;
  pointer-events: none;
  ${textRole('timecode')}
`
const Buffered = styled.div`
  position: absolute;
  left: 0;
  top: calc(50% - 0.125rem);
  height: 0.25rem;
  background: var(--color-muted-foreground);
  opacity: 0.5;
  pointer-events: none;
`

export function CinemaTimeline({
  videoId,
  time,
  duration,
  buffered,
  disabled,
  information,
  previewUrl,
  onSeek
}: {
  videoId: string
  time: number
  duration: number
  buffered: number
  disabled: boolean
  information?: VideoPlaybackInformation
  previewUrl: string | null
  onSeek: (position: number) => void
}) {
  const [draft, setDraft] = useState<{
    videoId: string
    position: number
  } | null>(null)
  const [hover, setHover] = useState<number | null>(null)
  const cancelled = useRef(false)
  const draftTime = draft?.videoId === videoId ? draft.position : null
  const displayed = draftTime ?? time
  const previewTime = draftTime ?? hover
  const sprite = information?.preview
  const frame =
    sprite && previewTime !== null
      ? Math.min(
          sprite.count - 1,
          Math.max(0, Math.floor(previewTime / sprite.interval))
        )
      : 0
  const chapter = information?.chapters.find(
    (item) =>
      previewTime !== null &&
      previewTime >= item.start &&
      previewTime < item.end
  )
  const commit = (position: number) => {
    setDraft(null)
    if (!cancelled.current) onSeek(position)
  }
  return (
    <Timeline
      onPointerMove={(event) => {
        const bounds = event.currentTarget.getBoundingClientRect()
        if (bounds.width > 0)
          setHover(
            Math.max(
              0,
              Math.min(
                duration,
                ((event.clientX - bounds.left) / bounds.width) * duration
              )
            )
          )
      }}
      onPointerLeave={() => setHover(null)}
    >
      <Buffered
        style={{
          width: `${duration > 0 ? Math.min(100, (buffered / duration) * 100) : 0}%`
        }}
      />
      <Slider
        key={videoId}
        aria-label="Seek movie"
        aria-valuetext={`${formatVideoTime(displayed)} of ${formatVideoTime(duration)}`}
        min={0}
        max={duration || 1}
        step={1}
        value={[Math.max(0, Math.min(duration, displayed))]}
        disabled={disabled || duration <= 0}
        onValueChange={([position]) => setDraft({ videoId, position })}
        onValueCommit={([position]) => commit(position)}
        onPointerDown={() => {
          cancelled.current = false
        }}
        onPointerCancel={() => {
          cancelled.current = true
          setDraft(null)
          setHover(null)
        }}
        onKeyDown={() => {
          cancelled.current = false
        }}
        onBlur={() => {
          if (draftTime !== null) {
            commit(draftTime)
            cancelled.current = true
          }
        }}
      />
      <Marks aria-hidden="true">
        {information?.chapters
          .filter((chapter) => chapter.start > 0 && chapter.start < duration)
          .map((chapter) => (
            <span
              key={chapter.start}
              style={{ left: `${(chapter.start / duration) * 100}%` }}
            />
          ))}
      </Marks>
      {previewTime !== null ? (
        <Preview
          style={{
            left: `${duration ? Math.max(12, Math.min(88, (previewTime / duration) * 100)) : 50}%`
          }}
        >
          {previewUrl && sprite && sprite.count > 0 ? (
            <div
              role="img"
              aria-label={`Movie preview at ${formatVideoTime(previewTime)}`}
              style={{
                width: sprite.width,
                height: sprite.height,
                backgroundImage: `url("${previewUrl}")`,
                backgroundPosition: `-${(frame % sprite.columns) * sprite.width}px -${Math.floor(frame / sprite.columns) * sprite.height}px`
              }}
            />
          ) : null}
          {formatVideoTime(previewTime)}
          {chapter ? ` · ${chapter.title}` : ''}
        </Preview>
      ) : null}
    </Timeline>
  )
}
