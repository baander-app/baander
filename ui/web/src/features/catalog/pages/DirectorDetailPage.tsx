import { Link, Navigate, useLocation, useParams, useSearchParams } from 'react-router-dom'
import {
  useGetMovieDirectorMovies,
  useGetMovieDirectorShow,
} from '@/shared/api-client/gen/endpoints'
import { PaginatedGrid } from '../components/PaginatedGrid'
import { MovieGridItem } from '../components/MovieGridItem'
import { asMovies } from '../utils/api-adapters'
import {
  DirectorBrowseEmpty,
  DirectorBrowseError,
  DirectorBrowseLayout,
  DirectorBrowseLoading,
  type DirectorNavigationState,
} from '../components/DirectorBrowseLayout'

export function DirectorDetailPage() {
  const { publicId = '' } = useParams<{ publicId: string }>()
  const location = useLocation()
  const browsePosition = (location.state as DirectorNavigationState | null)?.directorBrowse
  const [searchParams, setSearchParams] = useSearchParams()
  const requestedPage = Number(searchParams.get('page') ?? 1)
  const page = Number.isSafeInteger(requestedPage) && requestedPage > 0 ? requestedPage : 1
  const query = searchParams.get('q') ?? ''
  const director = useGetMovieDirectorShow(publicId)
  const movies = useGetMovieDirectorMovies(publicId, {
    page,
    limit: 50,
    ...(query ? { q: query } : {}),
  })
  const notFound = [director.error, movies.error].some(error => (
    (error as { response?: { status?: number } } | null)?.response?.status === 404
  ))
  const setPage = (next: number) => {
    setSearchParams(previous => {
      const params = new URLSearchParams(previous)
      params.set('page', String(next))
      return params
    }, { state: location.state })
  }
  const search = (value: string) => {
    setSearchParams(previous => {
      const params = new URLSearchParams(previous)
      params.delete('page')
      if (value) {
        params.set('q', value)
      } else {
        params.delete('q')
      }
      return params
    }, { state: location.state })
  }
  const name = director.data?.data.name
  const count = director.data?.data.movieCount

  if (!movies.isError && movies.data?.meta.current_page === page && page > movies.data.meta.last_page) {
    const params = new URLSearchParams(searchParams)
    params.set('page', String(movies.data.meta.last_page))
    return <Navigate to={{ search: params.toString() }} replace state={location.state} />
  }

  return (
    <DirectorBrowseLayout
      title={notFound ? 'Director not found' : name ?? 'Director'}
      description={count === undefined ? undefined : `${count} ${count === 1 ? 'movie' : 'movies'}`}
      navigation={(
        <Link
          to={{ pathname: '/movies/directors', search: browsePosition?.search ?? '' }}
          state={browsePosition ? { directorBrowse: browsePosition } : undefined}
        >
          Back to Directors
        </Link>
      )}
      query={query}
      searchLabel="Search this director’s movies"
      onSearch={search}
    >
      {notFound ? (
        <DirectorBrowseEmpty>This director is unavailable.</DirectorBrowseEmpty>
      ) : director.isError || movies.isError ? (
        <DirectorBrowseError retry={() => Promise.all([director.refetch(), movies.refetch()])} />
      ) : director.isPending || movies.isPending ? (
        <DirectorBrowseLoading />
      ) : !movies.data?.data.length ? (
        <DirectorBrowseEmpty>{query ? 'No movies found.' : 'No movies available.'}</DirectorBrowseEmpty>
      ) : (
        <PaginatedGrid
          pagination={{
            currentPage: movies.data.meta.current_page,
            lastPage: movies.data.meta.last_page,
            total: movies.data.meta.total,
          }}
          onPageChange={setPage}
        >
          {asMovies(movies.data).map(movie => (
            <MovieGridItem key={movie.publicId} movie={movie} />
          ))}
        </PaginatedGrid>
      )}
    </DirectorBrowseLayout>
  )
}
