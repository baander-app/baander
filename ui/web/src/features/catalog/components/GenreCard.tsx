import styled from 'styled-components'
import { interactiveTransition, textRole } from '@/shared/theme'
import { ContentText } from '@/shared/typography'

const Card = styled.div`
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-lg);
  border: 1px solid var(--color-border);
  background-color: var(--color-card);
  padding: 1rem;
  ${interactiveTransition(['color', 'background-color'])}

`

const CenterContent = styled.div`
  text-align: center;
`

const Name = styled(ContentText)`
  font-weight: 500;
`

const AlbumCount = styled.p`
  ${textRole('caption')}
  color: var(--color-muted-foreground);
`

interface GenreCardProps {
  publicId: string
  name: string
  albumCount?: number
}

export function GenreCard({ name, albumCount }: GenreCardProps) {
  return (
    <Card>
      <CenterContent>
        <Name textRole="body-sm" text={name} element="p" />
        {albumCount !== undefined && (
          <AlbumCount>
            {albumCount} {albumCount === 1 ? 'album' : 'albums'}
          </AlbumCount>
        )}
      </CenterContent>
    </Card>
  )
}
