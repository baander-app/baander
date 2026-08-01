import { useEffect, useRef } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { ArrowLeft, Play, Shuffle } from 'lucide-react'
import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { useBlurhashAccent } from '../hooks/use-blurhash-accent'
import { useArtistDetail } from '../hooks/use-artist-detail'
import { ArtistDiscography } from '../components/ArtistDiscography'
import { useContextPanelStore } from '@/features/layout/stores/context-panel-store'
import { usePlayerStore, type Track } from '@/features/player/stores/player-store'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

const PageContainer = styled.div`
  display: flex;
  height: 100%;
  flex-direction: column;
`

const CompactHeader = styled.div`
  display: flex;
  height: 3rem;
  align-items: center;
  gap: 0.75rem;
  border-bottom: 1px solid var(--color-border);
  padding: 0 1.5rem;
`

const Avatar = styled.div`
  display: flex;
  height: 2rem;
  width: 2rem;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  background-color: var(--color-secondary);
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--color-muted-foreground);
`

const ArtistName = styled.h1`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.875rem;
  font-weight: 600;
  letter-spacing: -0.025em;
`

const AlbumCount = styled.span`
  font-size: 11px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const Spacer = styled.div`
  flex: 1;
`

const ActionButtons = styled.div`
  display: flex;
  align-items: center;
  gap: 0.25rem;
`

const ActionButton = styled(Button)`
  height: 1.75rem;
  gap: 0.25rem;
  padding: 0 0.5rem;
  font-size: 0.75rem;
`

const DiscographyArea = styled.div`
  flex: 1;
  overflow-y: auto;
  padding: 1rem 1.5rem;
`

const LoadMoreRow = styled.div`
  margin-top: 1rem;
  display: flex;
  justify-content: center;
`

const CenterMessage = styled.div`
  display: flex;
  height: 100%;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
`

const ErrorText = styled.p`
  font-size: 0.875rem;
  color: var(--color-destructive);
`

const LoadingHeader = styled.div`
  display: flex;
  height: 3rem;
  align-items: center;
  gap: 0.75rem;
  padding: 0 1.5rem;
`

const LoadingContent = styled.div`
  flex: 1;
  padding: 0 1.5rem;
`

const LoadingGrid = styled.div`
  display: grid;
  gap: 1rem;
`

const LoadingGridItem = styled.div`
  /* wrapper for each skeleton album */
`

export function ArtistDetailPage() {
  const { publicId } = useParams<{ publicId: string }>()
  const navigate = useNavigate()
  const containerRef = useRef<HTMLDivElement>(null)
  const setSelectedItem = useContextPanelStore((s) => s.setSelectedItem)

  const { artist, albums, albumCount, isLoading, error, loadMore, hasNextPage } =
    useArtistDetail(publicId)

  // Select this artist in context panel
  useEffect(() => {
    if (publicId) {
      setSelectedItem({ type: 'artist', publicId })
    }
  }, [publicId, setSelectedItem])

  const artistName: string = typeof artist?.name === 'string' ? artist.name : ''
  const coverImage = artist?.coverImage as Record<string, unknown> | null | undefined
  const artistBlurhash: string | null | undefined = typeof coverImage?.blurhash === 'string' ? coverImage.blurhash : null
  useBlurhashAccent(artistBlurhash, containerRef)

  const playTrack = usePlayerStore((s) => s.playTrack)

  const fetchArtistSongs = async (): Promise<Track[]> => {
    const res = await AXIOS_INSTANCE.get('/api/songs', {
      params: { artistId: publicId, limit: 1000 },
    })
    const body = res.data as Record<string, unknown>
    const items = (Array.isArray(body?.data) ? body.data : []) as Record<string, unknown>[]
    return items.map((s) => ({
      publicId: String(s.publicId ?? ''),
      title: String(s.title ?? ''),
      artistName: artistName || undefined,
      albumName: s.albumName ? String(s.albumName) : undefined,
      albumPublicId: typeof s.albumId === 'string' ? s.albumId : undefined,
      duration: typeof s.length === 'number' ? s.length : undefined,
    }))
  }

  const handlePlay = async () => {
    try {
      const tracks = await fetchArtistSongs()
      if (tracks.length > 0) playTrack(tracks[0], tracks)
    } catch {
      // Silently fail
    }
  }

  const handleShuffle = async () => {
    try {
      const tracks = await fetchArtistSongs()
      if (tracks.length === 0) return
      const shuffled = [...tracks].sort(() => Math.random() - 0.5)
      playTrack(shuffled[0], shuffled)
    } catch {
      // Silently fail
    }
  }

  if (isLoading || !publicId) {
    return (
      <PageContainer>
        <LoadingHeader>
          <Skeleton style={{ height: '2rem', width: '2rem', borderRadius: '50%' }} />
          <Skeleton style={{ height: '1.5rem', width: '1.5rem', borderRadius: '0.25rem' }} />
          <Skeleton style={{ height: '1.25rem', width: '10rem' }} />
          <Skeleton style={{ height: '1.25rem', width: '5rem' }} />
        </LoadingHeader>
        <LoadingContent>
          <LoadingGrid
            style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))' }}
          >
            {Array.from({ length: 6 }).map((_, i) => (
              <LoadingGridItem key={i}>
                <Skeleton style={{ aspectRatio: '1', borderRadius: '0.375rem' }} />
                <Skeleton style={{ marginTop: '0.5rem', height: '1rem', width: '75%', borderRadius: '0.25rem' }} />
                <Skeleton style={{ marginTop: '0.25rem', height: '0.75rem', width: '50%', borderRadius: '0.25rem' }} />
              </LoadingGridItem>
            ))}
          </LoadingGrid>
        </LoadingContent>
      </PageContainer>
    )
  }

  if (error || !artist) {
    return (
      <CenterMessage>
        <ErrorText>Failed to load artist</ErrorText>
        <Button variant="ghost" size="sm" onClick={() => navigate(-1)}>
          Go back
        </Button>
      </CenterMessage>
    )
  }

  const initial = artistName.charAt(0).toUpperCase()

  return (
    <PageContainer ref={containerRef}>
      {/* Compact header */}
      <CompactHeader>
        <Button
          variant="ghost"
          size="icon-xs"
          onClick={() => navigate(-1)}
          aria-label="Go back"
        >
          <ArrowLeft size={16} />
        </Button>

        <Avatar>{initial}</Avatar>

        <ArtistName>{artistName}</ArtistName>

        {albumCount > 0 && (
          <AlbumCount>
            {albumCount} {albumCount === 1 ? 'album' : 'albums'}
          </AlbumCount>
        )}

        <Spacer />

        <ActionButtons>
          <ActionButton
            variant="ghost"
            size="sm"
            aria-label="Play all"
            onClick={handlePlay}
          >
            <Play size={14} fill="currentColor" />
            Play
          </ActionButton>
          <ActionButton
            variant="ghost"
            size="sm"
            aria-label="Shuffle all"
            onClick={handleShuffle}
          >
            <Shuffle size={14} />
            Shuffle
          </ActionButton>
        </ActionButtons>
      </CompactHeader>

      {/* Discography */}
      <DiscographyArea>
        <ArtistDiscography albums={albums} isLoading={false} />
        {hasNextPage && (
          <LoadMoreRow>
            <Button variant="ghost" size="sm" onClick={loadMore}>
              Load more
            </Button>
          </LoadMoreRow>
        )}
      </DiscographyArea>
    </PageContainer>
  )
}
