import styled from 'styled-components'
import type { Track } from '@/features/player/stores/player-store'
import { TrackCredits } from '@/features/player/components/TrackCredits'
import { CoverArt } from '@/shared/components/cover-art'
import { textRole } from '@/shared/theme'
import { ContentText } from '@/shared/typography'

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

const TrackTitle = styled(ContentText)`
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: 500;
  color: var(--color-foreground);
`

const TrackSubtitle = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  ${textRole('caption')}
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
        <TrackTitle textRole="body-sm" text={track.title} language={track.albumLanguage} />
        <TrackSubtitle>
          <TrackCredits track={track} textRole="caption" />
        </TrackSubtitle>
      </TrackText>
    </TrackInfoRow>
  )
}
