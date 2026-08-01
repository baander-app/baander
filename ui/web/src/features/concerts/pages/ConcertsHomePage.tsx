import styled from 'styled-components'
import { MediaTypeHomePage } from '@/shared/components/media-type-home-page'
import { Music } from 'lucide-react'

const DimIcon = styled.span`
  color: color-mix(in srgb, var(--color-muted-foreground) 30%, transparent);

  svg {
    color: inherit;
  }
`

export function ConcertsHomePage() {
  return (
    <MediaTypeHomePage
      title="Concerts"
      subtitle="Your concert recordings"
      mediaType="concerts"
      recentLabel="Recently Watched"
      emptyText="No concerts watched yet"
      icon={
        <DimIcon>
          <Music size={48} strokeWidth={1} />
        </DimIcon>
      }
    />
  )
}
