import styled from 'styled-components'
import { Link } from 'react-router-dom'
import { useImageBlob } from '@/shared/hooks/use-image-blob'
import { AlbumContextMenu } from './menus/AlbumContextMenu'
import { interactiveTransition } from '@/shared/theme'
import { ContentText } from '@/shared/typography'

const CardLink = styled(Link)`
  display: block;
  overflow: hidden;
  border-radius: var(--radius-lg);
  border: 1px solid var(--color-border);
  background-color: var(--color-card);
  ${interactiveTransition(['color', 'background-color'])}

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

const Title = styled(ContentText)`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: 500;
`

const ArtistName = styled(ContentText)`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--color-muted-foreground);
`

interface AlbumCardProps {
  publicId: string
  title: string
  artistName?: string
  imageUrl?: string | null
}

export function AlbumCard({ publicId, title, artistName, imageUrl }: AlbumCardProps) {
  const { src } = useImageBlob(imageUrl)

  return (
    <AlbumContextMenu album={{ publicId, title, artistName }}>
      <CardLink to={`/albums/${publicId}`}>
        <ImageArea>
          {src && (
            <CoverImage src={src} alt={title} loading="lazy" />
          )}
        </ImageArea>
        <InfoArea>
          <Title textRole="body-sm" text={title} forwardedAs="p" />
          {artistName && (
            <ArtistName textRole="caption" text={artistName} forwardedAs="p" />
          )}
        </InfoArea>
      </CardLink>
    </AlbumContextMenu>
  )
}
