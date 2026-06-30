import styled, { css } from 'styled-components'
import { useRef, useCallback, useState } from 'react'
import { useVirtualizer } from '@tanstack/react-virtual'
import { Play } from 'lucide-react'
import { usePlayerStore, type Track } from '@/features/player/stores/player-store'
import { useSelectionStore } from '../stores/selection-store'
import { SongContextMenu } from './menus/SongContextMenu'
import { formatDuration } from '@/shared/utils/format-duration'
import { focusVisibleRing, interactiveTransition } from '@/shared/theme'

const EmptyContainer = styled.div`
  display: flex;
  flex: 1;
  align-items: center;
  justify-content: center;
`

const EmptyText = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const ScrollContainer = styled.div`
  flex: 1;
  overflow-y: auto;
`

const TrackRow = styled.div<{ $isPlaying: boolean }>`
  position: absolute;
  top: 0;
  left: 0;
  display: flex;
  width: 100%;
  cursor: default;
  align-items: center;
  padding: 0 1rem;

  ${({ $isPlaying }) =>
    $isPlaying
      ? css`color: var(--color-foreground);`
      : css`
        color: color-mix(in srgb, var(--color-foreground) 80%, transparent);
        &:hover {
          background-color: color-mix(in srgb, var(--color-accent) 40%, transparent);
        }
      `}
`

const TrackNumber = styled.span`
  width: 2.5rem;
  flex-shrink: 0;
  text-align: right;
  font-size: 0.75rem;
  font-variant-numeric: tabular-nums;
  color: var(--color-muted-foreground);
`

const PlayIconButton = styled.button`
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--color-foreground);

  &:hover {
    color: var(--color-foreground);
  }
`

const TrackTitle = styled.span`
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  padding: 0 0.5rem;
  font-size: 0.875rem;
  font-weight: 500;
`

const TrackArtist = styled.span`
  width: 25%;
  flex-shrink: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  padding: 0 0.5rem;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const TrackDuration = styled.span`
  width: 4rem;
  flex-shrink: 0;
  text-align: right;
  font-size: 0.75rem;
  font-variant-numeric: tabular-nums;
  color: var(--color-muted-foreground);
`

const EqualizerSvg = styled.svg`
  display: inline-block;
  color: var(--accent-derived, var(--color-primary));
`

const EqualizerBar = styled.rect`
  animation: equalizer-bar 0.6s ease-in-out infinite;
`

interface AlbumTrackListProps {
  songs: Record<string, unknown>[]
  albumTitle: string
  albumPublicId?: string
}

const ROW_HEIGHT = 32

function EqualizerIcon() {
  return (
    <EqualizerSvg width="14" height="14" viewBox="0 0 14 14">
      <EqualizerBar x="1" y="8" width="2.5" height="5" rx="1" style={{ animationDelay: '0s' }} />
      <EqualizerBar x="5.5" y="4" width="2.5" height="9" rx="1" style={{ animationDelay: '0.15s' }} />
      <EqualizerBar x="10" y="6" width="2.5" height="7" rx="1" style={{ animationDelay: '0.3s' }} />
    </EqualizerSvg>
  )
}

export function AlbumTrackList({ songs, albumTitle, albumPublicId }: AlbumTrackListProps) {
  const parentRef = useRef<HTMLDivElement>(null)
  const currentTrack = usePlayerStore((s) => s.currentTrack)
  const playTrack = usePlayerStore((s) => s.playTrack)
  const select = useSelectionStore((s) => s.select)
  const [hoveredIndex, setHoveredIndex] = useState<number | null>(null)

  const virtualizer = useVirtualizer({
    count: songs.length,
    getScrollElement: () => parentRef.current,
    estimateSize: () => ROW_HEIGHT,
    overscan: 30,
    getItemKey: (i) => String(songs[i]?.publicId ?? i),
  })

  const buildTracks = useCallback((): Track[] => {
    return songs.map((s: Record<string, unknown>) => ({
      publicId: String(s.publicId ?? ''),
      title: String(s.title ?? ''),
      artistName: s.artistName ? String(s.artistName) : undefined,
      albumName: albumTitle,
      albumPublicId,
      duration: typeof s.length === 'number' ? s.length : (typeof s.duration === 'number' ? s.duration : undefined),
    }))
  }, [songs, albumTitle, albumPublicId])

  const handlePlayTrack = useCallback(
    (index: number) => {
      const tracks = buildTracks()
      if (tracks[index]) {
        playTrack(tracks[index], tracks)
      }
    },
    [buildTracks, playTrack],
  )

  if (songs.length === 0) {
    return (
      <EmptyContainer>
        <EmptyText>No songs</EmptyText>
      </EmptyContainer>
    )
  }

  return (
    <ScrollContainer ref={parentRef}>
      <div
        style={{
          height: `${virtualizer.getTotalSize()}px`,
          width: '100%',
          position: 'relative',
        }}
      >
        {virtualizer.getVirtualItems().map((virtualRow) => {
          const song = songs[virtualRow.index]
          const publicId = String(song.publicId ?? '')
          const title = String(song.title ?? '')
          const artistName = song.artistName ? String(song.artistName) : undefined
          const duration = typeof song.length === 'number' ? song.length : (typeof song.duration === 'number' ? song.duration : undefined)
          const trackNumber = typeof song.track === 'number' ? song.track : virtualRow.index + 1
          const isPlaying = currentTrack?.publicId === publicId
          const isHovered = hoveredIndex === virtualRow.index

          return (
            <SongContextMenu
              key={publicId}
              song={{
                publicId,
                title,
                artistName,
                albumName: albumTitle,
                duration,
                albumId: song.albumId ? String(song.albumId) : undefined,
                artistId: song.artistId ? String(song.artistId) : undefined,
              }}
            >
              <TrackRow
                $isPlaying={isPlaying}
                style={{
                  height: `${virtualRow.size}px`,
                  transform: `translateY(${virtualRow.start}px)`,
                }}
                onClick={() => select(publicId, 'song')}
                onDoubleClick={() => handlePlayTrack(virtualRow.index)}
                onMouseEnter={() => setHoveredIndex(virtualRow.index)}
                onMouseLeave={() => setHoveredIndex(null)}
              >
                {/* Track number / playing indicator / hover play */}
                <TrackNumber>
                  {isPlaying && !isHovered ? (
                    <EqualizerIcon />
                  ) : isHovered ? (
                    <PlayIconButton
                      onClick={(e) => {
                        e.stopPropagation()
                        handlePlayTrack(virtualRow.index)
                      }}
                      aria-label={`Play ${title}`}
                    >
                      <Play size={10} fill="currentColor" />
                    </PlayIconButton>
                  ) : (
                    trackNumber
                  )}
                </TrackNumber>

                {/* Title */}
                <TrackTitle>{title}</TrackTitle>

                {/* Artist (for compilations) */}
                <TrackArtist>{artistName}</TrackArtist>

                {/* Duration */}
                <TrackDuration>
                  {duration !== undefined ? formatDuration(duration) : '\u2014'}
                </TrackDuration>
              </TrackRow>
            </SongContextMenu>
          )
        })}
      </div>
    </ScrollContainer>
  )
}
