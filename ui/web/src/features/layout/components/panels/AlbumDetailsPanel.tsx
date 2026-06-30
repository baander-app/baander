import styled from 'styled-components'
import { useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { Play, Shuffle, ExternalLink } from 'lucide-react'
import { useGetAlbumShow } from '@/shared/api-client/gen/endpoints'
import { useImageBlob } from '@/shared/hooks/use-image-blob'
import { formatDuration } from '@/shared/utils/format-duration'
import { usePlayerStore, type Track } from '@/features/player/stores/player-store'
import { Button } from '@/shared/components/ui/button'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { AlbumContextMenu } from '@/features/catalog/components/menus/AlbumContextMenu'

/* eslint-disable @typescript-eslint/no-explicit-any */

const CoverSkeleton = styled(Skeleton)`
  margin-inline: auto;
  height: 10rem;
  width: 10rem;
  border-radius: var(--radius-lg);
`

const TitleSkeleton = styled(Skeleton)`
  height: 1.25rem;
  width: 8rem;
`

const SubtitleSkeleton = styled(Skeleton)`
  height: 1rem;
  width: 6rem;
`

const ActionSkeleton = styled(Skeleton)`
  height: 2rem;
  flex: 1;
`

const TrackSkeleton = styled(Skeleton)`
  height: 2rem;
  width: 100%;
`

const FullWidthButton = styled(Button)`
  width: 100%;
  gap: 0.375rem;
`

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1rem;
`

const Header = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.75rem;
  text-align: center;
`

const CoverContainer = styled.div`
  display: flex;
  height: 10rem;
  width: 10rem;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  border-radius: var(--radius-lg);
  background-color: var(--color-secondary);
`

const CoverImg = styled.img`
  height: 100%;
  width: 100%;
  object-fit: cover;
`

const AlbumTitle = styled.h3`
  font-size: 1rem;
  font-weight: 500;
  color: var(--color-foreground);
  margin: 0;
`

const AlbumArtist = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
  margin: 0;
`

const AlbumMeta = styled.p`
  font-size: 0.75rem;
  color: color-mix(in srgb, var(--color-muted-foreground) 70%, transparent);
  margin: 0;
`

const ActionRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
`

const SummarySection = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
  font-size: 0.875rem;
`

const SummaryRow = styled.div`
  display: flex;
  justify-content: space-between;
`

const SummaryLabel = styled.span`
  color: var(--color-muted-foreground);
`

const SummaryValue = styled.span`
  font-family: var(--font-mono);
  font-size: 0.75rem;
  font-variant-numeric: tabular-nums;
  color: var(--color-foreground);
`

const TrackList = styled.ul`
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
  list-style: none;
  margin: 0;
  padding: 0;
`

const TrackItem = styled.li`
  display: flex;
  cursor: pointer;
  align-items: center;
  gap: 0.5rem;
  border-radius: var(--radius-md);
  padding: 0.375rem 0.5rem;
  font-size: 0.875rem;
  transition: background-color 150ms ease;

  &:hover {
    background-color: color-mix(in srgb, var(--color-accent) 50%, transparent);
  }
`

const TrackIndex = styled.span`
  width: 1.25rem;
  flex-shrink: 0;
  text-align: right;
  font-size: 0.75rem;
  font-variant-numeric: tabular-nums;
  color: var(--color-muted-foreground);
`

const TrackName = styled.div`
  min-width: 0;
  flex: 1;
`

const TrackNameText = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: color-mix(in srgb, var(--color-foreground) 90%, transparent);
  margin: 0;
`

const TrackDuration = styled.span`
  font-size: 0.75rem;
  font-variant-numeric: tabular-nums;
  color: var(--color-muted-foreground);
  font-family: var(--font-mono);
`

const ErrorState = styled.div`
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

const LoadingActions = styled.div`
  display: flex;
  gap: 0.5rem;
  padding: 0 0.5rem;
`

const LoadingTracks = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding-top: 0.5rem;
`

interface AlbumDetailsPanelProps {
  publicId: string
}

function buildTracks(songs: any[], albumTitle: string): Track[] {
  return songs.map((s: any) => ({
    publicId: String(s.publicId ?? ''),
    title: String(s.title ?? ''),
    artistName: s.artistName ? String(s.artistName) : undefined,
    albumName: albumTitle,
    duration: typeof s.length === 'number' ? s.length : (typeof s.duration === 'number' ? s.duration : undefined),
  }))
}

export function AlbumDetailsPanel({ publicId }: AlbumDetailsPanelProps) {
  const navigate = useNavigate()
  const { data, isLoading, isError, refetch } = useGetAlbumShow(publicId, {
    query: { enabled: !!publicId },
  })
  const album = (data as any)?.data as any
  const playTrack = usePlayerStore((s) => s.playTrack)

  const coverUrl = album?.coverImage?.url ?? album?.coverUrl ?? null
  const { src: coverSrc } = useImageBlob(coverUrl)

  const songs: any[] = album?.songs ?? []
  const totalDuration = songs.reduce(
    (acc: number, s: any) => acc + (typeof s.length === 'number' ? s.length : (typeof s.duration === 'number' ? s.duration : 0)),
    0,
  )

  const handlePlay = useCallback(() => {
    if (songs.length === 0) return
    const tracks = buildTracks(songs, album?.title ?? '')
    playTrack(tracks[0], tracks)
  }, [songs, album, playTrack])

  const handleShuffle = useCallback(() => {
    if (songs.length === 0) return
    const tracks = [...buildTracks(songs, album?.title ?? '')].sort(() => Math.random() - 0.5)
    playTrack(tracks[0], tracks)
  }, [songs, album, playTrack])

  const handlePlayTrack = useCallback(
    (song: any) => {
      const tracks = buildTracks(songs, album?.title ?? '')
      const track: Track = {
        publicId: String(song.publicId ?? ''),
        title: String(song.title ?? ''),
        artistName: song.artistName ? String(song.artistName) : undefined,
        albumName: album?.title,
        duration: typeof song.length === 'number' ? song.length : (typeof song.duration === 'number' ? song.duration : undefined),
      }
      playTrack(track, tracks)
    },
    [songs, album, playTrack],
  )

  const handleOpen = useCallback(() => {
    navigate(`/albums/${publicId}`)
  }, [publicId, navigate])

  if (isLoading) {
    return (
      <LoadingContainer>
        <CoverSkeleton />
        <LoadingHeader>
          <TitleSkeleton />
          <SubtitleSkeleton />
        </LoadingHeader>
        <LoadingActions>
          <ActionSkeleton />
          <ActionSkeleton />
        </LoadingActions>
        <LoadingTracks>
          {Array.from({ length: 6 }).map((_, i) => (
            <TrackSkeleton key={i} />
          ))}
        </LoadingTracks>
      </LoadingContainer>
    )
  }

  if (isError || !album) {
    return (
      <ErrorState>
        <ErrorText>Failed to load album</ErrorText>
        <Button variant="ghost" size="sm" onClick={() => refetch()}>Retry</Button>
      </ErrorState>
    )
  }

  return (
    <AlbumContextMenu album={{ publicId, title: album?.title, artistName: album?.artistName }}>
      <Container>
      {/* Album header */}
      <Header>
        <CoverContainer>
          {coverSrc ? (
            <CoverImg src={coverSrc} alt={album.title} loading="lazy" />
          ) : (
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" style={{ color: 'color-mix(in srgb, var(--color-muted-foreground) 20%, transparent)' }}>
              <circle cx="12" cy="12" r="10" />
              <circle cx="12" cy="12" r="3" />
            </svg>
          )}
        </CoverContainer>
        <div>
          <AlbumTitle>{album.title}</AlbumTitle>
          <AlbumArtist>{album.artistName}</AlbumArtist>
          {(album.year || album.label) && (
            <AlbumMeta>{[album.year, album.label].filter(Boolean).join(' · ')}</AlbumMeta>
          )}
        </div>
        {songs.length > 0 && (
          <ActionRow>
            <Button size="sm" onClick={handlePlay}>
              <Play size={14} fill="currentColor" />
              Play
            </Button>
            <Button variant="outline" size="sm" onClick={handleShuffle}>
              <Shuffle size={14} />
            </Button>
          </ActionRow>
        )}
      </Header>

      {/* Summary metadata */}
      {songs.length > 0 && (
        <SummarySection>
          <SummaryRow>
            <SummaryLabel>Tracks</SummaryLabel>
            <SummaryValue>{songs.length}</SummaryValue>
          </SummaryRow>
          {totalDuration > 0 && (
            <SummaryRow>
              <SummaryLabel>Duration</SummaryLabel>
              <SummaryValue>{formatDuration(totalDuration)}</SummaryValue>
            </SummaryRow>
          )}
        </SummarySection>
      )}

      {/* Tracklist */}
      {songs.length > 0 && (
        <TrackList>
          {songs.map((song: any, index: number) => (
            <TrackItem
              key={song.publicId ?? index}
              onDoubleClick={() => handlePlayTrack(song)}
            >
              <TrackIndex>{index + 1}</TrackIndex>
              <TrackName>
                <TrackNameText>{song.title ?? 'Unknown'}</TrackNameText>
              </TrackName>
              <TrackDuration>
                {typeof (song.length ?? song.duration) === 'number' ? formatDuration((song.length ?? song.duration) as number) : '\u2014'}
              </TrackDuration>
            </TrackItem>
          ))}
        </TrackList>
      )}

      {/* Open button */}
      <FullWidthButton variant="outline" size="sm" onClick={handleOpen}>
        <ExternalLink size={12} />
        Open
      </FullWidthButton>
    </Container>
    </AlbumContextMenu>
  )
}
