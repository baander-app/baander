import styled from 'styled-components'
import { useCallback } from 'react'
import { useContextPanelStore } from '@/features/layout/stores/context-panel-store'
import { ArtistContextMenu } from './menus/ArtistContextMenu'
import { usePlayArtist } from '../hooks/use-play-artist'
import { focusVisibleRing } from '@/shared/theme'

const CardButton = styled.button`
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

const Placeholder = styled.div`
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

const Name = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--color-foreground);
`

const AlbumCount = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

interface ArtistGridItemProps {
  publicId: string
  name: string
  albumCount?: number
}

export function ArtistGridItem({ publicId, name, albumCount }: ArtistGridItemProps) {
  const setSelectedItem = useContextPanelStore((s) => s.setSelectedItem)
  const { playArtist } = usePlayArtist()

  const handleClick = useCallback(() => {
    setSelectedItem({ type: 'artist', publicId })
  }, [publicId, setSelectedItem])

  const handleDoubleClick = useCallback(() => {
    playArtist(publicId, name)
  }, [publicId, name, playArtist])

  return (
    <ArtistContextMenu artist={{ publicId, name }}>
      <CardButton
        type="button"
        onClick={handleClick}
        onDoubleClick={handleDoubleClick}
      >
      <CoverArea>
        <Placeholder>
          <PlaceholderIcon width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
            <circle cx="12" cy="7" r="4" />
          </PlaceholderIcon>
        </Placeholder>
      </CoverArea>
      <InfoArea>
        <Name>{name}</Name>
        {albumCount !== undefined && (
          <AlbumCount>
            {albumCount} {albumCount === 1 ? 'album' : 'albums'}
          </AlbumCount>
        )}
      </InfoArea>
    </CardButton>
    </ArtistContextMenu>
  )
}
