import { Link, Navigate, useSearchParams } from 'react-router-dom'
import styled from 'styled-components'
import { ContentText } from '@/shared/typography'
import { focusVisibleRing } from '@/shared/theme'
import { useTvSeriesIndex, type TvSeriesResource } from '@/shared/api-client/gen/endpoints'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { retryPersonRead } from '@/features/catalog/api/people-api'
import { PaginatedGrid } from '@/features/catalog/components/PaginatedGrid'
import { VideoArtwork } from '@/features/catalog/components/VideoArtwork'
import {
  DirectorBrowseEmpty,
  DirectorBrowseError,
  DirectorBrowseLayout,
} from '@/features/catalog/components/DirectorBrowseLayout'

const Card = styled(Link)`
  min-width: 0;
  overflow-wrap: anywhere;
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
  color: var(--color-foreground);
  text-decoration: none;
  ${focusVisibleRing}
`

const LoadingGrid = styled.div`
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 9rem), 1fr));
  gap: var(--space-md);
`

const LoadingPoster = styled(Skeleton)`
  aspect-ratio: 2 / 3;
`

export function TVSeriesPage() {
  const [params, setParams] = useSearchParams()
  const requested = Number(params.get('page') ?? 1)
  const page = Number.isSafeInteger(requested) && requested > 0 ? requested : 1
  const q = params.get('q') ?? ''
  const library = params.get('library') ?? undefined
  const series = useTvSeriesIndex({ page, limit: 50, q, library }, { query: { retry: retryPersonRead } })
  const change = (key: string, value: string) => {
    setParams(previous => {
      const next = new URLSearchParams(previous)
      next.delete('page')
      if (value) {
        next.set(key, value)
      } else {
        next.delete(key)
      }
      return next
    })
  }
  if (!series.isError && series.data?.meta.current_page === page && page > series.data.meta.last_page) {
    const next = new URLSearchParams(params)
    next.set('page', String(series.data.meta.last_page))
    return <Navigate to={{ search: next.toString() }} replace />
  }
  return (
    <DirectorBrowseLayout
      title="TV Shows"
      query={q}
      searchLabel="Search TV shows"
      onSearch={value => change('q', value)}
    >
      {series.isError ? (
        <DirectorBrowseError retry={series.refetch} />
      ) : series.isPending ? (
        <LoadingGrid role="status" aria-label="Loading TV shows">
          {[0, 1, 2, 3, 4, 5].map(index => <LoadingPoster key={index} />)}
        </LoadingGrid>
      ) : !series.data?.data.length ? (
        <DirectorBrowseEmpty>{q ? 'No matching shows.' : 'No TV shows are available.'}</DirectorBrowseEmpty>
      ) : (
        <PaginatedGrid
          pagination={{
            currentPage: page,
            lastPage: series.data.meta.last_page,
            total: series.data.meta.total,
          }}
          onPageChange={value => change('page', String(value))}
        >
          {series.data.data.map((show: TvSeriesResource) => (
            <Card to={`/tv/series/${show.publicId}`} key={show.publicId}>
              <VideoArtwork source={show.posterUrl} />
              <ContentText text={show.name} textRole="body" element="h2" />
            </Card>
          ))}
        </PaginatedGrid>
      )}
    </DirectorBrowseLayout>
  )
}
