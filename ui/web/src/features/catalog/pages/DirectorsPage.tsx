import { useLayoutEffect, useRef, type MouseEvent } from 'react'
import { Navigate, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { useGetMovieDirectorIndex, type DirectorResource } from '@/shared/api-client/gen/endpoints'
import { DirectorGridItem } from '../components/DirectorGridItem'
import { PaginatedGrid } from '../components/PaginatedGrid'
import {
  DirectorBrowseEmpty,
  DirectorBrowseError,
  DirectorBrowseLayout,
  DirectorBrowseLoading,
  type DirectorNavigationState,
} from '../components/DirectorBrowseLayout'

export function DirectorsPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const location = useLocation()
  const navigate = useNavigate()
  const container = useRef<HTMLElement>(null)
  const restoredLocation = useRef<string | null>(null)
  const returnPosition = (location.state as DirectorNavigationState | null)?.directorBrowse
  const requestedPage = Number(searchParams.get('page') ?? 1)
  const page = Number.isSafeInteger(requestedPage) && requestedPage > 0 ? requestedPage : 1
  const query = searchParams.get('q') ?? ''
  const { data, isPending, isError, refetch } = useGetMovieDirectorIndex({
    page,
    limit: 50,
    ...(query ? { q: query } : {}),
  })
  const directors: DirectorResource[] = data?.data ?? []
  const setPage = (next: number) => {
    setSearchParams(previous => {
      const params = new URLSearchParams(previous)
      params.set('page', String(next))
      return params
    })
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
    })
  }
  const openDirector = (event: MouseEvent<HTMLAnchorElement>, publicId: string) => {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return
    }

    event.preventDefault()
    navigate(`/movies/directors/${publicId}`, {
      state: {
        directorBrowse: {
          search: location.search,
          scrollTop: container.current?.scrollTop ?? 0,
          selectedId: publicId,
        },
      } satisfies DirectorNavigationState,
    })
  }

  useLayoutEffect(() => {
    if (!returnPosition || !data || isPending || isError || restoredLocation.current === location.key) {
      return
    }
    const element = container.current
    if (!element) {
      return
    }

    document.getElementById(`director-card-${returnPosition.selectedId}`)?.focus({ preventScroll: true })
    element.scrollTop = returnPosition.scrollTop
    restoredLocation.current = location.key
  }, [data, isPending, isError, location.key, returnPosition])

  if (!isError && data?.meta.current_page === page && page > data.meta.last_page) {
    const params = new URLSearchParams(searchParams)
    params.set('page', String(data.meta.last_page))
    return <Navigate to={{ search: params.toString() }} replace state={location.state} />
  }

  return (
    <DirectorBrowseLayout
      title="Directors"
      query={query}
      searchLabel="Search directors"
      onSearch={search}
      containerRef={container}
    >
      {isError ? (
        <DirectorBrowseError retry={refetch} />
      ) : isPending ? (
        <DirectorBrowseLoading />
      ) : !data || directors.length === 0 ? (
        <DirectorBrowseEmpty>{query ? 'No directors found.' : 'No directors yet.'}</DirectorBrowseEmpty>
      ) : (
        <PaginatedGrid
          pagination={{
            currentPage: data.meta.current_page,
            lastPage: data.meta.last_page,
            total: data.meta.total,
          }}
          onPageChange={setPage}
        >
          {directors.map(director => (
            <DirectorGridItem
              key={director.publicId}
              director={director}
              onOpen={event => openDirector(event, director.publicId)}
            />
          ))}
        </PaginatedGrid>
      )}
    </DirectorBrowseLayout>
  )
}
