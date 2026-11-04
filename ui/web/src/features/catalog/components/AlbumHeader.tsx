import styled from 'styled-components'
import { ChevronLeft, Play, Shuffle } from 'lucide-react'
import { useImageBlob } from '@/shared/hooks/use-image-blob'
import { usePlayerStore, type Track } from '@/features/player/stores/player-store'
import { Button } from '@/shared/components/ui/button'
import { AlbumContextMenu } from './menus/AlbumContextMenu'
import { textRole } from '@/shared/theme'
import { ContentText } from '@/shared/typography'

const HeaderBar = styled.div`
  display: flex;
  min-height: 3rem;
  flex-wrap: wrap;
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

const Title = styled(ContentText)`
  min-width: 0;
  flex: 1 1 8rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: 600;
`

const ArtistName = styled(ContentText)`
  min-width: 0;
  max-width: 12rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--color-muted-foreground);
`

const Year = styled.span`
  flex-shrink: 0;
  ${textRole('caption')}
  font-variant-numeric: tabular-nums;
  color: var(--color-muted-foreground);
`

const InfoButton = styled(Button)`
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
  /** The album's stored release language, which its title and songs take. */
  albumLanguage?: string | null
  albumPublicId?: string
  onBack: () => void
  onToggleMetadata: () => void
  metadataOpen: boolean
}

function buildTracks(
  songs: Record<string, unknown>[],
  albumTitle: string,
  albumLanguage: string | null | undefined,
  albumPublicId?: string,
): Track[] {
  return songs.filter((s) => s.available === true).map((s) => ({
    publicId: String(s.publicId ?? ''),
    available: s.available === true,
    title: String(s.title ?? ''),
    artistName: s.artistName ? String(s.artistName) : undefined,
    albumName: albumTitle,
    albumLanguage: albumLanguage ?? undefined,
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
  albumLanguage,
  albumPublicId,
  onBack,
  onToggleMetadata,
  metadataOpen,
}: AlbumHeaderProps) {
  const { src: coverSrc } = useImageBlob(coverUrl)
  const playTrack = usePlayerStore((s) => s.playTrack)

  const handlePlayAll = () => {
    if (songs.length === 0) return
    const tracks = buildTracks(songs, albumTitle, albumLanguage, albumPublicId)
    if (tracks.length > 0) playTrack(tracks[0], tracks)
  }

  const handleShuffle = () => {
    if (songs.length === 0) return
    const tracks = [...buildTracks(songs, albumTitle, albumLanguage, albumPublicId)].sort(() => Math.random() - 0.5)
    if (tracks.length > 0) playTrack(tracks[0], tracks)
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

        <Title textRole="body-sm" text={title} language={albumLanguage} />
        {artistName && (
          <ArtistName textRole="body-sm" text={artistName} />
        )}
        {year != null && (
          <Year>{year}</Year>
        )}

        <Spacer />

        <InfoButton variant="ghost" size="xs" onClick={onToggleMetadata}>
          {metadataOpen ? 'Hide Info' : 'Info'}
        </InfoButton>

        <Button size="xs" onClick={handlePlayAll} disabled={!songs.some((song) => song.available === true)}>
          <Play size={12} fill="currentColor" />
          Play
        </Button>

        <Button variant="outline" size="xs" onClick={handleShuffle} disabled={!songs.some((song) => song.available === true)}>
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
