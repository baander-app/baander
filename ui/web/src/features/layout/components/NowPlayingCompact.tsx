import styled from 'styled-components'
import { Play, Pause, SkipBack, SkipForward } from 'lucide-react'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { ProgressBar } from '@/features/player/components/ProgressBar'
import { Button } from '@/shared/components/ui/button'
import { TrackCredits } from '@/features/player/components/TrackCredits'
import { CoverArt } from '@/shared/components/cover-art'
import { textRole } from '@/shared/theme'
import { ContentText } from '@/shared/typography'

const EmptyState = styled.div`
  display: flex;
  flex: 1;
  align-items: center;
  justify-content: center;
  padding: 1rem;
`

const EmptyText = styled.p`
  ${textRole('body')}
  color: var(--color-muted-foreground);
`

const CompactContainer = styled.div`
  display: flex;
  flex: 1;
  flex-direction: column;
  justify-content: flex-end;
  padding: 0.75rem;
`

const ControlsRow = styled.div`
  margin-top: 0.5rem;
  display: flex;
  align-items: center;
  gap: 0.5rem;
`

const CoverThumbnail = styled.div`
  width: 2.5rem;
  height: 2.5rem;
  flex-shrink: 0;
  overflow: hidden;
  border-radius: var(--radius-md);
`

const ExpandButton = styled.button`
  min-width: 0;
  flex: 1;
  text-align: left;
  background: none;
  border: none;
  padding: 0;
  cursor: pointer;
  color: inherit;
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

const TransportControls = styled.div`
  display: flex;
  align-items: center;
  gap: 0.125rem;
`

export function NowPlayingCompact({ onExpand }: { onExpand: () => void }) {
  const currentTrack = usePlayerStore((s) => s.currentTrack)
  const isPlaying = usePlayerStore((s) => s.isPlaying)
  const setIsPlaying = usePlayerStore((s) => s.setIsPlaying)
  const playNext = usePlayerStore((s) => s.playNext)
  const playPrevious = usePlayerStore((s) => s.playPrevious)

  if (!currentTrack) {
    return (
      <EmptyState>
        <EmptyText>Nothing playing</EmptyText>
      </EmptyState>
    )
  }

  return (
    <CompactContainer>
      <ProgressBar />

      <ControlsRow>
        {/* Cover art */}
        <CoverThumbnail>
          <CoverArt albumPublicId={currentTrack.albumPublicId} iconSize={14} />
        </CoverThumbnail>

        {/* Track info */}
        <ExpandButton
          onClick={onExpand}
          aria-label="Expand now playing"
        >
          <TrackTitle textRole="body-sm" text={currentTrack.title} language={currentTrack.albumLanguage} />
          <TrackSubtitle>
            <TrackCredits track={currentTrack} textRole="caption" />
          </TrackSubtitle>
        </ExpandButton>

        {/* Transport controls */}
        <TransportControls>
          <Button variant="ghost" size="icon-xs" onClick={playPrevious} aria-label="Previous">
            <SkipBack size={14} />
          </Button>
          <Button variant="ghost" size="icon-sm" onClick={() => setIsPlaying(!isPlaying)} aria-label={isPlaying ? 'Pause' : 'Play'}>
            {isPlaying ? <Pause size={16} fill="currentColor" /> : <Play size={16} fill="currentColor" />}
          </Button>
          <Button variant="ghost" size="icon-xs" onClick={playNext} aria-label="Next">
            <SkipForward size={14} />
          </Button>
        </TransportControls>
      </ControlsRow>
    </CompactContainer>
  )
}
