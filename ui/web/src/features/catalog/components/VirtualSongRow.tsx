import styled, { css } from 'styled-components'
import { memo } from 'react'
import { Play } from 'lucide-react'
import { formatDuration } from '@/shared/utils/format-duration'
import { CoverArt } from '@/shared/components/cover-art'
import type { SongEntry } from '@/features/catalog/types'

const Row = styled.div<{ $isPlaying: boolean; $isFocused: boolean }>`
  position: absolute;
  top: 0;
  left: 0;
  display: flex;
  width: 100%;
  cursor: default;
  align-items: center;
  padding: 0 0.5rem;

  ${({ $isPlaying, $isFocused }) =>
    $isPlaying
      ? css`
        background-color: color-mix(in srgb, var(--color-primary) 10%, transparent);
        color: var(--color-primary);
      `
      : $isFocused
        ? css`
          background-color: color-mix(in srgb, var(--color-accent) 20%, transparent);
          color: var(--color-foreground);
        `
        : css`
          color: color-mix(in srgb, var(--color-foreground) 80%, transparent);
          &:hover {
            background-color: color-mix(in srgb, var(--color-accent) 40%, transparent);
          }
        `}
`

const IndexCell = styled.span`
  width: 2.5rem;
  flex-shrink: 0;
  padding-left: 0.25rem;
  text-align: right;
  font-size: 0.75rem;
  font-variant-numeric: tabular-nums;
  color: var(--color-muted-foreground);

  ${Row}:hover & {
    visibility: hidden;
  }
`

const HoverPlayCell = styled.span`
  position: absolute;
  width: 2.5rem;
  flex-shrink: 0;
  padding-left: 0.25rem;
  text-align: right;
  opacity: 0;
  transition: opacity 150ms;

  ${Row}:hover & {
    opacity: 1;
  }
`

const HoverPlayButton = styled.button`
  display: inline-flex;
  height: 1.25rem;
  width: 1.25rem;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-sm);
  border: none;
  background: none;
  cursor: pointer;

  &:hover {
    background-color: var(--color-accent);
  }
`

const CoverThumbnail = styled.span`
  width: 1.25rem;
  height: 1.25rem;
  flex-shrink: 0;
  overflow: hidden;
  border-radius: 2px;
`

const TitleCell = styled.span`
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  padding: 0 0.5rem;
  font-size: 0.875rem;
  font-weight: 500;
`

const MutedCell = styled.span`
  width: 25%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  padding: 0 0.5rem;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const DurationCell = styled.span`
  width: 4rem;
  flex-shrink: 0;
  text-align: right;
  font-size: 0.75rem;
  font-variant-numeric: tabular-nums;
  color: var(--color-muted-foreground);
`

interface VirtualSongRowProps {
  song: SongEntry
  index: number
  isPlaying: boolean
  isFocused: boolean
  onPlay: (index: number) => void
  onClick: (song: SongEntry, index: number) => void
  height: number
  translateY: number
}

export const VirtualSongRow = memo(function VirtualSongRow({
  song,
  index,
  isPlaying,
  isFocused,
  onPlay,
  onClick,
  height,
  translateY,
}: VirtualSongRowProps) {
  return (
    <Row
      $isPlaying={isPlaying}
      $isFocused={isFocused}
      style={{
        height: `${height}px`,
        transform: `translateY(${translateY}px)`,
      }}
      onClick={() => onClick(song, index)}
      onDoubleClick={() => onPlay(index)}
    >
      {/* Index / play-on-hover / playing indicator */}
      <IndexCell>
        {isPlaying ? (
          <Play size={10} fill="currentColor" style={{ marginLeft: 'auto', display: 'inline-block' }} />
        ) : (
          index + 1
        )}
      </IndexCell>
      {/* Play button revealed on hover */}
      <HoverPlayCell style={{ left: '0.5rem' }}>
        {!isPlaying && (
          <HoverPlayButton
            onClick={(e) => {
              e.stopPropagation()
              onPlay(index)
            }}
            aria-label={`Play ${song.title}`}
          >
            <Play size={10} fill="currentColor" />
          </HoverPlayButton>
        )}
      </HoverPlayCell>

      {/* Cover art thumbnail when playing */}
      {isPlaying && song.albumPublicId && (
        <CoverThumbnail>
          <CoverArt albumPublicId={song.albumPublicId} iconSize={8} />
        </CoverThumbnail>
      )}

      {/* Title */}
      <TitleCell>{song.title}</TitleCell>

      {/* Artist */}
      <MutedCell>{song.artistName}</MutedCell>

      {/* Album */}
      <MutedCell>{song.albumName}</MutedCell>

      {/* Duration */}
      <DurationCell>
        {song.duration !== undefined ? formatDuration(song.duration) : '\u2014'}
      </DurationCell>
    </Row>
  )
}, (prev, next) => {
  // Custom equality: compare by publicId (stable UUID) + display-affecting props
  if (prev.song.publicId !== next.song.publicId) return false
  if (prev.isPlaying !== next.isPlaying) return false
  if (prev.isFocused !== next.isFocused) return false
  if (prev.index !== next.index) return false
  if (prev.height !== next.height) return false
  if (prev.translateY !== next.translateY) return false
  if (prev.onPlay !== next.onPlay) return false
  if (prev.onClick !== next.onClick) return false
  return true
})
