import styled from 'styled-components'
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { AlbumContextMenu } from './menus/AlbumContextMenu'
import { createLogger } from '@/shared/lib/logger'
import { interactiveTransition } from '@/shared/theme'

const logger = createLogger('AlbumCard')

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

const Title = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.875rem;
  font-weight: 500;
`

const ArtistName = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

interface AlbumCardProps {
  publicId: string
  title: string
  artistName?: string
  imageUrl?: string | null
}

export function AlbumCard({ publicId, title, artistName, imageUrl }: AlbumCardProps) {
  const [src, setSrc] = useState<string | null>(null)

  useEffect(() => {
    if (!imageUrl) {
      setSrc(null)
      return
    }

    let revoked = false

    AXIOS_INSTANCE.get(imageUrl, { responseType: 'blob' })
      .then((res) => {
        if (!revoked) {
          const url = URL.createObjectURL(res.data)
          setSrc(url)
        }
      })
      .catch((err) => {
        if (!revoked) { logger.warn('Image load failed:', err); setSrc(null) }
      })

    return () => {
      revoked = true
    }
  }, [imageUrl])

  // Revoke blob URL when component unmounts or src changes
  useEffect(() => {
    return () => {
      if (src) URL.revokeObjectURL(src)
    }
  }, [src])

  return (
    <AlbumContextMenu album={{ publicId, title, artistName }}>
      <CardLink to={`/albums/${publicId}`}>
        <ImageArea>
          {src && (
            <CoverImage src={src} alt={title} loading="lazy" />
          )}
        </ImageArea>
        <InfoArea>
          <Title>{title}</Title>
          {artistName && (
            <ArtistName>{artistName}</ArtistName>
          )}
        </InfoArea>
      </CardLink>
    </AlbumContextMenu>
  )
}
