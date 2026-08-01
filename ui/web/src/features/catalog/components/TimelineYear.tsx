import styled, { css } from 'styled-components'
import { useCallback, useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import { useImageBlob } from '@/shared/hooks/use-image-blob'
import { extractDominantColor } from '@/shared/utils/blurhash'
import { useSelectionStore } from '../stores/selection-store'
import { AlbumContextMenu } from './menus/AlbumContextMenu'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/shared/components/ui/tooltip'
import type { AlbumSummary } from '../types'
import { focusVisibleRing } from '@/shared/theme'

const ThumbnailButton = styled.div<{ $isSelected: boolean }>`
  height: 4rem;
  width: 4rem;
  flex-shrink: 0;
  overflow: hidden;
  border-radius: var(--radius-sm);
  ${focusVisibleRing}

  ${({ $isSelected }) =>
    $isSelected &&
    css`
      outline: 2px solid var(--color-primary);
      outline-offset: -2px;
    `}
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

const YearRow = styled.div`
  display: flex;
  align-items: flex-start;
  gap: 1rem;
`

const YearLabel = styled.span`
  width: 3rem;
  flex-shrink: 0;
  padding-top: 1rem;
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--color-foreground);
`

const AlbumStrip = styled.div`
  display: flex;
  gap: 0.5rem;
  overflow-x: auto;
  padding-bottom: 0.5rem;
`

interface TimelineYearProps {
  label: string
  albums: AlbumSummary[]
}

function AlbumThumbnail({ album }: { album: AlbumSummary }) {
  const navigate = useNavigate()
  const select = useSelectionStore((s) => s.select)
  const selectedId = useSelectionStore((s) => s.selectedId)
  const isSelected = selectedId === album.publicId

  const imageUrl = album.coverImage?.url ?? null
  const { src } = useImageBlob(imageUrl)
  const dominantColor = useMemo(() => extractDominantColor(album.coverImage?.blurhash ?? null), [album.coverImage?.blurhash])

  const handleClick = useCallback(() => {
    select(album.publicId, 'album')
  }, [album.publicId, select])

  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault()
        navigate(`/albums/${album.publicId}`)
      }
    },
    [navigate, album.publicId],
  )

  const artistName = album.artists.length > 0 ? album.artists[0].name : undefined

  const contextMenuData = useMemo(
    () => ({
      publicId: album.publicId,
      title: album.title,
      artistName,
    }),
    [album.publicId, album.title, artistName],
  )

  const thumbnail = (
    <AlbumContextMenu album={contextMenuData}>
      <ThumbnailButton
        role="button"
        tabIndex={0}
        onClick={handleClick}
        onKeyDown={handleKeyDown}
        $isSelected={isSelected}
        style={{
          backgroundColor: dominantColor ?? 'var(--color-secondary)',
        }}
      >
        {src ? (
          <CoverImage src={src} alt={album.title} loading="lazy" />
        ) : (
          <Placeholder
            style={{ backgroundColor: dominantColor ?? 'var(--color-secondary)' }}
          >
            {!dominantColor && (
              <PlaceholderIcon
                width="20"
                height="20"
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
      </ThumbnailButton>
    </AlbumContextMenu>
  )

  return (
    <TooltipProvider delayDuration={300}>
      <Tooltip>
        <TooltipTrigger asChild>{thumbnail}</TooltipTrigger>
        <TooltipContent side="top">
          {album.title}
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}

export function TimelineYear({ label, albums }: TimelineYearProps) {
  return (
    <YearRow>
      <YearLabel>{label}</YearLabel>
      <AlbumStrip role="list" aria-label={`Albums from ${label}`}>
        {albums.map((album) => (
          <div key={album.publicId} role="listitem">
            <AlbumThumbnail album={album} />
          </div>
        ))}
      </AlbumStrip>
    </YearRow>
  )
}
