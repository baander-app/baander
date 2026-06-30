import styled, { css } from 'styled-components'
import { useEffect, useState, useCallback } from 'react'
import { Play } from 'lucide-react'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { useContextPanelStore } from '@/features/layout/stores/context-panel-store'
import { AlbumContextMenu } from './menus/AlbumContextMenu'
import { usePlayAlbum } from '../hooks/use-play-album'
import { createLogger } from '@/shared/lib/logger'
import { focusVisibleRing, interactiveTransition } from '@/shared/theme'

const logger = createLogger('AlbumGridItem')

const CardButton = styled.button`
  position: relative;
  overflow: hidden;
  border-radius: var(--radius-lg);
  background-color: var(--color-card);
  text-align: left;
  transition: all 150ms;
  ${focusVisibleRing}

  &:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
    background-color: color-mix(in srgb, var(--color-accent) 50%, transparent);
  }
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

const HoverOverlay = styled.div`
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: transparent;
  opacity: 0;
  transition: all 150ms;

  ${CardButton}:hover & {
    background-color: rgba(0, 0, 0, 0.3);
    opacity: 1;
  }
`

const PlayButton = styled.div`
  display: flex;
  height: 2.5rem;
  width: 2.5rem;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  background-color: var(--color-primary);
  color: var(--color-primary-foreground);
  box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
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
  const [src, setSrc] = useState<string | null>(null)
  const setSelectedItem = useContextPanelStore((s) => s.setSelectedItem)
  const { playAlbum } = usePlayAlbum()

  useEffect(() => {
    if (!imageUrl) {
      setSrc(null)
      return
    }
    let revoked = false
    AXIOS_INSTANCE.get(imageUrl, { responseType: 'blob' })
      .then((res) => { if (!revoked) setSrc(URL.createObjectURL(res.data)) })
      .catch((err) => { if (!revoked) { logger.warn('Image load failed:', err); setSrc(null) } })
    return () => { revoked = true }
  }, [imageUrl])

  useEffect(() => {
    return () => { if (src) URL.revokeObjectURL(src) }
  }, [src])

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
        {/* Hover play button */}
        <HoverOverlay>
          <PlayButton>
            <Play size={18} fill="currentColor" />
          </PlayButton>
        </HoverOverlay>
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
