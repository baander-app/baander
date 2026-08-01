import styled from 'styled-components'
import { useState, useCallback, useMemo } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useGetArtistIndex, useGetGenreIndex } from '@/shared/api-client/gen/endpoints'
import type { PaginatedResponse, GetArtistIndexOrder } from '@/shared/api-client/gen/endpoints'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { Button } from '@/shared/components/ui/button'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { ArtistGridItem } from '../components/ArtistGridItem'
import { SortSelect, type SortOption } from '@/shared/components/ui/sort-select'
import { FilterBar, type FilterOption } from '@/shared/components/ui/filter-bar'
import { asGenres } from '../utils/api-adapters'

const PageContainer = styled.div`
  display: flex;
  height: 100%;
  flex-direction: column;
`

const PageHeader = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1rem 1.5rem;
`

const PageTitle = styled.h1`
  font-size: 1.125rem;
  font-weight: 600;
  letter-spacing: -0.025em;
`

const HeaderActions = styled.div`
  display: flex;
  align-items: center;
  gap: 0.25rem;
`

const FilterArea = styled.div`
  padding: 0 1.5rem 0.5rem;
`

const ContentArea = styled.div`
  flex: 1;
  overflow-y: auto;
  padding: 0 1.5rem 1.5rem;
`

const ResponsiveGrid = styled.div`
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 1rem;

  @media (min-width: 640px) { grid-template-columns: repeat(3, 1fr); }
  @media (min-width: 768px) { grid-template-columns: repeat(4, 1fr); }
  @media (min-width: 1024px) { grid-template-columns: repeat(5, 1fr); }
  @media (min-width: 1280px) { grid-template-columns: repeat(6, 1fr); }
  @media (min-width: 1536px) { grid-template-columns: repeat(7, 1fr); }
`

const ErrorContainer = styled.div`
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

const PaginationRow = styled.div`
  margin-top: 1.5rem;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
`

const PageInfo = styled.span`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const EmptyContainer = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  padding: 5rem 0;
`

const EmptyIcon = styled.svg`
  color: color-mix(in srgb, var(--color-muted-foreground) 30%, transparent);
`

const EmptyTitle = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const SORT_OPTIONS: SortOption[] = [
  { value: 'name_asc', label: 'Name A-Z' },
  { value: 'name_desc', label: 'Name Z-A' },
]

const SORT_MAP: Record<string, { sort: string; order: GetArtistIndexOrder }> = {
  name_asc: { sort: 'name', order: 'asc' },
  name_desc: { sort: 'name', order: 'desc' },
}

export function ArtistsPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const [page, setPage] = useState(1)

  const genre = searchParams.get('genre') ?? null
  const sortBy = searchParams.get('sort') ?? 'name_asc'
  const { sort, order } = SORT_MAP[sortBy] ?? { sort: 'name', order: 'asc' as GetArtistIndexOrder }

  const { data: genreData } = useGetGenreIndex()
  const genreOptions: FilterOption[] = useMemo(() => {
    const genres = asGenres(genreData)
    return genres
      .map((g) => ({ value: g.slug, label: g.name }))
      .sort((a, b) => a.label.localeCompare(b.label))
  }, [genreData])

  const { data, isLoading, isError, refetch } = useGetArtistIndex({
    page,
    ...(genre ? { genre } : {}),
    sort,
    order,
  })

  const response = data as unknown as PaginatedResponse | undefined
  const items = response?.data as Record<string, unknown>[] | undefined

  const handleGenreToggle = useCallback((value: string) => {
    setPage(1)
    setSearchParams((prev) => {
      if (prev.get('genre') === value) {
        prev.delete('genre')
      } else {
        prev.set('genre', value)
      }
      return prev
    })
  }, [setSearchParams])

  const handleClearFilters = useCallback(() => {
    setPage(1)
    setSearchParams((prev) => {
      prev.delete('genre')
      return prev
    })
  }, [setSearchParams])

  const handleSortChange = useCallback((value: string) => {
    setPage(1)
    setSearchParams((prev) => {
      prev.set('sort', value)
      return prev
    })
  }, [setSearchParams])

  return (
    <PageContainer>
      <PageHeader>
        <PageTitle>Artists</PageTitle>
        <HeaderActions>
          <SortSelect options={SORT_OPTIONS} value={sortBy} onChange={handleSortChange} />
        </HeaderActions>
      </PageHeader>

      {/* Genre filter bar */}
      {genreOptions.length > 0 && (
        <FilterArea>
          <FilterBar
            filters={genreOptions}
            selected={genre ? [genre] : []}
            onToggle={handleGenreToggle}
            onClear={handleClearFilters}
          />
        </FilterArea>
      )}

      <ContentArea>
        {isError ? (
          <ErrorContainer>
            <ErrorText>Failed to load artists</ErrorText>
            <Button variant="ghost" size="sm" onClick={() => refetch()}>
              Retry
            </Button>
          </ErrorContainer>
        ) : isLoading ? (
          <ResponsiveGrid>
            {Array.from({ length: 12 }).map((_, i) => (
              <div key={i}>
                <Skeleton style={{ aspectRatio: '1', borderRadius: 'var(--radius-lg)' }} />
                <Skeleton style={{ marginTop: '0.5rem', height: '1rem', width: '75%' }} />
              </div>
            ))}
          </ResponsiveGrid>
        ) : !items?.length ? (
          <EmptyArtistsState />
        ) : (
          <>
            <ResponsiveGrid>
              {items.map((artist) => (
                <ArtistGridItem
                  key={artist.publicId as string}
                  publicId={artist.publicId as string}
                  name={(artist.name as string) ?? 'Unknown'}
                />
              ))}
            </ResponsiveGrid>

            {response && response.lastPage > 1 && (
              <PaginationRow>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  disabled={response.currentPage <= 1}
                >
                  <ChevronLeft size={14} />
                  Previous
                </Button>
                <PageInfo>
                  {response.currentPage} / {response.lastPage}
                </PageInfo>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setPage((p) => p + 1)}
                  disabled={response.currentPage >= response.lastPage}
                >
                  Next
                  <ChevronRight size={14} />
                </Button>
              </PaginationRow>
            )}
          </>
        )}
      </ContentArea>
    </PageContainer>
  )
}

function EmptyArtistsState() {
  return (
    <EmptyContainer>
      <EmptyIcon width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
        <circle cx="12" cy="7" r="4" />
      </EmptyIcon>
      <EmptyTitle>No artists yet</EmptyTitle>
    </EmptyContainer>
  )
}
