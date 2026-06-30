import styled from 'styled-components'
import { useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { Play, ExternalLink, Music } from 'lucide-react'
import { useGetSongShow, useGetAlbumShow } from '@/shared/api-client/gen/endpoints'
import { asSongFromData, asAlbumFromData } from '../../utils/api-adapters'
import { useImageBlob } from '@/shared/hooks/use-image-blob'
import { formatDuration } from '@/shared/utils/format-duration'
import { usePlayerStore, type Track } from '@/features/player/stores/player-store'
import { Button } from '@/shared/components/ui/button'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { SongContextMenu } from '../menus/SongContextMenu'

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1rem;
`

const HeaderSection = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.75rem;
  text-align: center;
`

const CoverArea = styled.div`
  display: flex;
  height: 10rem;
  width: 10rem;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  border-radius: var(--radius-lg);
  background-color: var(--color-secondary);
`

const CoverImage = styled.img`
  height: 100%;
  width: 100%;
  object-fit: cover;
`

const SongTitle = styled.h3`
  font-size: 1rem;
  font-weight: 500;
  color: var(--color-foreground);
`

const ArtistText = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const AlbumText = styled.p`
  font-size: 0.75rem;
  color: color-mix(in srgb, var(--color-muted-foreground) 70%, transparent);
`

const MetadataGrid = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
  font-size: 0.875rem;
`

const MetadataRow = styled.div`
  display: flex;
  justify-content: space-between;
`

const MetadataLabel = styled.span`
  color: var(--color-muted-foreground);
`

const MetadataValue = styled.span`
  font-size: 0.75rem;
  color: var(--color-foreground);
`

const MonoValue = styled.span`
  font-family: var(--font-mono);
  font-size: 0.75rem;
  font-variant-numeric: tabular-nums;
  color: var(--color-foreground);
`

const ErrorContainer = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  padding: 2rem 0;
`

const ErrorText = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const LoadingContainer = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1rem;
`

const LoadingHeader = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.25rem;
`

interface SongPreviewPanelProps {
  publicId: string
}

export function SongPreviewPanel({ publicId }: SongPreviewPanelProps) {
  const navigate = useNavigate()
  const playTrack = usePlayerStore((s) => s.playTrack)

  const { data: songData, isLoading: songLoading, isError, refetch } = useGetSongShow(publicId, {
    query: { enabled: !!publicId },
  })
  const song = asSongFromData(songData)

  const albumId: string | undefined = song?.albumId
  const { data: albumData } = useGetAlbumShow(albumId ?? '', {
    query: { enabled: !!albumId },
  })
  const album = asAlbumFromData(albumData)

  const coverUrl = album?.coverImage?.url ?? null
  const { src: coverSrc } = useImageBlob(coverUrl)

  const handlePlay = useCallback(() => {
    if (!song) return
    const primaryArtist = album?.artists
      ?.filter((a) => a.role === null || a.role === 'artist')
      .map((a) => a.name)
      .filter(Boolean)
      .join(', ')
    const track: Track = {
      publicId: song.publicId ?? publicId,
      title: song.title ?? 'Unknown',
      artistName: primaryArtist || undefined,
      albumName: album?.title,
      duration: song.length ?? undefined,
    }
    playTrack(track)
  }, [song, album, publicId, playTrack])

  const handleOpenAlbum = useCallback(() => {
    if (albumId) {
      navigate(`/albums/${albumId}`)
    }
  }, [albumId, navigate])

  if (songLoading) {
    return (
      <LoadingContainer>
        <Skeleton style={{ margin: '0 auto', height: '10rem', width: '10rem', borderRadius: 'var(--radius-lg)' }} />
        <LoadingHeader>
          <Skeleton style={{ height: '1.25rem', width: '8rem' }} />
          <Skeleton style={{ height: '1rem', width: '6rem' }} />
        </LoadingHeader>
        <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
          <Skeleton style={{ height: '2rem', width: '100%' }} />
          <Skeleton style={{ height: '2rem', width: '100%' }} />
        </div>
      </LoadingContainer>
    )
  }

  if (isError || !song) {
    return (
      <ErrorContainer>
        <ErrorText>Failed to load song</ErrorText>
        <Button variant="ghost" size="sm" onClick={() => refetch()}>Retry</Button>
      </ErrorContainer>
    )
  }

  const duration = song.length
  const bitrate = typeof song.bitrate === 'number' ? song.bitrate : null

  const artistNames = album?.artists
    ?.filter((a) => a.role === null || a.role === 'artist')
    .map((a) => a.name)
    .filter(Boolean)

  const trackLabel =
    song.track != null
      ? song.disc != null && song.disc > 1
        ? `${song.disc}-${song.track}`
        : String(song.track)
      : null

  return (
    <SongContextMenu song={{
      publicId,
      title: song.title ?? 'Unknown',
      artistName: artistNames?.join(', '),
      albumName: album?.title,
      duration: song.length ?? undefined,
      albumId: albumId,
    }}>
      <Container>
      {/* Header */}
      <HeaderSection>
        <CoverArea>
          {coverSrc ? (
            <CoverImage src={coverSrc} alt={album?.title ?? 'Album'} loading="lazy" />
          ) : (
            <Music size={32} style={{ color: 'color-mix(in srgb, var(--color-muted-foreground) 20%, transparent)' }} />
          )}
        </CoverArea>
        <div>
          <SongTitle>{song.title ?? 'Unknown'}</SongTitle>
          {artistNames && artistNames.length > 0 && (
            <ArtistText>{artistNames.join(', ')}</ArtistText>
          )}
          {album?.title && (
            <AlbumText>{album.title}</AlbumText>
          )}
        </div>
        <Button size="sm" onClick={handlePlay}>
          <Play size={14} fill="currentColor" />
          Play
        </Button>
      </HeaderSection>

      {/* Metadata */}
      <MetadataGrid>
        {artistNames && artistNames.length > 0 && (
          <MetadataRow>
            <MetadataLabel>Artist</MetadataLabel>
            <MetadataValue>{artistNames.join(', ')}</MetadataValue>
          </MetadataRow>
        )}
        {album?.title && (
          <MetadataRow>
            <MetadataLabel>Album</MetadataLabel>
            <MetadataValue>{album.title}</MetadataValue>
          </MetadataRow>
        )}
        {trackLabel != null && (
          <MetadataRow>
            <MetadataLabel>Track</MetadataLabel>
            <MonoValue>{trackLabel}</MonoValue>
          </MetadataRow>
        )}
        {duration != null && (
          <MetadataRow>
            <MetadataLabel>Duration</MetadataLabel>
            <MonoValue>{formatDuration(duration)}</MonoValue>
          </MetadataRow>
        )}
        {bitrate != null && (
          <MetadataRow>
            <MetadataLabel>Bitrate</MetadataLabel>
            <MonoValue>{bitrate} kbps</MonoValue>
          </MetadataRow>
        )}
        {song.explicit && (
          <MetadataRow>
            <MetadataLabel>Explicit</MetadataLabel>
            <MetadataValue>Yes</MetadataValue>
          </MetadataRow>
        )}
        {album?.year != null && (
          <MetadataRow>
            <MetadataLabel>Year</MetadataLabel>
            <MonoValue>{album.year}</MonoValue>
          </MetadataRow>
        )}
      </MetadataGrid>

      {/* Open album link */}
      {albumId && (
        <Button variant="outline" size="sm" onClick={handleOpenAlbum} style={{ width: '100%', gap: '0.375rem' }}>
          <ExternalLink size={12} />
          Open Album
        </Button>
      )}
    </Container>
    </SongContextMenu>
  )
}
