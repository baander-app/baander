import { Link, Navigate, useSearchParams } from 'react-router-dom'
import styled from 'styled-components'
import { ContentText } from '@/shared/typography'
import { focusVisibleRing } from '@/shared/theme'
import { useTvSeriesIndex, type TvSeriesResource } from '@/shared/api-client/gen/endpoints'
import { retryPersonRead } from '@/features/catalog/api/people-api'
import { PaginatedGrid } from '@/features/catalog/components/PaginatedGrid'
import {
  DirectorBrowseEmpty,
  DirectorBrowseError,
  DirectorBrowseLayout,
  DirectorBrowseLoading,
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
  img { width: 100%; aspect-ratio: 2 / 3; object-fit: cover; border-radius: var(--radius-md); }
`
export function TVSeriesPage() {
  const [params, setParams] = useSearchParams()
  const requested = Number(params.get('page') ?? 1)
  const page = Number.isSafeInteger(requested) && requested > 0 ? requested : 1
  const q = params.get('q') ?? ''
  const series = useTvSeriesIndex({ page, limit: 50, q }, { query: { retry: retryPersonRead } })
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
        <DirectorBrowseLoading />
      ) : !series.data?.data.length ? (
        <DirectorBrowseEmpty>{q ? 'No matching shows.' : 'No TV shows registered yet.'}</DirectorBrowseEmpty>
      ) : (
        <PaginatedGrid
          pagination={{
            currentPage: page,
            lastPage: series.data.meta.last_page,
            total: series.data.meta.total,
          }}
          onPageChange={value => change('page', String(value))}
        >
          {(series.data.data as TvSeriesResource[]).map(show => (
            <Card to={`/tv/series/${show.publicId}`} key={show.publicId}>
              {show.posterUrl ? <img src={show.posterUrl} alt="" loading="lazy" /> : null}
              <ContentText text={show.name} textRole="body" element="h2" />
            </Card>
          ))}
        </PaginatedGrid>
      )}
    </DirectorBrowseLayout>
  )
}
