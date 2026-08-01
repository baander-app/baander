import styled from 'styled-components'
import { useState, useCallback, useMemo } from 'react'
import { useSearchParams } from 'react-router-dom'
import { LayoutGrid, List, ChevronLeft, ChevronRight } from 'lucide-react'
import type { PaginatedResponse, GetAlbumIndexOrder } from '@/shared/api-client/gen/endpoints'
import { useGetAlbumIndex, useGetGenreIndex } from '@/shared/api-client/gen/endpoints'
import { AlbumGridItem } from '../components/AlbumGridItem'
import { AlbumListItem } from '../components/AlbumListItem'
import { Button } from '@/shared/components/ui/button'
import { Skeleton } from '@/shared/components/ui/skeleton'
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

const Table = styled.table`
  width: 100%;
`

const TableHeader = styled.thead``

const HeaderRow = styled.tr`
  border-bottom: 1px solid var(--color-border);
  text-align: left;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const HeaderCell = styled.th`
  padding: 0.5rem;
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

const EmptySubtitle = styled.p`
  font-size: 0.75rem;
  color: color-mix(in srgb, var(--color-muted-foreground) 70%, transparent);
`

const SkeletonCard = styled.div`
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
`

type ViewMode = 'grid' | 'list'

const SORT_OPTIONS: SortOption[] = [
  { value: 'name_asc', label: 'Name A-Z' },
  { value: 'name_desc', label: 'Name Z-A' },
  { value: 'artist_asc', label: 'Artist A-Z' },
  { value: 'year_desc', label: 'Newest first' },
  { value: 'year_asc', label: 'Oldest first' },
]

const SORT_MAP: Record<string, { sort: string; order: GetAlbumIndexOrder }> = {
  name_asc: { sort: 'title', order: 'asc' },
  name_desc: { sort: 'title', order: 'desc' },
  artist_asc: { sort: 'artist', order: 'asc' },
  year_desc: { sort: 'year', order: 'desc' },
  year_asc: { sort: 'year', order: 'asc' },
}

function getPersistedView(): ViewMode {
  try {
    return (localStorage.getItem('albums-view') as ViewMode) || 'grid'
  } catch {
    return 'grid'
  }
}

export function AlbumsPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const [page, setPage] = useState(1)
  const [viewMode, setViewMode] = useState<ViewMode>(getPersistedView)

  const genre = searchParams.get('genre') ?? null
  const sortBy = searchParams.get('sort') ?? 'name_asc'
  const { sort, order } = SORT_MAP[sortBy] ?? { sort: 'title', order: 'asc' as GetAlbumIndexOrder }

  const { data: genreData } = useGetGenreIndex()
  const genreOptions: FilterOption[] = useMemo(() => {
    const genres = asGenres(genreData)
    return genres
      .map((g) => ({ value: g.slug, label: g.name }))
      .sort((a, b) => a.label.localeCompare(b.label))
  }, [genreData])

  const { data, isLoading, isError, refetch } = useGetAlbumIndex({
    page,
    ...(genre ? { genre } : {}),
    sort,
    order,
  })

  const toggleView = useCallback(() => {
    setViewMode((prev) => {
      const next = prev === 'grid' ? 'list' : 'grid'
      localStorage.setItem('albums-view', next)
      return next
    })
  }, [])

  const response = data as unknown as PaginatedResponse | undefined
  const items = response?.data as any[] | undefined

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
      {/* Page header */}
      <PageHeader>
        <PageTitle>Albums</PageTitle>
        <HeaderActions>
          <SortSelect options={SORT_OPTIONS} value={sortBy} onChange={handleSortChange} />
          <Button
            variant="ghost"
            size="icon-sm"
            onClick={toggleView}
            aria-label={`Switch to ${viewMode === 'grid' ? 'list' : 'grid'} view`}
          >
            {viewMode === 'grid' ? <List size={16} /> : <LayoutGrid size={16} />}
          </Button>
        </HeaderActions>
      </PageHeader>

      {/* Filter bar */}
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

      {/* Content */}
      <ContentArea>
        {isError ? (
          <ErrorContainer>
            <ErrorText>Failed to load albums</ErrorText>
            <Button variant="ghost" size="sm" onClick={() => refetch()}>
              Retry
            </Button>
          </ErrorContainer>
        ) : isLoading ? (
          viewMode === 'grid' ? <AlbumGridSkeleton /> : <AlbumListSkeleton />
        ) : !items?.length ? (
          <EmptyAlbumsState />
        ) : (
          <>
            {viewMode === 'grid' ? (
              <ResponsiveGrid>
                {items.map((album) => (
                  <AlbumGridItem
                    key={album.publicId}
                    publicId={album.publicId}
                    title={album.title ?? 'Unknown'}
                    artistName={album.artistName}
                    imageUrl={album?.coverImage?.url}
                  />
                ))}
              </ResponsiveGrid>
            ) : (
              <Table>
                <TableHeader>
                  <HeaderRow>
                    <HeaderCell style={{ width: '2.5rem' }}></HeaderCell>
                    <HeaderCell>Album</HeaderCell>
                    <HeaderCell>Artist</HeaderCell>
                    <HeaderCell>Year</HeaderCell>
                    <HeaderCell>Genre</HeaderCell>
                  </HeaderRow>
                </TableHeader>
                <tbody>
                  {items.map((album) => (
                    <AlbumListItem
                      key={album.publicId}
                      publicId={album.publicId}
                      title={album.title ?? 'Unknown'}
                      artistName={album.artistName}
                      imageUrl={album?.coverImage?.url}
                    />
                  ))}
                </tbody>
              </Table>
            )}

            {/* Pagination */}
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

function AlbumGridSkeleton() {
  return (
    <ResponsiveGrid>
      {Array.from({ length: 12 }).map((_, i) => (
        <SkeletonCard key={i}>
          <Skeleton style={{ aspectRatio: '1', borderRadius: 'var(--radius-lg)' }} />
          <Skeleton style={{ marginTop: '0.5rem', height: '1rem', width: '75%' }} />
          <Skeleton style={{ marginTop: '0.25rem', height: '0.75rem', width: '50%' }} />
        </SkeletonCard>
      ))}
    </ResponsiveGrid>
  )
}

function AlbumListSkeleton() {
  return (
    <Table>
      <TableHeader>
        <HeaderRow>
          <HeaderCell style={{ width: '2.5rem' }}></HeaderCell>
          <HeaderCell>Album</HeaderCell>
          <HeaderCell>Artist</HeaderCell>
          <HeaderCell>Year</HeaderCell>
          <HeaderCell>Genre</HeaderCell>
        </HeaderRow>
      </TableHeader>
      <tbody>
        {Array.from({ length: 10 }).map((_, i) => (
          <tr key={i}>
            <td style={{ padding: '0.5rem' }}><Skeleton style={{ height: '2.5rem', width: '2.5rem', borderRadius: 'var(--radius-sm)' }} /></td>
            <td style={{ padding: '0.5rem' }}><Skeleton style={{ height: '1rem', width: '12rem' }} /></td>
            <td style={{ padding: '0.5rem' }}><Skeleton style={{ height: '1rem', width: '8rem' }} /></td>
            <td style={{ padding: '0.5rem' }}><Skeleton style={{ height: '1rem', width: '2.5rem' }} /></td>
            <td style={{ padding: '0.5rem' }}><Skeleton style={{ height: '1rem', width: '5rem' }} /></td>
          </tr>
        ))}
      </tbody>
    </Table>
  )
}

function EmptyAlbumsState() {
  return (
    <EmptyContainer>
      <EmptyIcon width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
        <circle cx="12" cy="12" r="10" />
        <circle cx="12" cy="12" r="3" />
      </EmptyIcon>
      <EmptyTitle>No albums yet</EmptyTitle>
      <EmptySubtitle>Add your music library to get started</EmptySubtitle>
    </EmptyContainer>
  )
}
