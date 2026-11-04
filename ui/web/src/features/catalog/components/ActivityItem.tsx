import styled, { css } from 'styled-components'
import { formatRelativeTime } from '@/shared/utils/format-relative-time'
import { textRole } from '@/shared/theme'
import { ContentText } from '@/shared/typography'
import { useSelectionStore } from '../stores/selection-store'
import { usePlayerStore, type Track } from '@/features/player/stores/player-store'
import { SongContextMenu } from './menus/SongContextMenu'
import type { ActivityEntry } from '../types/activity'

const ItemContainer = styled.div<{ $isSelected: boolean; $isPlaying: boolean }>`
  display: flex;
  cursor: default;
  align-items: center;
  gap: 0.75rem;
  border-radius: var(--radius-sm);
  padding: 0.375rem 0.5rem;

  ${({ $isSelected, $isPlaying }) =>
    $isSelected
      ? css`
        border-left: 2px solid var(--color-primary);
        background-color: color-mix(in srgb, var(--color-primary) 5%, transparent);
      `
      : $isPlaying
        ? css`
          background-color: color-mix(in srgb, var(--color-primary) 10%, transparent);
          color: var(--color-accent-text);
        `
        : css`
          &:hover {
            background-color: color-mix(in srgb, var(--color-highlight) 40%, transparent);
          }
        `}
`

const CoverThumb = styled.div`
  height: 2rem;
  width: 2rem;
  flex-shrink: 0;
  border-radius: var(--radius-sm);
  background-color: color-mix(in srgb, var(--color-muted) 50%, transparent);
`

const SongInfo = styled.div`
  min-width: 0;
  flex: 1;
`

const TitleLine = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  ${textRole('body-sm')}
  font-weight: 500;
`

const SongTitle = styled(ContentText)`
  font-weight: 500;
`

const MetaRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
`

const MetaText = styled(ContentText)`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--color-muted-foreground);
`

const Separator = styled.span`
  color: var(--color-muted-foreground);
`

const Timestamp = styled.span`
  flex-shrink: 0;
  ${textRole('caption')}
  color: var(--color-muted-foreground);
`

interface ActivityItemProps {
  entry: ActivityEntry
}

export function ActivityItem({ entry }: ActivityItemProps) {
  const selectedId = useSelectionStore((s) => s.selectedId)
  const select = useSelectionStore((s) => s.select)
  const playTrack = usePlayerStore((s) => s.playTrack)
  const currentTrack = usePlayerStore((s) => s.currentTrack)

  const isSelected = selectedId === entry.publicId
  const isPlaying = currentTrack?.publicId === entry.songId

  const title = entry.songTitle ?? entry.songId ?? 'Unknown'
  const timestamp = entry.lastPlayedAt ?? entry.createdAt
  const relativeTime = formatRelativeTime(timestamp)

  const handleClick = () => {
    select(entry.publicId, 'song')
  }

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && entry.songId) {
      e.preventDefault()
      const track: Track = {
        publicId: entry.songId,
        available: entry.songAvailable === true,
        title: entry.songTitle ?? '',
        artistName: entry.artistName ?? undefined,
        albumName: entry.albumName ?? undefined,
        albumPublicId: entry.albumId ?? undefined,
      }
      playTrack(track)
    }
  }

  return (
    <SongContextMenu
      song={{
        publicId: entry.songId ?? entry.publicId,
        available: entry.songAvailable === true,
        title,
        artistName: entry.artistName ?? undefined,
        albumName: entry.albumName ?? undefined,
        albumId: entry.albumId ?? undefined,
        artistId: entry.artistId ?? undefined,
      }}
    >
      <ItemContainer
        $isSelected={isSelected}
        $isPlaying={isPlaying}
        onClick={handleClick}
        onKeyDown={handleKeyDown}
        tabIndex={0}
        role="listitem"
      >
        {/* Cover art thumbnail */}
        <CoverThumb />

        {/* Song info */}
        <SongInfo>
          <TitleLine>
            <SongTitle textRole="body-sm" text={title} />
            {entry.songAvailable === false && <span> · Unavailable</span>}
          </TitleLine>
          <MetaRow>
            {entry.artistName && (
              <MetaText textRole="body-sm" text={entry.artistName} />
            )}
            {entry.albumName && (
              <>
                {entry.artistName && <Separator>·</Separator>}
                <MetaText textRole="body-sm" text={entry.albumName} />
              </>
            )}
          </MetaRow>
        </SongInfo>

        {/* Timestamp */}
        <Timestamp>{relativeTime}</Timestamp>
      </ItemContainer>
    </SongContextMenu>
  )
}
