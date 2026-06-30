import styled from 'styled-components'
import { Link } from 'react-router-dom'
import { ArtistContextMenu } from './menus/ArtistContextMenu'
import { interactiveTransition } from '@/shared/theme'

const CardLink = styled(Link)`
  display: block;
  overflow: hidden;
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  background-color: var(--color-card);
  ${interactiveTransition(['color', 'background-color'])}

  &:hover {
    background-color: var(--color-accent);
  }
`

const ImageArea = styled.div`
  aspect-ratio: 1;
  background-color: var(--color-muted);
`

const CoverImage = styled.img`
  height: 100%;
  width: 100%;
  object-fit: cover;
`

const InfoArea = styled.div`
  padding: 0.5rem;
`

const Name = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.875rem;
  font-weight: 500;
`

const AlbumCount = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

interface ArtistCardProps {
  publicId: string
  name: string
  albumCount?: number
  imageUrl?: string
}

export function ArtistCard({ publicId, name, albumCount, imageUrl }: ArtistCardProps) {
  return (
    <ArtistContextMenu artist={{ publicId, name }}>
      <CardLink to={`/artists/${publicId}`}>
      <ImageArea>
        {imageUrl && (
          <CoverImage src={imageUrl} alt={name} loading="lazy" />
        )}
      </ImageArea>
      <InfoArea>
        <Name>{name}</Name>
        {albumCount !== undefined && (
          <AlbumCount>
            {albumCount} {albumCount === 1 ? 'album' : 'albums'}
          </AlbumCount>
        )}
      </InfoArea>
    </CardLink>
    </ArtistContextMenu>
  )
}
