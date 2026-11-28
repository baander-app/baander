import type { ComponentProps } from 'react'
import { Link } from 'react-router-dom'
import { Check, Play } from 'lucide-react'
import styled from 'styled-components'
import type { TvEpisodeResource } from '@/shared/api-client/gen/endpoints'
import { ProgressBar } from '@/shared/components/progress-bar'
import { Button } from '@/shared/components/ui/button'
import { ContentText } from '@/shared/typography'
import { focusVisibleRing, textRole } from '@/shared/theme'
import { VideoActionMenu } from '@/features/catalog/components/VideoActionMenu'
import { VideoArtwork } from '@/features/catalog/components/VideoArtwork'

const RowMenu = styled(VideoActionMenu)`
  min-height: 3rem;
  padding-block: var(--space-xs);
  overflow-wrap: anywhere;
`

const Still = styled(VideoArtwork)`
  display: none;
  width: 6rem;
  flex: 0 0 auto;

  @media (min-width: 768px) {
    display: grid;
  }
`

const Detail = styled.div`
  display: grid;
  gap: var(--space-xs);
  flex: 1;
  min-width: 0;
`

const TitleLink = styled(Link)`
  width: fit-content;
  color: var(--color-foreground);
  ${focusVisibleRing}
`

const Count = styled.span`
  ${textRole('mono-sm')}
  color: var(--color-muted-foreground);
`

const Watched = styled.span`
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  color: var(--color-muted-foreground);
  ${textRole('caption')}
`

interface EpisodeRowProps {
  episode: TvEpisodeResource
  watched: boolean
  actions: ComponentProps<typeof VideoActionMenu>['actions']
  onPlay: () => void
}

export function EpisodeRow({ episode, watched, actions, onPlay }: EpisodeRowProps) {
  const number = episode.episodeNumber ?? episode.absoluteNumber
  const title = episode.name || (number === null ? episode.airDate ?? 'Episode' : `Episode ${number}`)
  const version = episode.videos.find(video => video.publicId === episode.defaultVideoPublicId)
  const resume = (version?.positionSeconds ?? 0) > 0
  const playLabel = resume ? 'Resume' : 'Play'

  return (
    <li>
      <RowMenu label={`Options for ${title}`} actions={actions}>
        <Still source={episode.stillUrl} wide />
        <Detail>
          <TitleLink to={`/tv/episodes/${episode.publicId}`}>
            <ContentText text={title} textRole="body" />
          </TitleLink>
          {number !== null || episode.airDate ? (
            <Count>{number !== null ? `Episode ${number}` : episode.airDate}</Count>
          ) : null}
          {watched ? (
            <Watched><Check size={16} aria-hidden="true" />Watched</Watched>
          ) : episode.progress !== null && episode.progress > 0 ? (
            <ProgressBar value={episode.progress * 100} label="Progress" />
          ) : null}
        </Detail>
        {version ? (
          <Button variant="ghost" size="icon" aria-label={`${playLabel} ${title}`} onClick={onPlay}>
            <Play size={18} aria-hidden="true" />
          </Button>
        ) : null}
      </RowMenu>
    </li>
  )
}
