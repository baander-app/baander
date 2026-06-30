import { useState, useEffect } from 'react'
import styled from 'styled-components'
import type { GetAlbumIndexParams } from '@/shared/api-client/gen/endpoints'
import { useGetAlbumIndex } from '@/shared/api-client/gen/endpoints'
import { asAlbums, extractPaginatedMeta } from '../utils/api-adapters'
import type { AlbumSummary } from '../types'
import { AlbumGridCard } from '../components/AlbumGridCard'
import { Button } from '@/shared/components/ui/button'
import { Skeleton } from '@/shared/components/ui/skeleton'

const GridContainer = styled.div`
  display: grid;
  gap: 1rem;
`

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

const EmptyText = styled.p`
  padding: 1rem 0;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const LoadMoreRow = styled.div`
  margin-top: 1.5rem;
  display: flex;
  justify-content: center;
`

interface GridViewProps {
  params?: Omit<GetAlbumIndexParams, 'page'>
}

const SKELETON_COUNT = 12
const PER_PAGE = 24

export function GridView({ params }: GridViewProps) {
  const [page, setPage] = useState(1)

  useEffect(() => { setPage(1) }, [params])

  const { data, isLoading, isError, refetch } = useGetAlbumIndex({
    ...params,
    page,
    limit: PER_PAGE,
  })

  const albums: AlbumSummary[] = asAlbums(data)
  const meta = extractPaginatedMeta(data)
  const hasNextPage = meta.currentPage < meta.lastPage

  if (isError) {
    return (
      <CenterMessage>
        <ErrorText>Failed to load albums</ErrorText>
        <Button variant="ghost" size="sm" onClick={() => refetch()}>
          Retry
        </Button>
      </CenterMessage>
    )
  }

  if (isLoading) {
    return <GridSkeleton />
  }

  if (!albums?.length) {
    return (
      <EmptyText>No albums found</EmptyText>
    )
  }

  return (
    <>
      <GridContainer
        style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))' }}
        role="grid"
        aria-label="Albums grid"
      >
        {albums.map((album) => (
          <AlbumGridCard
            key={album.publicId}
            publicId={album.publicId}
            title={album.title ?? 'Unknown'}
            artistName={album.artists.map((a) => a.name).join(', ') || undefined}
            imageUrl={album.coverImage?.url}
            blurhash={album.coverImage?.blurhash}
          />
        ))}
      </GridContainer>

      {hasNextPage && (
        <LoadMoreRow>
          <Button variant="ghost" size="sm" onClick={() => setPage((p) => p + 1)}>
            Load more
          </Button>
        </LoadMoreRow>
      )}
    </>
  )
}

function GridSkeleton() {
  return (
    <GridContainer
      style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))' }}
    >
      {Array.from({ length: SKELETON_COUNT }).map((_, i) => (
        <div key={i}>
          <Skeleton style={{ aspectRatio: '1', borderRadius: '0.375rem' }} />
          <Skeleton style={{ marginTop: '0.5rem', height: '1rem', width: '75%' }} />
          <Skeleton style={{ marginTop: '0.25rem', height: '0.75rem', width: '50%' }} />
        </div>
      ))}
    </GridContainer>
  )
}
