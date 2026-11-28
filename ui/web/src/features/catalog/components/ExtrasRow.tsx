import { useRef } from 'react'
import { useVirtualizer } from '@tanstack/react-virtual'
import { Play } from 'lucide-react'
import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import { ProgressBar } from '@/shared/components/progress-bar'
import type { VideoExtraResource } from '@/shared/api-client/gen/endpoints'
import { textRole } from '@/shared/theme'
import type { DetailPlaybackOptions } from './use-video-detail-playback'

const Section = styled.section`
  display: grid;
  gap: var(--space-md);
  min-width: 0;
`

const Heading = styled.h2`
  ${textRole('title')}
`

const Scroll = styled.div`
  overflow-x: auto;
  padding: 0.25rem;
`

const Shelf = styled.ul`
  position: relative;
  display: flex;
  gap: var(--space-md);
  padding: 0;
  list-style: none;
`

const Extra = styled.li`
  flex: 0 0 max(12rem, 20%);
  display: grid;
  gap: var(--space-sm);
  align-content: start;
  padding: var(--space-md);
  background: var(--color-card);
  border-radius: var(--radius-lg);
`

const EXTRA_WIDTH = 192

const VirtualExtra = styled(Extra)`
  width: ${EXTRA_WIDTH}px;
  position: absolute;
  inset-block: 0;
`

const ExtraName = styled.span`
  ${textRole('body')}
  text-transform: capitalize;
`

const Action = styled(Button)`
  justify-self: start;
`

function videoExtraLabel(kind: string) {
  return kind.replaceAll('_', ' ').replace(/^./, initial => initial.toUpperCase())
}

interface ExtrasRowProps {
  extras: VideoExtraResource[]
  onPlay: (intent: DetailPlaybackOptions) => void
}

export function ExtrasRow({ extras, onPlay }: ExtrasRowProps) {
  const scrollRef = useRef<HTMLDivElement>(null)
  const virtual = extras.length > 50
  const tiles = useVirtualizer({
    count: extras.length,
    enabled: virtual,
    getScrollElement: () => scrollRef.current,
    horizontal: true,
    estimateSize: () => EXTRA_WIDTH,
    gap: 16,
    overscan: 3,
    getItemKey: index => extras[index]?.placementPublicId ?? index,
  })

  if (!extras.length) return null

  function itemContent(extra: VideoExtraResource) {
    const position = extra.positionSeconds ?? 0
    const label = videoExtraLabel(extra.kind)
    const action = position > 0 ? 'Resume' : 'Play'
    return (
      <>
        <ExtraName>{label}</ExtraName>
        {position > 0 && extra.duration
          ? <ProgressBar value={100 * position / extra.duration} label="Progress" />
          : null}
        <Action
          variant="secondary"
          aria-label={`${action} ${label}`}
          onClick={() => onPlay({
            titleKind: 'extra',
            titleId: extra.placementPublicId,
            videoId: extra.videoPublicId,
            startSeconds: position,
            title: label,
            duration: extra.duration,
          })}
        >
          <Play size={16} aria-hidden="true" />
          {action}
        </Action>
      </>
    )
  }

  return (
    <Section aria-label="Extras">
      <Heading>Extras</Heading>
      <Scroll
        ref={scrollRef}
        role={virtual ? 'group' : undefined}
        tabIndex={virtual ? 0 : undefined}
        aria-label={virtual ? 'Extra files' : undefined}
      >
        {virtual ? (
          <Shelf style={{ width: tiles.getTotalSize(), minHeight: '12rem' }}>
            {tiles.getVirtualItems().map(({ index, start, key }) => {
              const extra = extras[index]
              return extra ? (
                <VirtualExtra key={key} style={{ insetInlineStart: start }}>
                  {itemContent(extra)}
                </VirtualExtra>
              ) : null
            })}
          </Shelf>
        ) : (
          <Shelf>
            {extras.map(extra => <Extra key={extra.placementPublicId}>{itemContent(extra)}</Extra>)}
          </Shelf>
        )}
      </Scroll>
    </Section>
  )
}
