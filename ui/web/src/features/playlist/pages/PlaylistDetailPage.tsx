import { useParams, Link } from 'react-router-dom'
import styled from 'styled-components'
import { useGetPlaylistShow } from '@/shared/api-client/gen/endpoints'
import { usePlayerStore, type Track } from '@/features/player/stores/player-store'
import { Button } from '@/shared/components/ui/button'
import { Badge } from '@/shared/components/ui/badge'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { ArrowLeft, Play } from 'lucide-react'
import { useMemo } from 'react'
import { formatDuration } from '@/shared/utils/format-duration'

const PageContainer = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1.5rem;
`

const LoadingContainer = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1.5rem;
`

const NotFoundMessage = styled.div`
  padding: 1.5rem;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const HeaderRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.75rem;
`

const BackLink = styled(Link)`
  color: var(--color-muted-foreground);

  &:hover {
    color: var(--color-foreground);
  }
`

const HeaderInfo = styled.div`
  min-width: 0;
  flex: 1;
`

const PlaylistTitle = styled.h1`
  font-size: 1.125rem;
  font-weight: 600;
`

const PlaylistDescription = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const MetaRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-top: 0.25rem;
`

const MetaText = styled.span`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const SongList = styled.ul`
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
  list-style: none;
  margin: 0;
  padding: 0;
`

const SongItem = styled.li`
  display: flex;
  align-items: center;
  gap: 0.75rem;
  border-radius: var(--radius-md);
  padding: 0.5rem 0.75rem;
  font-size: 0.875rem;
  cursor: pointer;

  &:hover {
    background-color: color-mix(in srgb, var(--color-accent) 50%, transparent);
  }
`

const SongPosition = styled.span`
  width: 1.5rem;
  text-align: right;
  color: var(--color-muted-foreground);
`

const SongInfo = styled.div`
  min-width: 0;
  flex: 1;
`

const SongTitle = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: 500;
`

const SongArtist = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const SongDuration = styled.span`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

interface PlaylistSongResponse {
  position: number
  publicId: string
  title: string
  artistName?: string
  albumName?: string
  albumId?: string
  length?: number
}

function formatTrackDuration(seconds: number): string {
  return `${Math.floor(seconds / 60)}:${(seconds % 60).toString().padStart(2, '0')}`
}

export function PlaylistDetailPage() {
  const { publicId } = useParams<{ publicId: string }>()
  const { data, isLoading } = useGetPlaylistShow(
    publicId!,
    { query: { enabled: !!publicId } }
  )
  const playTrack = usePlayerStore((s) => s.playTrack)

  const playlist = (data as { data?: Record<string, unknown> })?.data ?? data as Record<string, unknown> | undefined

  if (isLoading) {
    return (
      <LoadingContainer>
        <Skeleton style={{ height: '2rem', width: '12rem' }} />
        <Skeleton style={{ height: '1rem', width: '8rem' }} />
        <Skeleton style={{ height: '2.5rem', width: '6rem' }} />
      </LoadingContainer>
    )
  }

  if (!playlist) {
    return <NotFoundMessage>Playlist not found</NotFoundMessage>
  }

  const songs = (playlist.songs ?? []) as PlaylistSongResponse[]

  const tracks: Track[] = useMemo(() =>
    songs
      .filter((s) => s.publicId)
      .map((s) => ({
        publicId: s.publicId,
        title: s.title ?? 'Unknown',
        artistName: s.artistName ?? undefined,
        albumName: s.albumName ?? undefined,
        albumPublicId: s.albumId ?? undefined,
        duration: s.length ?? undefined,
      })),
    [songs]
  )

  const totalDuration = songs.reduce((sum, s) => sum + (s.length ?? 0), 0)

  const handlePlayAll = () => {
    if (tracks.length > 0) playTrack(tracks[0], tracks)
  }

  return (
    <PageContainer>
      <HeaderRow>
        <BackLink to="/music/playlists">
          <ArrowLeft size={20} />
        </BackLink>
        <HeaderInfo>
          <PlaylistTitle>{playlist.name as string}</PlaylistTitle>
          {(playlist.description as string) && (
            <PlaylistDescription>{playlist.description as string}</PlaylistDescription>
          )}
          <MetaRow>
            <MetaText>
              {songs.length} {songs.length === 1 ? 'song' : 'songs'}
              {totalDuration > 0 && ` · ${formatTrackDuration(totalDuration)}`}
            </MetaText>
            {playlist.isSmart as boolean && <Badge variant="secondary" style={{ fontSize: '10px' }}>Smart</Badge>}
          </MetaRow>
        </HeaderInfo>
      </HeaderRow>
      {tracks.length > 0 && (
        <Button size="sm" onClick={handlePlayAll} style={{ width: 'fit-content' }}>
          <Play size={14} style={{ marginRight: '0.25rem' }} /> Play all
        </Button>
      )}
      <SongList>
        {songs.map((song, i) => (
          <SongItem
            key={`${song.publicId}-${i}`}
            onClick={() => playTrack(tracks[i], tracks)}
          >
            <SongPosition>{song.position ?? i + 1}</SongPosition>
            <SongInfo>
              <SongTitle>{song.title || song.publicId}</SongTitle>
              <SongArtist>{song.artistName ?? 'Unknown artist'}</SongArtist>
            </SongInfo>
            {song.length != null && (
              <SongDuration>
                {formatDuration(song.length)}
              </SongDuration>
            )}
          </SongItem>
        ))}
      </SongList>
    </PageContainer>
  )
}
