import styled from 'styled-components'
import { MediaTypeHomePage } from '@/shared/components/media-type-home-page'
import { Film } from 'lucide-react'

const DimIcon = styled.span`
  color: color-mix(in srgb, var(--color-muted-foreground) 30%, transparent);

  svg {
    color: inherit;
  }
`

export function MoviesHomePage() {
  return (
    <MediaTypeHomePage
      title="Movies"
      subtitle="Your movie collection"
      mediaType="movies"
      recentLabel="Recently Watched"
      emptyText="No movies watched yet"
      icon={
        <DimIcon>
          <Film size={48} strokeWidth={1} />
        </DimIcon>
      }
    />
  )
}
