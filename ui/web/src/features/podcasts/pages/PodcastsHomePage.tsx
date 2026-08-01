import styled from 'styled-components'
import { MediaTypeHomePage } from '@/shared/components/media-type-home-page'
import { Podcast } from 'lucide-react'

const DimIcon = styled.span`
  color: color-mix(in srgb, var(--color-muted-foreground) 30%, transparent);

  svg {
    color: inherit;
  }
`

export function PodcastsHomePage() {
  return (
    <MediaTypeHomePage
      title="Podcasts"
      subtitle="Your podcast subscriptions"
      mediaType="podcasts"
      recentLabel="Recently Played"
      emptyText="No podcasts played yet"
      icon={
        <DimIcon>
          <Podcast size={48} strokeWidth={1} />
        </DimIcon>
      }
    />
  )
}
