import styled, { css } from 'styled-components'
import { useState, useEffect, useRef, useMemo, useCallback } from 'react'
import { motion, AnimatePresence } from 'motion/react'
import { createPortal } from 'react-dom'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { useCurrentTime } from '@/features/player/stores/player-time-tracker'
import { Button } from '@/shared/components/ui/button'
import { useLyricsFullscreenStore } from '../stores/lyrics-fullscreen-store'
import {
  useGetLyricsSongLyrics,
  useGetAlbumShow,
} from '@/shared/api-client/gen/endpoints'
import { AudioLyricSynchronizer, Lrc } from '@/shared/lib/lyrics'
import { decode as decodeBlurhash } from 'blurhash'
import { Minimize, Music, Play, Pause, SkipBack, SkipForward } from 'lucide-react'
import { VisualizerHost } from '@/features/visualizer/components/VisualizerHost'
import { registerVisualizerRenderers } from '@/features/visualizer/register-visualizer-renderers'
import { usePaletteStore } from '@/features/visualizer/stores/palette-store'
import { extractPalette } from '@/features/visualizer/utils/extract-palette'
import { useEqBandsStore } from '@/features/equalizer/stores/eq-bands-store'
import { isEngineMode, ENGINE_MODES } from '@/features/visualizer/types'

interface CachedLyrics {
  plainLyrics?: string | null
  syncedLyrics?: string | null
  source?: string | null
  isInstrumental?: boolean
}

const FullscreenVisualizer = styled(VisualizerHost)`
  position: absolute;
  inset: 0;
`

const PlayPauseButton = styled(Button)`
  height: 3.5rem;
  width: 3.5rem;
  border-radius: 9999px;
`

const FullscreenOverlay = styled(motion.div)`
  position: fixed;
  inset: 0;
  z-index: 100;
  display: flex;
  flex-direction: column;
`

const BackgroundLayer = styled.div`
  position: absolute;
  inset: 0;
  overflow: hidden;
  background-color: var(--color-background);
`

const BackgroundOverlay = styled.div`
  position: absolute;
  inset: 0;
  background-color: color-mix(in srgb, var(--color-background) 80%, transparent);
  backdrop-filter: blur(4px);
`

const ContentLayer = styled.div`
  position: relative;
  z-index: 10;
  display: flex;
  min-height: 0;
  flex: 1;
  flex-direction: column;
`

const TopBar = styled.div`
  display: flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: space-between;
  padding: 1rem 1.5rem;
`

const TrackInfo = styled.div`
  min-width: 0;
  flex: 1;
`

const TrackTitle = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 1.125rem;
  font-weight: 500;
  color: var(--color-foreground);
  margin: 0;
`

const TrackSubtitle = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
  margin: 0;
`

const ModeButtons = styled.div`
  display: flex;
  align-items: center;
  gap: 0.25rem;
`

const LyricsArea = styled.div`
  flex: 1;
  overflow: hidden;
`

const PlainLyricsCenter = styled.div`
  display: flex;
  height: 100%;
  align-items: center;
  justify-content: center;
  padding: 0 3rem;
`

const PlainLyricsText = styled.pre`
  max-width: 42rem;
  white-space: pre-wrap;
  text-align: center;
  font-family: inherit;
  font-size: 1.25rem;
  line-height: 1.625;
  color: color-mix(in srgb, var(--color-foreground) 70%, transparent);
  margin: 0;
`

const CenteredMessage = styled.div`
  display: flex;
  height: 100%;
  align-items: center;
  justify-content: center;
`

const MessageText = styled.p`
  font-size: 1.125rem;
  color: var(--color-muted-foreground);
  margin: 0;
`

const InstrumentalMessage = styled.div`
  display: flex;
  height: 100%;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
`

const TransportSection = styled.div`
  display: flex;
  flex-shrink: 0;
  flex-direction: column;
  align-items: center;
  gap: 0.75rem;
  padding: 0.5rem 1.5rem 1.5rem;
`

const ProgressRow = styled.div`
  display: flex;
  width: 100%;
  max-width: 42rem;
  align-items: center;
  gap: 0.75rem;
`

const TimeLabel = styled.span`
  width: 2.5rem;
  font-size: 0.75rem;
  font-variant-numeric: tabular-nums;
  color: var(--color-muted-foreground);
`

const TimeLabelRight = styled.span`
  width: 2.5rem;
  font-size: 0.75rem;
  font-variant-numeric: tabular-nums;
  color: var(--color-muted-foreground);
`

const ProgressBarTrack = styled.div`
  position: relative;
  height: 0.25rem;
  flex: 1;
  border-radius: 9999px;
  background-color: var(--color-muted);
`

const ProgressBarFill = styled.div`
  position: absolute;
  left: 0;
  top: 0;
  height: 100%;
  border-radius: 9999px;
  background-color: color-mix(in srgb, var(--color-foreground) 60%, transparent);
  transition: width 200ms linear;
`

const PlaybackButtons = styled.div`
  display: flex;
  align-items: center;
  gap: 1rem;
`

const FullscreenScroll = styled.div`
  height: 100%;
  overflow-y: auto;
  scroll-behavior: smooth;
`

const FullscreenLine = styled.div`
  cursor: pointer;
  padding: 0.5rem 2rem;
  text-align: center;
`

const FullscreenLineText = styled.span<{ $active: boolean; $past: boolean }>`
  display: inline-block;
  font-size: 1.5rem;
  line-height: 1.625;
  transition: all 500ms ease;

  @media (min-width: 640px) {
    font-size: 1.875rem;
  }

  @media (min-width: 1024px) {
    font-size: 2.25rem;
  }

  ${({ $active, $past }) =>
    $active
      ? css`color: var(--color-foreground); font-weight: 700; opacity: 1; transform: scale(1);`
      : $past
        ? css`color: color-mix(in srgb, var(--color-muted-foreground) 50%, transparent); opacity: 0.5; transform: scale(1);`
        : css`color: color-mix(in srgb, var(--color-muted-foreground) 30%, transparent); opacity: 0.3; transform: scale(1);`}
`

const BlurhashCanvas = styled.canvas`
  position: absolute;
  inset: 0;
  height: 100%;
  width: 100%;
  transform: scale(3);
  filter: blur(40px);
  image-rendering: auto;
`

export function LyricsFullscreenOverlay() {
  const isOpen = useLyricsFullscreenStore((s) => s.isOpen)
  const setOpen = useLyricsFullscreenStore((s) => s.setOpen)

  // Escape key to close
  useEffect(() => {
    if (!isOpen) return
    function handleKey(e: KeyboardEvent) {
      if (e.key === 'Escape') setOpen(false)
    }
    document.addEventListener('keydown', handleKey)
    return () => document.removeEventListener('keydown', handleKey)
  }, [isOpen, setOpen])

  return createPortal(
    <AnimatePresence>
      {isOpen && <FullscreenContent onClose={() => setOpen(false)} />}
    </AnimatePresence>,
    document.body,
  )
}

function BlurhashBackground({ blurhash }: { blurhash: string | null | undefined }) {
  const canvasRef = useRef<HTMLCanvasElement>(null)

  useEffect(() => {
    if (!blurhash || !canvasRef.current) return

    try {
      const width = 32
      const height = 32
      const pixels = decodeBlurhash(blurhash, width, height)
      const canvas = canvasRef.current
      canvas.width = width
      canvas.height = height
      const ctx = canvas.getContext('2d')
      if (!ctx) return
      const imageData = ctx.createImageData(width, height)
      imageData.data.set(pixels)
      ctx.putImageData(imageData, 0, 0)
    } catch {
      // Ignore decode failures
    }
  }, [blurhash])

  if (!blurhash) return null

  return <BlurhashCanvas ref={canvasRef} />
}

function FullscreenContent({ onClose }: { onClose: () => void }) {
  registerVisualizerRenderers()

  const currentTrack = usePlayerStore((s) => s.currentTrack)
  const publicId = currentTrack?.publicId ?? ''
  const albumPublicId = currentTrack?.albumPublicId ?? ''
  const isPlaying = usePlayerStore((s) => s.isPlaying)
  const setIsPlaying = usePlayerStore((s) => s.setIsPlaying)
  const playNext = usePlayerStore((s) => s.playNext)
  const playPrevious = usePlayerStore((s) => s.playPrevious)
  const currentTime = useCurrentTime()
  const duration = usePlayerStore((s) => s.duration)

  const visualizerMode = useEqBandsStore((s) => s.visualizerMode)
  const setVisualizerMode = useEqBandsStore((s) => s.setVisualizerMode)

  const { data: lyricsData } = useGetLyricsSongLyrics(publicId, {
    query: { enabled: !!publicId },
  })

  const { data: albumData } = useGetAlbumShow(albumPublicId, {
    query: { enabled: !!albumPublicId },
  })

  const blurhash = (albumData as any)?.data?.coverImage?.blurhash ?? null

  const lyrics = extractLyrics(lyricsData)
  const hasSynced = !!lyrics?.syncedLyrics

  // Extract color palette when album changes
  useEffect(() => {
    if (!albumPublicId) return
    const store = usePaletteStore.getState()
    if (store.getPalette(albumPublicId)) return // already cached
    if (store.extracting.has(albumPublicId)) return // in progress

    const coverUrl = (albumData as any)?.data?.coverImage?.url
    if (!coverUrl) return

    store.startExtraction(albumPublicId)
    extractPalette(`/api/albums/${albumPublicId}/cover`).then((palette) => {
      if (palette) {
        store.setPalette(albumPublicId, palette)
      } else {
        store.removePalette(albumPublicId)
      }
    })
  }, [albumPublicId, albumData])

  return (
    <FullscreenOverlay
      initial={{ opacity: 0 }}
      animate={{ opacity: 1 }}
      exit={{ opacity: 0 }}
      transition={{ duration: 0.25, ease: 'easeOut' }}
    >
      {/* Background layer with visualizer */}
      <BackgroundLayer>
        {isEngineMode(visualizerMode) ? (
          <FullscreenVisualizer
            mode={visualizerMode}
            albumPublicId={albumPublicId}
          />
        ) : (
          <BlurhashBackground blurhash={blurhash} />
        )}
        <BackgroundOverlay />
      </BackgroundLayer>

      {/* Content layer */}
      <ContentLayer>
      {/* Top bar */}
      <TopBar>
        <TrackInfo>
          <TrackTitle>{currentTrack?.title ?? 'Unknown'}</TrackTitle>
          <TrackSubtitle>
            {currentTrack?.artistName}
            {currentTrack?.artistName && currentTrack?.albumName ? ' · ' : ''}
            {currentTrack?.albumName}
          </TrackSubtitle>
        </TrackInfo>
        <ModeButtons>
          {ENGINE_MODES.map((mode) => (
            <Button
              key={mode}
              variant={visualizerMode === mode ? 'secondary' : 'ghost'}
              size="xs"
              onClick={() => setVisualizerMode(mode)}
              aria-pressed={visualizerMode === mode}
            >
              {mode === 'enhanced-spectrum' ? 'Enhanced' :
               mode === 'circular' ? 'Circular' :
               mode === 'spectrogram' ? 'Spectrogram' : 'Particles'}
            </Button>
          ))}
        </ModeButtons>
        <Button variant="ghost" size="icon" onClick={onClose} aria-label="Exit fullscreen lyrics">
          <Minimize size={18} />
        </Button>
      </TopBar>

      {/* Lyrics area */}
      <LyricsArea>
        {hasSynced ? (
          <FullscreenSyncedLyrics syncedLyrics={lyrics!.syncedLyrics!} />
        ) : lyrics?.plainLyrics ? (
          <PlainLyricsCenter>
            <PlainLyricsText>{lyrics.plainLyrics}</PlainLyricsText>
          </PlainLyricsCenter>
        ) : lyrics?.isInstrumental ? (
          <InstrumentalMessage>
            <Music size={48} style={{ color: 'color-mix(in srgb, var(--color-muted-foreground) 30%, transparent)' }} />
            <MessageText>Instrumental</MessageText>
          </InstrumentalMessage>
        ) : (
          <CenteredMessage>
            <MessageText>No lyrics available</MessageText>
          </CenteredMessage>
        )}
      </LyricsArea>

      {/* Transport controls */}
      <TransportSection>
        {/* Progress bar */}
        <ProgressRow>
          <TimeLabel style={{ textAlign: 'right' }}>{formatTime(currentTime)}</TimeLabel>
          <ProgressBarTrack>
            <ProgressBarFill
              style={{ width: duration > 0 ? `${(currentTime / duration) * 100}%` : '0%' }}
            />
          </ProgressBarTrack>
          <TimeLabelRight>{formatTime(duration)}</TimeLabelRight>
        </ProgressRow>

        {/* Playback buttons */}
        <PlaybackButtons>
          <Button variant="ghost" size="icon-lg" onClick={playPrevious} aria-label="Previous">
            <SkipBack size={20} />
          </Button>
          <PlayPauseButton
            size="icon-lg"
            onClick={() => setIsPlaying(!isPlaying)}
            aria-label={isPlaying ? 'Pause' : 'Play'}
          >
            {isPlaying
              ? <Pause size={24} fill="currentColor" />
              : <Play size={24} fill="currentColor" />}
          </PlayPauseButton>
          <Button variant="ghost" size="icon-lg" onClick={playNext} aria-label="Next">
            <SkipForward size={20} />
          </Button>
        </PlaybackButtons>
      </TransportSection>
      </ContentLayer>
    </FullscreenOverlay>
  )
}

// -- Synced lyrics in fullscreen --

function FullscreenSyncedLyrics({ syncedLyrics }: { syncedLyrics: string }) {
  const currentTime = useCurrentTime()
  const seekTo = usePlayerStore((s) => s.seekTo)
  const containerRef = useRef<HTMLDivElement>(null)
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

  // Measure container for centering spacers
  useEffect(() => {
    const container = containerRef.current
    if (!container) return
    const observer = new ResizeObserver(([entry]) => {
      setSpacerHeight(Math.floor(entry.contentRect.height / 2))
    })
    observer.observe(container)
    return () => observer.disconnect()
  }, [])

  // Auto-scroll to active line — center it in viewport
  useEffect(() => {
    if (userScrolling) return
    const container = containerRef.current
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
    <FullscreenScroll
      ref={containerRef}
      onScroll={handleScroll}
    >
      <div style={{ height: spacerHeight }} />

      {lines.map((line, i) => (
        <FullscreenLine
          key={i}
          ref={(el) => { lineRefs.current[i] = el }}
          onClick={() => {
            if (line.timestamp >= 0) {
              seekTo(line.timestamp)
            }
          }}
        >
          <FullscreenLineText
            $active={i === activeIndex}
            $past={i < activeIndex}
          >
            {line.content || '\u00A0'}
          </FullscreenLineText>
        </FullscreenLine>
      ))}

      <div style={{ height: spacerHeight }} />
    </FullscreenScroll>
  )
}

// -- Helpers --

function formatTime(seconds: number): string {
  if (isNaN(seconds) || !isFinite(seconds) || seconds < 0) return '0:00'
  const mins = Math.floor(seconds / 60)
  const secs = Math.floor(seconds % 60)
  return `${mins}:${secs.toString().padStart(2, '0')}`
}

function extractLyrics(data: unknown): CachedLyrics | null {
  const d = (data as any)?.data
  if (!d || (typeof d === 'object' && Object.keys(d).length === 0)) return null
  return d as CachedLyrics
}
