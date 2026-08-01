import styled from 'styled-components'
import { ChevronLeft, Play, Shuffle } from 'lucide-react'
import { useImageBlob } from '@/shared/hooks/use-image-blob'
import { usePlayerStore, type Track } from '@/features/player/stores/player-store'
import { Button } from '@/shared/components/ui/button'
import { AlbumContextMenu } from './menus/AlbumContextMenu'

const HeaderBar = styled.div`
  display: flex;
  height: 3rem;
  flex-shrink: 0;
  align-items: center;
  gap: 0.75rem;
  padding: 0 1rem;
`

const CoverThumb = styled.div`
  display: flex;
  height: 2rem;
  width: 2rem;
  flex-shrink: 0;
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

const Title = styled.span`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: 600;
  font-size: 0.875rem;
`

const ArtistName = styled.span`
  flex-shrink: 0;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const Year = styled.span`
  flex-shrink: 0;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const Spacer = styled.div`
  flex: 1;
`

const AccentLine = styled.div`
  height: 2px;
  width: 100%;
  flex-shrink: 0;
`

interface AlbumHeaderProps {
  title: string
  artistName?: string
  year: number | null
  coverUrl: string | null
  songs: Record<string, unknown>[]
  albumTitle: string
  albumPublicId?: string
  onBack: () => void
  onToggleMetadata: () => void
  metadataOpen: boolean
}

function buildTracks(songs: Record<string, unknown>[], albumTitle: string, albumPublicId?: string): Track[] {
  return songs.map((s) => ({
    publicId: String(s.publicId ?? ''),
    title: String(s.title ?? ''),
    artistName: s.artistName ? String(s.artistName) : undefined,
    albumName: albumTitle,
    albumPublicId,
    duration: typeof s.length === 'number' ? s.length : (typeof s.duration === 'number' ? s.duration : undefined),
  }))
}

export function AlbumHeader({
  title,
  artistName,
  year,
  coverUrl,
  songs,
  albumTitle,
  albumPublicId,
  onBack,
  onToggleMetadata,
  metadataOpen,
}: AlbumHeaderProps) {
  const { src: coverSrc } = useImageBlob(coverUrl)
  const playTrack = usePlayerStore((s) => s.playTrack)

  const handlePlayAll = () => {
    if (songs.length === 0) return
    const tracks = buildTracks(songs, albumTitle, albumPublicId)
    playTrack(tracks[0], tracks)
  }

  const handleShuffle = () => {
    if (songs.length === 0) return
    const tracks = [...buildTracks(songs, albumTitle, albumPublicId)].sort(() => Math.random() - 0.5)
    playTrack(tracks[0], tracks)
  }

  return (
    <>
      <AlbumContextMenu album={{ publicId: albumPublicId ?? '', title: albumTitle, artistName }}>
        <HeaderBar>
        <Button variant="ghost" size="icon-xs" onClick={onBack} aria-label="Back">
          <ChevronLeft size={16} />
        </Button>

        <CoverThumb>
          {coverSrc ? (
            <CoverImage src={coverSrc} alt={title} loading="lazy" />
          ) : (
            <PlaceholderIcon width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
              <circle cx="12" cy="12" r="10" />
              <circle cx="12" cy="12" r="3" />
            </PlaceholderIcon>
          )}
        </CoverThumb>

        <Title>{title}</Title>
        {artistName && (
          <ArtistName>{artistName}</ArtistName>
        )}
        {year != null && (
          <Year>{year}</Year>
        )}

        <Spacer />

        <Button variant="ghost" size="xs" onClick={onToggleMetadata} style={{ fontSize: '0.75rem', color: 'var(--color-muted-foreground)' }}>
          {metadataOpen ? 'Hide Info' : 'Info'}
        </Button>

        <Button size="xs" onClick={handlePlayAll} disabled={songs.length === 0}>
          <Play size={12} fill="currentColor" />
          Play
        </Button>

        <Button variant="outline" size="xs" onClick={handleShuffle} disabled={songs.length === 0}>
          <Shuffle size={12} />
        </Button>
        </HeaderBar>
      </AlbumContextMenu>
      <AccentLine
        style={{ backgroundColor: 'var(--accent-derived, var(--color-primary))' }}
      />
    </>
  )
}
