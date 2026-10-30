import { isAxiosError } from 'axios'
import { useDeletePlaylistRemoveCollaborator } from '@/features/playlist/hooks/use-playlist-mutations'
import { useContextPanelStore } from '@/features/layout/stores/context-panel-store'
import { useParams, Link, useNavigate } from 'react-router-dom'
import styled from 'styled-components'
import { useGetPlaylistShow, type GetPlaylistShow200DataSongsItem } from '@/shared/api-client/gen/endpoints'
import { usePlayerStore, type Track } from '@/features/player/stores/player-store'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { parseApiError } from '@/features/auth/lib/parse-api-error'
import { Button } from '@/shared/components/ui/button'
import { Badge } from '@/shared/components/ui/badge'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { ArrowLeft, LogOut, Play } from 'lucide-react'
import { useMemo } from 'react'
import { toast } from 'sonner'
import { formatDuration } from '@/shared/utils/format-duration'
import { SharePlaylistDialog } from '../components/SharePlaylistDialog'

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

const ActionRow = styled.div`
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
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
    background-color: color-mix(in srgb, var(--color-highlight) 50%, transparent);
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

const EMPTY_SONGS: GetPlaylistShow200DataSongsItem[] = []

function formatTrackDuration(seconds: number): string {
  return `${Math.floor(seconds / 60)}:${(seconds % 60).toString().padStart(2, '0')}`
}

export function PlaylistDetailPage() {
  const { publicId } = useParams<{ publicId: string }>()
  const navigate = useNavigate()
  const { data, isLoading, error } = useGetPlaylistShow(
    publicId!,
    { query: { enabled: !!publicId } }
  )
  const playTrack = usePlayerStore((s) => s.playTrack)
  const setSelectedItem = useContextPanelStore((s) => s.setSelectedItem)
  const viewerPublicId = useAuthStore((s) => s.user?.publicId)
  const leave = useDeletePlaylistRemoveCollaborator({
    mutation: {
      onSuccess: () => navigate('/music/playlists'),
      onError: (failure: unknown) => {
        toast.error(parseApiError(failure, 'You could not leave the playlist.').message)
      },
    },
  })

  const denied = isAxiosError(error) && [403, 404].includes(error.response?.status ?? 0)
  const playlist = denied ? undefined : data?.data
  const songs = playlist?.songs ?? EMPTY_SONGS

  const tracks: Track[] = useMemo(() =>
    songs
      .filter((s): s is GetPlaylistShow200DataSongsItem & { publicId: string } => !!s.publicId && s.available === true)
      .map((s) => ({
        publicId: s.publicId,
        available: s.available === true,
        title: s.title ?? 'Unknown',
        artistName: s.artistName ?? undefined,
        albumName: s.albumName ?? undefined,
        albumPublicId: s.albumId ?? undefined,
        duration: s.length ?? undefined,
      })),
    [songs]
  )

  if (isLoading) {
    return (
      <LoadingContainer>
        <Skeleton style={{ height: '2rem', width: '12rem' }} />
        <Skeleton style={{ height: '1rem', width: '8rem' }} />
        <Skeleton style={{ height: '2.5rem', width: '6rem' }} />
      </LoadingContainer>
    )
  }

  if (!playlist || !publicId) {
    return <NotFoundMessage>Playlist not found</NotFoundMessage>
  }

  const totalDuration = songs.reduce((sum, s) => sum + (s.length ?? 0), 0)
  const isOwner = viewerPublicId !== undefined && playlist.ownerPublicId === viewerPublicId
  const isCollaborator = viewerPublicId !== undefined && (playlist.collaboratorPublicIds ?? []).includes(viewerPublicId)

  const handlePlayAll = () => {
    if (tracks.length > 0) playTrack(tracks[0], tracks)
  }

  const handleLeave = () => {
    if (!viewerPublicId) return
    leave.mutate({ publicId, userPublicId: viewerPublicId })
  }

  return (
    <PageContainer>
      <HeaderRow>
        <BackLink to="/music/playlists">
          <ArrowLeft size={20} />
        </BackLink>
        <HeaderInfo>
          <PlaylistTitle>{playlist.name}</PlaylistTitle>
          {playlist.description && (
            <PlaylistDescription>{playlist.description}</PlaylistDescription>
          )}
          <MetaRow>
            <MetaText>
              {songs.length} {songs.length === 1 ? 'song' : 'songs'}
              {totalDuration > 0 && ` · ${formatTrackDuration(totalDuration)}`}
            </MetaText>
            {playlist.isSmart && <Badge variant="secondary" style={{ fontSize: '10px' }}>Smart</Badge>}
          </MetaRow>
        </HeaderInfo>
      </HeaderRow>
      <ActionRow>
        {tracks.length > 0 && (
          <Button size="sm" onClick={handlePlayAll}>
            <Play size={14} style={{ marginRight: '0.25rem' }} /> Play all
          </Button>
        )}
        {isOwner && <SharePlaylistDialog playlistPublicId={publicId} />}
        {isCollaborator && (
          <Button variant="outline" size="sm" onClick={handleLeave} disabled={leave.isPending}>
            <LogOut size={14} /> Leave playlist
          </Button>
        )}
      </ActionRow>
      <SongList>
        {songs.map((song, i) => (
          <SongItem
            key={`${song.publicId}-${i}`}
            onClick={() => {
              if (song.publicId) setSelectedItem({ type: 'song', publicId: song.publicId })
              const selected = tracks.find((track) => track.publicId === song.publicId)
              if (selected) playTrack(selected, tracks)
            }}
          >
            <SongPosition>{song.position ?? i + 1}</SongPosition>
            <SongInfo>
              <SongTitle>{song.title || song.publicId}{song.available === false && <span> · Unavailable</span>}</SongTitle>
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
