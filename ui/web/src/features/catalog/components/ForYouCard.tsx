import styled from 'styled-components'
import { usePlayerStore } from '@/features/player/stores/player-store'
import type { ForYouSong } from '../hooks/use-for-you-view-model'

// ── Shared card styles (mirror HomePage cover-card primitives) ──

const CoverCard = styled.button`
  width: 9rem;
  flex-shrink: 0;
  overflow: hidden;
  border-radius: var(--radius-lg);
  background-color: var(--color-card);
  text-align: left;
  transition: all 150ms ease-out;
  border: none;
  cursor: pointer;
  padding: 0;

  &:hover {
    transform: translateY(-0.125rem);
    box-shadow: var(--shadow-lg);
  }
`

const CoverImageArea = styled.div`
  aspect-ratio: 1;
  background-color: var(--color-secondary);

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
`

const PlaceholderIcon = styled.div`
  display: flex;
  width: 100%;
  height: 100%;
  align-items: center;
  justify-content: center;
  color: color-mix(in srgb, var(--color-muted-foreground) 20%, transparent);
`

const CardInfo = styled.div`
  padding: 0.375rem;
`

const CardTitle = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.75rem;
  font-weight: 500;
  color: var(--color-foreground);
`

const CardSubtitle = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 11px;
  color: var(--color-muted-foreground);
`

interface ForYouCardProps {
  song: ForYouSong
}

export function ForYouCard({ song }: ForYouCardProps) {
  const playTrack = usePlayerStore((s) => s.playTrack)

  return (
    <CoverCard
      type="button"
      onClick={() => {
        playTrack({
          publicId: song.publicId,
          title: song.title,
          duration: song.duration ?? undefined,
        })
      }}
    >
      <CoverImageArea>
        <PlaceholderIcon>
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
            <circle cx="12" cy="12" r="10" />
            <circle cx="12" cy="12" r="3" />
          </svg>
        </PlaceholderIcon>
      </CoverImageArea>
      <CardInfo>
        <CardTitle>{song.title}</CardTitle>
        <CardSubtitle>{song.explanation}</CardSubtitle>
      </CardInfo>
    </CoverCard>
  )
}
