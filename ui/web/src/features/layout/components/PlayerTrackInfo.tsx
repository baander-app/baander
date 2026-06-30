import styled from 'styled-components'
import type { Track } from '@/features/player/stores/player-store'
import { CoverArt } from '@/shared/components/cover-art'

const TrackInfoRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.625rem;
`

const CoverArtWrapper = styled.div`
  width: 2.5rem;
  height: 2.5rem;
  flex-shrink: 0;
  overflow: hidden;
  border-radius: var(--radius-md);
  corner-shape: squircle;
`

const TrackText = styled.div`
  min-width: 0;
  flex: 1;
`

const TrackTitle = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.875rem;
  font-weight: 500;
  line-height: 1.25;
  color: var(--color-foreground);
`

const TrackSubtitle = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.75rem;
  line-height: 1.25;
  color: var(--color-muted-foreground);
`

interface PlayerTrackInfoProps {
  track: Track
}

export function PlayerTrackInfo({ track }: PlayerTrackInfoProps) {
  return (
    <TrackInfoRow>
      <CoverArtWrapper>
        <CoverArt albumPublicId={track.albumPublicId} iconSize={14} />
      </CoverArtWrapper>
      <TrackText>
        <TrackTitle>{track.title}</TrackTitle>
        <TrackSubtitle>
          {track.artistName}
          {track.artistName && track.albumName ? ' · ' : ''}
          {track.albumName}
        </TrackSubtitle>
      </TrackText>
    </TrackInfoRow>
  )
}
