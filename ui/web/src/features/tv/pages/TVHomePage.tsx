import styled from 'styled-components'
import { MediaTypeHomePage } from '@/shared/components/media-type-home-page'
import { Tv } from 'lucide-react'

const DimIcon = styled.span`
  color: color-mix(in srgb, var(--color-muted-foreground) 30%, transparent);

  svg {
    color: inherit;
  }
`

export function TVHomePage() {
  return (
    <MediaTypeHomePage
      title="TV Shows"
      subtitle="Your TV show collection"
      mediaType="tv"
      recentLabel="Continue Watching"
      emptyText="No shows watched yet"
      icon={
        <DimIcon>
          <Tv size={48} strokeWidth={1} />
        </DimIcon>
      }
    />
  )
}
