import { useState } from 'react'
import { useParams } from 'react-router-dom'
import styled from 'styled-components'
import { useGetMovieShow } from '@/shared/api-client/gen/endpoints'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { Button } from '@/shared/components/ui/button'
import { asMovieFromData } from '../utils/api-adapters'
import { MovieHeader } from '../components/MovieHeader'
import { MovieMetadata } from '../components/MovieMetadata'
import { requestPlayback } from '@/features/session/services/playback-execution'
import { usePlaybackSession } from '@/features/session/hooks/use-playback-session'
import { useVideoPresentation } from '../stores/video-presentation-store'
import { MoviePlayerOverlay } from '../components/MoviePlayerOverlay'
import { textRole } from '@/shared/theme'

const CenterMessage = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  padding: 5rem 0;
`

const ErrorText = styled.p`
  ${textRole('body')}
  color: var(--color-destructive);
`

const LoadingContainer = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
  padding: 1.5rem;
`

const PageContainer = styled.div`
  display: flex;
  height: 100%;
  flex-direction: column;
  overflow-y: auto;
`

export function MovieDetailPage() {
  const { publicId } = useParams<{ publicId: string }>()
  const { data, isLoading, isError, refetch } = useGetMovieShow(publicId!)
  const [isPlaying, setIsPlaying] = useState(false)
  const { snapshot } = usePlaybackSession()
  const error = useVideoPresentation(state => state.error)

  if (isError) {
    return (
      <CenterMessage>
        <ErrorText>Failed to load movie</ErrorText>
        <Button variant="ghost" size="sm" onClick={() => refetch()}>Retry</Button>
      </CenterMessage>
    )
  }

  const movie = asMovieFromData(data)

  if (isLoading || !data?.data || !movie) {
    return (
      <LoadingContainer>
        <Skeleton style={{ height: '16rem', width: '100%', borderRadius: '0.5rem' }} />
        <Skeleton style={{ height: '2rem', width: '16rem' }} />
        <Skeleton style={{ height: '1rem', width: '12rem' }} />
        <Skeleton style={{ height: '8rem', width: '100%' }} />
      </LoadingContainer>
    )
  }

  const video = movie.videos?.[0]
  if (isPlaying && video) {
    return (
      <MoviePlayerOverlay
        title={movie.title}
        videoId={video.publicId}
        sourceDuration={video.duration}
        onClose={() => setIsPlaying(false)}
      />
    )
  }

  return (
    <PageContainer>
      <MovieHeader movie={movie} onPlay={() => {
        if (!video) return
        if (snapshot?.media?.kind === 'video' && snapshot.media.publicId === video.publicId) {
          setIsPlaying(true)
          return
        }
        requestPlayback({ action: 'load', media: { kind: 'video', publicId: video.publicId }, desiredState: 'playing' })
          .then(() => setIsPlaying(true)).catch(useVideoPresentation.getState().reportError)
      }} />
      {error ? <ErrorText role="alert">{error}</ErrorText> : null}
      <MovieMetadata movie={movie} />
    </PageContainer>
  )
}
