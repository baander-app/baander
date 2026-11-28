import { useParams } from 'react-router-dom'
import styled from 'styled-components'
import { useGetMovieShow, type MovieResource } from '@/shared/api-client/gen/endpoints'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { Button } from '@/shared/components/ui/button'
import { ProgressBar } from '@/shared/components/progress-bar'
import { useImageBlob } from '@/shared/hooks/use-image-blob'
import { textRole } from '@/shared/theme'
import { asMovieFromData } from '../utils/api-adapters'
import { MovieHeader } from '../components/MovieHeader'
import { MovieMetadata } from '../components/MovieMetadata'
import { ActiveVideoOverlay } from '../components/ActiveVideoOverlay'
import { WorkPeople } from '../components/WorkPeople'
import { ExtrasRow } from '../components/ExtrasRow'
import { MetadataAttribution } from '../components/MetadataAttribution'
import { VideoActionMenu } from '../components/VideoActionMenu'
import { useVideoDetailPlayback } from '../components/use-video-detail-playback'
import { useVideoWatched } from '../components/use-video-watched'

const CenterMessage = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: var(--space-md);
  padding: var(--space-xl) 0;
`

const ErrorText = styled.p`
  ${textRole('body')}
  color: var(--color-destructive);
`

const LoadingContainer = styled.div`
  display: flex;
  flex-direction: column;
  gap: var(--space-lg);
  padding: var(--space-lg);
`

const PageContainer = styled.div`
  display: flex;
  height: 100%;
  flex-direction: column;
  overflow-y: auto;
`

const HeaderActions = styled(VideoActionMenu)`
  align-items: flex-end;
  padding-inline-end: var(--space-lg);

  > :first-child {
    flex: 1;
    min-width: 0;
  }
`

const DetailSection = styled.div`
  display: grid;
  gap: var(--space-lg);
  padding: 0 var(--space-lg) var(--space-lg);
`

const Watched = styled.p`
  ${textRole('caption')}
  color: var(--color-muted-foreground);
`

function MovieDetail({ data, publicId }: { data: MovieResource; publicId: string }) {
  const playback = useVideoDetailPlayback()
  const watched = useVideoWatched()
  const poster = useImageBlob(data.posterUrl?.startsWith('/api/') ? data.posterUrl : null)
  const backdrop = useImageBlob(data.backdropUrl?.startsWith('/api/') ? data.backdropUrl : null)
  const movie = asMovieFromData({ data })

  if (!movie) return null

  const video = data.videos.find(item => item.publicId === data.defaultVideoPublicId)
  const isWatched = watched.pending?.data.watched ?? data.watched
  const actions = watched.actions({ kind: 'movie', publicId }, !video)

  return (
    <>
      <PageContainer>
        <HeaderActions label={`Options for ${movie.title}`} actions={actions}>
          <MovieHeader
            movie={{
              ...movie,
              posterUrl: data.posterUrl?.startsWith('/api/') ? poster.src : movie.posterUrl,
              backdropUrl: data.backdropUrl?.startsWith('/api/') ? backdrop.src : movie.backdropUrl,
            }}
            onPlay={() => {
              if (!video) return
              playback.play({
                titleKind: 'movie',
                titleId: publicId,
                videoId: video.publicId,
                startSeconds: video.positionSeconds ?? 0,
                title: movie.title,
                duration: video.duration,
              })
            }}
          />
        </HeaderActions>
        <DetailSection>
          {playback.error ? <ErrorText role="alert">{playback.error}</ErrorText> : null}
          {isWatched ? <Watched>Watched</Watched> : data.progress ? <ProgressBar value={data.progress * 100} label="Progress" /> : null}
          <MetadataAttribution provider={data.metadataSource} />
          <ExtrasRow extras={data.extras ?? []} onPlay={playback.play} />
        </DetailSection>
        <MovieMetadata movie={movie} />
        <WorkPeople kind="movie" publicId={publicId} />
      </PageContainer>
      <ActiveVideoOverlay active={playback.active} onClose={playback.close} />
    </>
  )
}

export function MovieDetailPage() {
  const { publicId = '' } = useParams<{ publicId: string }>()
  const { data, isLoading, isError, refetch } = useGetMovieShow(publicId)

  if (isError) {
    return (
      <CenterMessage>
        <ErrorText role="alert">Failed to load movie</ErrorText>
        <Button variant="ghost" size="sm" onClick={() => refetch()}>
          Retry
        </Button>
      </CenterMessage>
    )
  }

  if (isLoading || !data?.data) {
    return (
      <LoadingContainer role="status" aria-label="Loading movie">
        <Skeleton style={{ height: '16rem', width: '100%' }} />
        <Skeleton style={{ height: '2rem', width: 'min(100%, 16rem)' }} />
        <Skeleton style={{ height: '1rem', width: 'min(100%, 12rem)' }} />
      </LoadingContainer>
    )
  }

  return <MovieDetail key={publicId} data={data.data} publicId={publicId} />
}
