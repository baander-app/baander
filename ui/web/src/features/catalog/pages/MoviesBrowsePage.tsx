import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { LayoutGrid, List, ChevronLeft, ChevronRight } from 'lucide-react'
import styled from 'styled-components'
import type { PaginatedResponse } from '@/shared/api-client/gen/endpoints'
import { useGetMovieIndex } from '@/shared/api-client/gen/endpoints'
import { MovieGridItem } from '../components/MovieGridItem'
import { MovieListItem } from '../components/MovieListItem'
import { Button } from '@/shared/components/ui/button'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { SortSelect, type SortOption } from '@/shared/components/ui/sort-select'

const PageContainer = styled.div`
  display: flex;
  height: 100%;
  flex-direction: column;
`

const TopBar = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1rem 1.5rem;
`

const Title = styled.h1`
  font-size: 1.125rem;
  font-weight: 600;
  letter-spacing: -0.025em;
`

const TopActions = styled.div`
  display: flex;
  align-items: center;
  gap: 0.25rem;
`

const ContentArea = styled.div`
  flex: 1;
  overflow-y: auto;
  padding: 0 1.5rem 1.5rem;
`

const MovieGrid = styled.div`
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 1rem;

  @media (min-width: 640px) {
    grid-template-columns: repeat(3, 1fr);
  }
  @media (min-width: 768px) {
    grid-template-columns: repeat(4, 1fr);
  }
  @media (min-width: 1024px) {
    grid-template-columns: repeat(5, 1fr);
  }
  @media (min-width: 1280px) {
    grid-template-columns: repeat(6, 1fr);
  }
  @media (min-width: 1536px) {
    grid-template-columns: repeat(7, 1fr);
  }
`

const StyledTable = styled.table`
  width: 100%;
`

const TableHead = styled.thead``

const HeadRow = styled.tr`
  border-bottom: 1px solid var(--color-border);
  text-align: left;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const HeadCell = styled.th`
  padding: 0.5rem;

  &:first-child {
    width: 2.5rem;
  }
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
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const EmptySubText = styled.p`
  font-size: 0.75rem;
  color: color-mix(in srgb, var(--color-muted-foreground) 70%, transparent);
`

const SkeletonCell = styled.td`
  padding: 0.5rem;
`

type ViewMode = 'grid' | 'list'

const SORT_OPTIONS: SortOption[] = [
  { value: 'title_asc', label: 'Title (A-Z)' },
  { value: 'title_desc', label: 'Title (Z-A)' },
  { value: 'year_desc', label: 'Year (Newest)' },
  { value: 'year_asc', label: 'Year (Oldest)' },
  { value: 'rating_desc', label: 'Rating (Highest)' },
]

function getPersistedView(): ViewMode {
  try {
    return (localStorage.getItem('movies-view') as ViewMode) || 'grid'
  } catch {
    return 'grid'
  }
}

export function MoviesBrowsePage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const [page, setPage] = useState(1)
  const [viewMode, setViewMode] = useState<ViewMode>(getPersistedView)

  const sortBy = searchParams.get('sort') ?? 'title_asc'

  const limit = 24
  const { data, isLoading, isError, refetch } = useGetMovieIndex({
    page,
    limit,
  })

  const response = data as unknown as PaginatedResponse | undefined
  const items = response?.data as any[] | undefined

  function toggleView() {
    setViewMode((prev) => {
      const next = prev === 'grid' ? 'list' : 'grid'
      try { localStorage.setItem('movies-view', next) } catch {}
      return next
    })
  }

  function handleSortChange(value: string) {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev)
      next.set('sort', value)
      return next
    })
    setPage(1)
  }

  return (
    <PageContainer>
      <TopBar>
        <Title>Movies</Title>
        <TopActions>
          <SortSelect options={SORT_OPTIONS} value={sortBy} onChange={handleSortChange} />
          <Button
            variant="ghost"
            size="icon-sm"
            onClick={toggleView}
            aria-label={`Switch to ${viewMode === 'grid' ? 'list' : 'grid'} view`}
          >
            {viewMode === 'grid' ? <List size={16} /> : <LayoutGrid size={16} />}
          </Button>
        </TopActions>
      </TopBar>

      <ContentArea>
        {isError ? (
          <CenterMessage>
            <ErrorText>Failed to load movies</ErrorText>
            <Button variant="ghost" size="sm" onClick={() => refetch()}>Retry</Button>
          </CenterMessage>
        ) : isLoading ? (
          viewMode === 'grid' ? <MovieGridSkeleton /> : <MovieListSkeleton />
        ) : !items?.length ? (
          <EmptyMoviesState />
        ) : (
          <>
            {viewMode === 'grid' ? (
              <MovieGrid>
                {items.map((movie: any) => (
                  <MovieGridItem key={movie.publicId} movie={movie} />
                ))}
              </MovieGrid>
            ) : (
              <StyledTable>
                <TableHead>
                  <HeadRow>
                    <HeadCell></HeadCell>
                    <HeadCell>Title</HeadCell>
                    <HeadCell>Year</HeadCell>
                    <HeadCell>Rating</HeadCell>
                  </HeadRow>
                </TableHead>
                <tbody>
                  {items.map((movie: any) => (
                    <MovieListItem key={movie.publicId} movie={movie} />
                  ))}
                </tbody>
              </StyledTable>
            )}

            {response && response.lastPage > 1 && (
              <PaginationRow>
                <Button variant="ghost" size="sm" onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={response.currentPage <= 1}>
                  <ChevronLeft size={14} /> Previous
                </Button>
                <PageInfo>{response.currentPage} / {response.lastPage}</PageInfo>
                <Button variant="ghost" size="sm" onClick={() => setPage((p) => p + 1)} disabled={response.currentPage >= response.lastPage}>
                  Next <ChevronRight size={14} />
                </Button>
              </PaginationRow>
            )}
          </>
        )}
      </ContentArea>
    </PageContainer>
  )
}

function MovieGridSkeleton() {
  return (
    <MovieGrid>
      {Array.from({ length: 14 }).map((_, i) => (
        <div key={i}>
          <Skeleton style={{ aspectRatio: '2/3', borderRadius: '0.5rem' }} />
          <Skeleton style={{ marginTop: '0.5rem', height: '1rem', width: '75%' }} />
          <Skeleton style={{ marginTop: '0.25rem', height: '0.75rem', width: '50%' }} />
        </div>
      ))}
    </MovieGrid>
  )
}

function MovieListSkeleton() {
  return (
    <StyledTable>
      <TableHead>
        <HeadRow>
          <HeadCell></HeadCell>
          <HeadCell>Title</HeadCell>
          <HeadCell>Year</HeadCell>
          <HeadCell>Rating</HeadCell>
        </HeadRow>
      </TableHead>
      <tbody>
        {Array.from({ length: 10 }).map((_, i) => (
          <tr key={i}>
            <SkeletonCell><Skeleton style={{ height: '4rem', width: '2.75rem', borderRadius: '0.25rem' }} /></SkeletonCell>
            <SkeletonCell><Skeleton style={{ height: '1rem', width: '12rem' }} /></SkeletonCell>
            <SkeletonCell><Skeleton style={{ height: '1rem', width: '2.5rem' }} /></SkeletonCell>
            <SkeletonCell><Skeleton style={{ height: '1rem', width: '3rem' }} /></SkeletonCell>
          </tr>
        ))}
      </tbody>
    </StyledTable>
  )
}

function EmptyMoviesState() {
  return (
    <CenterMessage>
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" style={{ color: 'color-mix(in srgb, var(--color-muted-foreground) 30%, transparent)' }}>
        <rect x="2" y="2" width="20" height="20" rx="2" ry="2" />
        <line x1="7" y1="2" x2="7" y2="22" />
        <line x1="17" y1="2" x2="17" y2="22" />
        <line x1="2" y1="12" x2="22" y2="12" />
      </svg>
      <EmptyText>No movies yet</EmptyText>
      <EmptySubText>Add a movie library and scan to get started</EmptySubText>
    </CenterMessage>
  )
}
