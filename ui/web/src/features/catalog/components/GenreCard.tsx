import styled from 'styled-components'
import { interactiveTransition } from '@/shared/theme'

const Card = styled.div`
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  background-color: var(--color-card);
  padding: 1rem;
  ${interactiveTransition(['color', 'background-color'])}

  &:hover {
    background-color: var(--color-accent);
  }
`

const CenterContent = styled.div`
  text-align: center;
`

const Name = styled.p`
  font-size: 0.875rem;
  font-weight: 500;
`

const AlbumCount = styled.p`
  font-size: 0.75rem;
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
        <Name>{name}</Name>
        {albumCount !== undefined && (
          <AlbumCount>
            {albumCount} {albumCount === 1 ? 'album' : 'albums'}
          </AlbumCount>
        )}
      </CenterContent>
    </Card>
  )
}
