import styled from 'styled-components'
import { useImageBlob } from '@/shared/hooks/use-image-blob'
import { Music } from 'lucide-react'

interface CoverArtProps {
  albumPublicId?: string
  className?: string
  /** Size of the placeholder icon when no image is available */
  iconSize?: number
}

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
  background-color: var(--color-secondary);
`

const MutedIcon = styled(Music)`
  color: color-mix(in srgb, var(--color-muted-foreground) 30%, transparent);
`

export function CoverArt({ albumPublicId, className, iconSize = 48 }: CoverArtProps) {
  const coverUrl = albumPublicId ? `/api/albums/${albumPublicId}/cover` : null
  const { src } = useImageBlob(coverUrl)

  if (src) {
    return <CoverImage src={src} alt="Album cover" className={className} loading="lazy" />
  }

  return (
    <Placeholder className={className}>
      <MutedIcon size={iconSize} />
    </Placeholder>
  )
}
