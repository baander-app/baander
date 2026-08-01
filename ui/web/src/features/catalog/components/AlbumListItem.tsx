import styled from 'styled-components'
import { useEffect, useState, useCallback } from 'react'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { useContextPanelStore } from '@/features/layout/stores/context-panel-store'
import { AlbumContextMenu } from './menus/AlbumContextMenu'
import { usePlayAlbum } from '../hooks/use-play-album'
import { createLogger } from '@/shared/lib/logger'
import { interactiveTransition } from '@/shared/theme'

const logger = createLogger('AlbumListItem')

const Row = styled.tr`
  cursor: pointer;
  ${interactiveTransition(['color', 'background-color'])}

  &:hover {
    background-color: color-mix(in srgb, var(--color-accent) 50%, transparent);
  }
`

const ThumbCell = styled.td`
  width: 2.5rem;
  padding: 0.375rem 0.5rem;
`

const Thumbnail = styled.div`
  display: flex;
  height: 2.5rem;
  width: 2.5rem;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  border-radius: var(--radius-sm);
  background-color: var(--color-secondary);
`

const CoverImage = styled.img`
  height: 100%;
  width: 100%;
  object-fit: cover;
`

const PlaceholderIcon = styled.svg`
  color: color-mix(in srgb, var(--color-muted-foreground) 20%, transparent);
`

const Cell = styled.td`
  padding: 0.375rem 0.5rem;
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
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

interface AlbumListItemProps {
  publicId: string
  title: string
  artistName?: string
  imageUrl?: string | null
}

export function AlbumListItem({ publicId, title, artistName, imageUrl }: AlbumListItemProps) {
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
      <Row
        onClick={handleClick}
        onDoubleClick={handleDoubleClick}
      >
      <ThumbCell>
        <Thumbnail>
          {src ? (
            <CoverImage src={src} alt="" loading="lazy" />
          ) : (
            <PlaceholderIcon width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
              <circle cx="12" cy="12" r="10" />
            </PlaceholderIcon>
          )}
        </Thumbnail>
      </ThumbCell>
      <Cell>
        <Title>{title}</Title>
      </Cell>
      <Cell>
        <ArtistName>{artistName}</ArtistName>
      </Cell>
    </Row>
    </AlbumContextMenu>
  )
}
