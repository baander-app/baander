import { Button } from '@/shared/components/ui/button'
import styled from 'styled-components'
import { useCallback } from 'react'
import { useImageBlob } from '@/shared/hooks/use-image-blob'
import { useContextPanelStore } from '@/features/layout/stores/context-panel-store'
import { AlbumContextMenu } from './menus/AlbumContextMenu'
import { usePlayAlbum } from '../hooks/use-play-album'

const CardButton = styled(Button).attrs({ variant: 'ghost' })`
  height: auto;
  padding: 0;
  border: none;
  display: block;
  &:hover, &:active { background-color: var(--color-card); transform: none; }
  position: relative;
  overflow: hidden;
  border-radius: var(--radius-lg);
  background-color: var(--color-card);
  text-align: left;

`

const CoverArea = styled.div`
  aspect-ratio: 1;
  background-color: var(--color-secondary);
`

const CoverImage = styled.img`
  height: 100%;
  width: 100%;
  object-fit: cover;
`

const CoverPlaceholder = styled.div`
  display: flex;
  height: 100%;
  width: 100%;
  align-items: center;
  justify-content: center;
`

const PlaceholderIcon = styled.svg`
  color: color-mix(in srgb, var(--color-muted-foreground) 20%, transparent);
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
  color: var(--color-foreground);
`

const ArtistName = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

interface AlbumGridItemProps {
  publicId: string
  title: string
  artistName?: string
  imageUrl?: string | null
}

export function AlbumGridItem({ publicId, title, artistName, imageUrl }: AlbumGridItemProps) {
  const { src } = useImageBlob(imageUrl)
  const setSelectedItem = useContextPanelStore((s) => s.setSelectedItem)
  const { playAlbum } = usePlayAlbum()

  const handleClick = useCallback(() => {
    setSelectedItem({ type: 'album', publicId })
  }, [publicId, setSelectedItem])

  const handleDoubleClick = useCallback(() => {
    playAlbum(publicId, title)
  }, [publicId, title, playAlbum])

  return (
    <AlbumContextMenu album={{ publicId, title, artistName }}>
      <CardButton
        type="button"
        onClick={handleClick}
        onDoubleClick={handleDoubleClick}
      >
      {/* Cover */}
      <CoverArea>
        {src ? (
          <CoverImage src={src} alt={title} loading="lazy" />
        ) : (
          <CoverPlaceholder>
            <PlaceholderIcon width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
              <circle cx="12" cy="12" r="10" />
              <circle cx="12" cy="12" r="3" />
            </PlaceholderIcon>
          </CoverPlaceholder>
        )}
      </CoverArea>

      {/* Info */}
      <InfoArea>
        <Title>{title}</Title>
        {artistName && (
          <ArtistName>{artistName}</ArtistName>
        )}
      </InfoArea>
    </CardButton>
    </AlbumContextMenu>
  )
}
