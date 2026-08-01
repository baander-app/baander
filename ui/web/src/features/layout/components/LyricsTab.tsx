import styled, { css } from 'styled-components'
import React, { useState, useEffect, useRef, useMemo, useCallback } from 'react'
import { motion } from 'motion/react'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { useCurrentTime } from '@/features/player/stores/player-time-tracker'
import { Button } from '@/shared/components/ui/button'
import { Skeleton } from '@/shared/components/ui/skeleton'
import {
  useGetLyricsSongLyrics,
  usePostLyricsSongLyricsFetch,
  getGetLyricsSongLyricsQueryKey,
} from '@/shared/api-client/gen/endpoints'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Download, Maximize2, Music } from 'lucide-react'
import { AudioLyricSynchronizer, Lrc } from '@/shared/lib/lyrics'
import { useLyricsFullscreenStore } from '../stores/lyrics-fullscreen-store'

interface CachedLyrics {
  plainLyrics?: string | null
  syncedLyrics?: string | null
  source?: string | null
  isInstrumental?: boolean
}

const TitleSkeleton = styled(Skeleton)`
  height: 1rem;
  width: 60%;
`

const SubtitleSkeleton = styled(Skeleton)`
  height: 0.75rem;
  width: 40%;
`

const LineSkeleton = styled(Skeleton)`
  height: 1rem;
  width: 100%;
`

const EmptyState = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  padding: 3rem 0;
`

const EmptyText = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const LoadingSkeletons = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 0 0.25rem;
`

const LoadingHeader = styled.div`
  margin-bottom: 0.5rem;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.25rem;
`

const FetchState = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.75rem;
  padding: 3rem 0;
`

const InstrumentalState = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  padding: 3rem 0;
`

const SyncedContainer = styled.div`
  display: flex;
  height: 100%;
  flex-direction: column;
`

const Toolbar = styled.div`
  display: flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: space-between;
  padding-bottom: 0.5rem;
`

const ToolbarLabel = styled.p`
  font-size: 0.75rem;
  color: color-mix(in srgb, var(--color-muted-foreground) 60%, transparent);
`

const ScrollContainer = styled.div`
  position: relative;
  flex: 1;
  overflow-y: auto;
  scroll-behavior: smooth;
`

const LyricLineContainer = styled.div`
  cursor: pointer;
  padding: 0.375rem 0.5rem;
  text-align: center;
  font-size: 1.125rem;
  line-height: 1.625;
  transition: color 300ms ease;
`

const LineText = styled.span<{ $active: boolean; $past: boolean }>`
  ${({ $active, $past }) =>
    $active
      ? css`color: var(--color-foreground); font-weight: 600;`
      : $past
        ? css`color: color-mix(in srgb, var(--color-muted-foreground) 60%, transparent);`
        : css`color: color-mix(in srgb, var(--color-muted-foreground) 35%, transparent);`}
`

const SourceLabel = styled.p`
  flex-shrink: 0;
  padding-top: 0.25rem;
  text-align: center;
  font-size: 0.75rem;
  color: color-mix(in srgb, var(--color-muted-foreground) 40%, transparent);
`

const PlainContainer = styled.div`
  display: flex;
  height: 100%;
  flex-direction: column;
`

const PlainScroll = styled.div`
  flex: 1;
  overflow-y: auto;
`

const PlainText = styled.pre`
  white-space: pre-wrap;
  font-family: inherit;
  font-size: 0.875rem;
  line-height: 1.625;
  color: color-mix(in srgb, var(--color-foreground) 80%, transparent);
  margin: 0;
`

export function LyricsTab() {
  const currentTrack = usePlayerStore((s) => s.currentTrack)
  const publicId = currentTrack?.publicId ?? ''
  const queryClient = useQueryClient()
  const toggleFullscreen = useLyricsFullscreenStore((s) => s.toggle)

  const { data: lyricsData, isLoading } = useGetLyricsSongLyrics(publicId, {
    query: { enabled: !!publicId },
  })

  const fetchMutation = usePostLyricsSongLyricsFetch({
    mutation: {
      onSuccess: (_data, variables) => {
        queryClient.invalidateQueries({ queryKey: getGetLyricsSongLyricsQueryKey(variables.publicId) })
        toast.success('Lyrics fetched')
      },
      onError: () => {
        toast.error('No lyrics found')
      },
    },
  })

  const handleFetch = useCallback(() => {
    if (publicId) fetchMutation.mutate({ publicId })
  }, [fetchMutation, publicId])

  if (!currentTrack) {
    return (
      <EmptyState>
        <EmptyText>No track playing</EmptyText>
      </EmptyState>
    )
  }

  const lyrics = extractLyrics(lyricsData)

  if (isLoading) {
    return (
      <LoadingSkeletons>
        <LoadingHeader>
          <TitleSkeleton />
          <SubtitleSkeleton />
        </LoadingHeader>
        {Array.from({ length: 8 }).map((_, i) => (
          <LineSkeleton key={i} style={{ width: `${60 + ((i * 37 + 13) % 40)}%` }} />
        ))}
      </LoadingSkeletons>
    )
  }

  if (!lyrics || (!lyrics.plainLyrics && !lyrics.syncedLyrics)) {
    return (
      <FetchState>
        <EmptyText>No lyrics cached</EmptyText>
        <Button size="sm" onClick={handleFetch} disabled={fetchMutation.isPending}>
          <Download size={14} />
          {fetchMutation.isPending ? 'Fetching…' : 'Fetch from LRCLIB'}
        </Button>
      </FetchState>
    )
  }

  if (lyrics.isInstrumental && !lyrics.plainLyrics && !lyrics.syncedLyrics) {
    return (
      <InstrumentalState>
        <Music size={20} style={{ color: 'color-mix(in srgb, var(--color-muted-foreground) 40%, transparent)' }} />
        <EmptyText>Instrumental</EmptyText>
      </InstrumentalState>
    )
  }

  if (lyrics.syncedLyrics) {
    return (
      <SyncedLyricsView
        syncedLyrics={lyrics.syncedLyrics}
        source={lyrics.source}
        onFullscreen={toggleFullscreen}
      />
    )
  }

  return (
    <PlainLyricsView
      plainLyrics={lyrics.plainLyrics!}
      source={lyrics.source}
      onFullscreen={toggleFullscreen}
    />
  )
}

// -- Synced lyrics with auto-scroll and line highlighting --

function SyncedLyricsView({
  syncedLyrics,
  source,
  onFullscreen,
}: {
  syncedLyrics: string
  source?: string | null
  onFullscreen: () => void
}) {
  const currentTime = useCurrentTime()
  const seekTo = usePlayerStore((s) => s.seekTo)
  const scrollRef = useRef<HTMLDivElement>(null)
  const lineRefs = useRef<(HTMLDivElement | null)[]>([])
  const [userScrolling, setUserScrolling] = useState(false)
  const userScrollTimerRef = useRef<number | undefined>(undefined)
  const [spacerHeight, setSpacerHeight] = useState(0)

  const synchronizer = useMemo(() => {
    const lrc = Lrc.parse(syncedLyrics)
    return new AudioLyricSynchronizer(lrc)
  }, [syncedLyrics])

  const lines = synchronizer.getLyrics()

  useEffect(() => {
    synchronizer.timeUpdate(currentTime)
  }, [currentTime, synchronizer])

  const activeIndex = synchronizer.curIndex()

  // Measure the scroll container so spacers match half its height
  useEffect(() => {
    const container = scrollRef.current
    if (!container) return
    const observer = new ResizeObserver(([entry]) => {
      setSpacerHeight(Math.floor(entry.contentRect.height / 2))
    })
    observer.observe(container)
    return () => observer.disconnect()
  }, [])

  // Auto-scroll to keep active line vertically centered
  useEffect(() => {
    if (userScrolling) return
    const container = scrollRef.current
    if (!container || lines.length === 0) return

    const idx = activeIndex >= 0 ? activeIndex : 0
    const el = lineRefs.current[idx]
    if (!el) return

    const targetScroll =
      el.offsetTop - container.clientHeight / 2 + el.clientHeight / 2

    container.scrollTo({ top: targetScroll, behavior: 'smooth' })
  }, [activeIndex, userScrolling, spacerHeight, lines.length])

  const handleScroll = useCallback(() => {
    setUserScrolling(true)
    if (userScrollTimerRef.current) clearTimeout(userScrollTimerRef.current)
    userScrollTimerRef.current = setTimeout(() => setUserScrolling(false), 4000)
  }, [])

  return (
    <SyncedContainer>
      {/* Toolbar */}
      <Toolbar>
        <ToolbarLabel>
          {userScrolling ? 'Scrolling paused' : `${lines.length} lines synced`}
        </ToolbarLabel>
        <Button variant="ghost" size="icon-xs" onClick={onFullscreen} aria-label="Fullscreen lyrics">
          <Maximize2 size={14} />
        </Button>
      </Toolbar>

      {/* Scroll container — fills remaining space */}
      <ScrollContainer
        ref={scrollRef}
        onScroll={handleScroll}
      >
        {/* Top spacer: half the container so first line can center */}
        <div style={{ height: spacerHeight }} />

        {lines.map((line, i) => (
          <LyricLine
            key={i}
            ref={(el) => { lineRefs.current[i] = el }}
            content={line.content}
            active={i === activeIndex}
            past={i < activeIndex}
            onClick={line.timestamp >= 0 ? () => seekTo(line.timestamp) : undefined}
          />
        ))}

        {/* Bottom spacer: half the container so last line can center */}
        <div style={{ height: spacerHeight }} />
      </ScrollContainer>

      {source && (
        <SourceLabel>Source: {source}</SourceLabel>
      )}
    </SyncedContainer>
  )
}

const LyricLine = motion.create(
  React.forwardRef<HTMLDivElement, {
    content: string
    active: boolean
    past: boolean
    onClick?: () => void
  }>(
    function LyricLineInner({ content, active, past, onClick }, ref) {
      return (
        <LyricLineContainer
          ref={ref}
          onClick={onClick}
        >
          <LineText $active={active} $past={past}>
            {content || '\u00A0'}
          </LineText>
        </LyricLineContainer>
      )
    },
  ),
)

// -- Plain lyrics (no sync) --

function PlainLyricsView({
  plainLyrics,
  source,
  onFullscreen,
}: {
  plainLyrics: string
  source?: string | null
  onFullscreen: () => void
}) {
  return (
    <PlainContainer>
      <Toolbar>
        <ToolbarLabel>Unsynced lyrics</ToolbarLabel>
        <Button variant="ghost" size="icon-xs" onClick={onFullscreen} aria-label="Fullscreen lyrics">
          <Maximize2 size={14} />
        </Button>
      </Toolbar>
      <PlainScroll>
        <PlainText>{plainLyrics}</PlainText>
      </PlainScroll>
      {source && (
        <SourceLabel>Source: {source}</SourceLabel>
      )}
    </PlainContainer>
  )
}

// -- Helpers --

function extractLyrics(data: unknown): CachedLyrics | null {
  const d = (data as any)?.data
  if (!d || (typeof d === 'object' && Object.keys(d).length === 0)) return null
  return d as CachedLyrics
}
