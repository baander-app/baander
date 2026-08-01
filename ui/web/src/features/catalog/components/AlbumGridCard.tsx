import styled, { css } from 'styled-components'
import { useCallback, useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import { useImageBlob } from '@/shared/hooks/use-image-blob'
import { extractDominantColor } from '@/shared/utils/blurhash'
import { useSelectionStore } from '../stores/selection-store'
import { AlbumContextMenu } from './menus/AlbumContextMenu'
import { focusVisibleRing } from '@/shared/theme'

const GridCard = styled.div<{ $isSelected: boolean }>`
  position: relative;
  overflow: hidden;
  border-radius: var(--radius-md);
  text-align: left;
  ${focusVisibleRing}

  ${({ $isSelected }) =>
    $isSelected &&
    css`
      outline: 2px solid var(--color-primary);
      outline-offset: -2px;
    `}
`

const ImageWrapper = styled.div`
  aspect-ratio: 1;
  overflow: hidden;
`

const CoverImage = styled.img`
  height: 100%;
  width: 100%;
  object-fit: cover;
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

interface AlbumGridCardProps {
  publicId: string
  title: string
  artistName?: string
  imageUrl?: string | null
  blurhash?: string | null
}

export function AlbumGridCard({ publicId, title, artistName, imageUrl, blurhash }: AlbumGridCardProps) {
  const navigate = useNavigate()
  const select = useSelectionStore((s) => s.select)
  const selectedId = useSelectionStore((s) => s.selectedId)
  const isSelected = selectedId === publicId

  const { src, isLoading: imageLoading } = useImageBlob(imageUrl)
  const dominantColor = useMemo(() => extractDominantColor(blurhash ?? null), [blurhash])

  const handleClick = useCallback(() => {
    select(publicId, 'album')
  }, [publicId, select])

  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault()
        navigate(`/albums/${publicId}`)
      }
    },
    [navigate, publicId],
  )

  const contextMenuData = useMemo(
    () => ({
      publicId,
      title,
      artistName,
    }),
    [publicId, title, artistName],
  )

  return (
    <AlbumContextMenu album={contextMenuData}>
      <GridCard
        role="gridcell"
        tabIndex={0}
        onClick={handleClick}
        onKeyDown={handleKeyDown}
        $isSelected={isSelected}
        style={{
          backgroundColor: imageLoading && dominantColor ? dominantColor : undefined,
        }}
      >
        {/* Cover image area */}
        <ImageWrapper>
          {src ? (
            <CoverImage src={src} alt={title} loading="lazy" />
          ) : (
            <Placeholder
              style={{ backgroundColor: dominantColor ?? 'var(--color-secondary)' }}
            >
              {!dominantColor && !imageLoading && (
                <PlaceholderIcon
                  width="32"
                  height="32"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="1.5"
                >
                  <circle cx="12" cy="12" r="10" />
                  <circle cx="12" cy="12" r="3" />
                </PlaceholderIcon>
              )}
            </Placeholder>
          )}
        </ImageWrapper>

        {/* Title & artist */}
        <InfoArea>
          <Title>{title}</Title>
          {artistName && (
            <ArtistName>{artistName}</ArtistName>
          )}
        </InfoArea>
      </GridCard>
    </AlbumContextMenu>
  )
}
