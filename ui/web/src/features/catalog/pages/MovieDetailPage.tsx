import { useState } from 'react'
import { useParams } from 'react-router-dom'
import styled from 'styled-components'
import { useGetMovieShow } from '@/shared/api-client/gen/endpoints'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { Button } from '@/shared/components/ui/button'
import { MovieHeader } from '../components/MovieHeader'
import { MovieMetadata } from '../components/MovieMetadata'
import { MoviePlayerOverlay } from '../components/MoviePlayerOverlay'

const CenterMessage = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  padding: 5rem 0;
`

const ErrorText = styled.p`
  font-size: 0.875rem;
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

  if (isError) {
    return (
      <CenterMessage>
        <ErrorText>Failed to load movie</ErrorText>
        <Button variant="ghost" size="sm" onClick={() => refetch()}>Retry</Button>
      </CenterMessage>
    )
  }

  if (isLoading || !data?.data) {
    return (
      <LoadingContainer>
        <Skeleton style={{ height: '16rem', width: '100%', borderRadius: '0.5rem' }} />
        <Skeleton style={{ height: '2rem', width: '16rem' }} />
        <Skeleton style={{ height: '1rem', width: '12rem' }} />
        <Skeleton style={{ height: '8rem', width: '100%' }} />
      </LoadingContainer>
    )
  }

  const movie = data.data as any

  if (isPlaying && movie.videos?.length > 0) {
    return (
      <MoviePlayerOverlay
        title={movie.title}
        videoId={movie.videos[0].publicId ?? movie.videos[0].uuid}
        onClose={() => setIsPlaying(false)}
      />
    )
  }

  return (
    <PageContainer>
      <MovieHeader movie={movie} onPlay={() => setIsPlaying(true)} />
      <MovieMetadata movie={movie} />
    </PageContainer>
  )
}
