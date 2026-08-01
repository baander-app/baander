import styled from 'styled-components'
import { MediaTypeHomePage } from '@/shared/components/media-type-home-page'
import { BookOpen } from 'lucide-react'

const DimIcon = styled.span`
  color: color-mix(in srgb, var(--color-muted-foreground) 30%, transparent);

  svg {
    color: inherit;
  }
`

export function EbooksHomePage() {
  return (
    <MediaTypeHomePage
      title="Ebooks"
      subtitle="Your book collection"
      mediaType="ebooks"
      recentLabel="Recently Read"
      emptyText="No books read yet"
      icon={
        <DimIcon>
          <BookOpen size={48} strokeWidth={1} />
        </DimIcon>
      }
    />
  )
}
