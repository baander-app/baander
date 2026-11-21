import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { render } from '../../../../../tests/test-utils'
import { DirectorsPage } from '../DirectorsPage'
import { DirectorDetailPage } from '../DirectorDetailPage'

const hooks = vi.hoisted(() => ({
  index: vi.fn(),
  show: vi.fn(),
  movies: vi.fn(),
  retry: vi.fn(),
}))

vi.mock('@/shared/api-client/gen/endpoints', () => ({
  useGetMovieDirectorIndex: hooks.index,
  useGetMovieDirectorShow: hooks.show,
  useGetMovieDirectorMovies: hooks.movies,
}))

const director = {
  publicId: 'director-public-id',
  name: 'Agnès Varda',
  tmdbId: 101,
  profileUrl: null,
  movieCount: 2,
}
const meta = { current_page: 1, last_page: 1, per_page: 50, total: 1 }

function queryResult(data: unknown) {
  return {
    data,
    isPending: false,
    isError: false,
    error: null,
    refetch: hooks.retry,
  }
}

function Location() {
  const location = useLocation()
  return <output aria-label="Current location">{location.pathname}{location.search}</output>
}

function renderDirectors(path = '/movies/directors') {
  return render(directorRoutes(path))
}

function directorRoutes(path: string) {
  return (
    <MemoryRouter initialEntries={[path]}>
      <Location />
      <Routes>
        <Route path="/movies/directors" element={<DirectorsPage />} />
        <Route path="/movies/directors/:publicId" element={<DirectorDetailPage />} />
        <Route path="/movies/:publicId" element={<p>Movie details</p>} />
      </Routes>
    </MemoryRouter>
  )
}

afterEach(cleanup)
beforeEach(() => {
  vi.clearAllMocks()
  hooks.retry.mockResolvedValue(undefined)
  hooks.index.mockReturnValue(queryResult({ data: [director], meta }))
  hooks.show.mockReturnValue(queryResult({ data: director }))
  hooks.movies.mockReturnValue(queryResult({
    data: [{ publicId: 'movie-public-id', title: 'Cléo from 5 to 7', year: 1962 }],
    meta,
  }))
})

describe('director browsing', () => {
  it('opens a real director and their movie through the existing movie card', () => {
    renderDirectors()
    expect(screen.getByRole('heading', { level: 1, name: 'Directors' })).toBeVisible()
    expect(screen.getByText('2 movies')).toBeVisible()
    expect(screen.queryByRole('img')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('link', { name: 'Agnès Varda 2 movies' }))
    expect(screen.getByRole('heading', { level: 1, name: 'Agnès Varda' })).toBeVisible()
    expect(hooks.show).toHaveBeenLastCalledWith('director-public-id')
    expect(hooks.movies).toHaveBeenLastCalledWith('director-public-id', { page: 1, limit: 50 })
    fireEvent.click(screen.getByRole('button', { name: /Cléo from 5 to 7/ }))
    expect(screen.getByText('Movie details')).toBeVisible()
  })

  it('retains the submitted search while paging and resets the page for a new search', () => {
    hooks.index.mockReturnValue(queryResult({
      data: [director],
      meta: { ...meta, current_page: 2, last_page: 3, total: 101 },
    }))
    renderDirectors('/movies/directors?q=Varda&page=2')
    expect(hooks.index).toHaveBeenLastCalledWith({ page: 2, limit: 50, q: 'Varda' })
    fireEvent.click(screen.getByRole('button', { name: 'Next' }))
    expect(hooks.index).toHaveBeenLastCalledWith({ page: 3, limit: 50, q: 'Varda' })
    fireEvent.change(screen.getByRole('textbox', { name: 'Search directors' }), {
      target: { value: 'Kurosawa' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Search' }))
    expect(hooks.index).toHaveBeenLastCalledWith({ page: 1, limit: 50, q: 'Kurosawa' })
    expect(screen.getByRole('status', { name: 'Current location' }))
      .toHaveTextContent('/movies/directors?q=Kurosawa')
  })

  it.each([
    ['/movies/directors', 'Agnès Varda'],
    ['/movies/directors/director-public-id', 'Cléo from 5 to 7'],
  ])('recovers an out-of-range page at %s without losing the search', async (path, name) => {
    hooks.index.mockImplementation(({ page }) => queryResult({
      data: page === 1 ? [director] : [],
      meta: { ...meta, current_page: page },
    }))
    hooks.movies.mockImplementation((_publicId, { page }) => queryResult({
      data: page === 1 ? [{ publicId: 'movie-public-id', title: 'Cléo from 5 to 7' }] : [],
      meta: { ...meta, current_page: page },
    }))

    renderDirectors(`${path}?q=Varda&page=3`)

    expect(await screen.findByText(name)).toBeVisible()
    expect(screen.getByRole('status', { name: 'Current location' }))
      .toHaveTextContent(`${path}?q=Varda&page=1`)
  })

  it('recovers when a live refresh removes the last directory page', async () => {
    let total = 51
    hooks.index.mockImplementation(({ page }) => queryResult({
      data: total === 51 || page === 1 ? [director] : [],
      meta: { ...meta, current_page: page, last_page: total === 51 ? 2 : 1, total },
    }))
    const path = '/movies/directors?q=Varda&page=2'
    const view = renderDirectors(path)
    expect(screen.getByText('Agnès Varda')).toBeVisible()

    total = 50
    view.rerender(directorRoutes(path))

    expect(await screen.findByText('Agnès Varda')).toBeVisible()
    expect(screen.getByRole('status', { name: 'Current location' }))
      .toHaveTextContent('/movies/directors?q=Varda&page=1')
  })

  it('returns to the filtered directory page with its scroll position and selected card', () => {
    hooks.index.mockReturnValue(queryResult({
      data: [director],
      meta: { ...meta, current_page: 2, last_page: 3, total: 101 },
    }))
    const path = '/movies/directors?q=Varda&page=2'
    const view = renderDirectors(path)
    const directory = screen.getByRole('heading', { name: 'Directors' }).closest('section')!
    directory.scrollTop = 240

    fireEvent.click(screen.getByRole('link', { name: 'Agnès Varda 2 movies' }))
    fireEvent.change(screen.getByRole('textbox', { name: 'Search this director’s movies' }), {
      target: { value: 'Cléo' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Search' }))
    fireEvent.click(screen.getByRole('link', { name: 'Back to Directors' }))

    expect(screen.getByRole('status', { name: 'Current location' }))
      .toHaveTextContent('/movies/directors?q=Varda&page=2')
    expect(screen.getByRole('heading', { name: 'Directors' }).closest('section')?.scrollTop)
      .toBe(240)
    expect(screen.getByRole('link', { name: 'Agnès Varda 2 movies' })).toHaveFocus()

    const returnedDirectory = screen.getByRole('heading', { name: 'Directors' }).closest('section')!
    returnedDirectory.scrollTop = 320
    const searchInput = screen.getByRole('textbox', { name: 'Search directors' })
    searchInput.focus()
    hooks.index.mockReturnValue(queryResult({
      data: [{ ...director, movieCount: 3 }],
      meta: { ...meta, current_page: 2, last_page: 3, total: 101 },
    }))
    view.rerender(directorRoutes(path))

    expect(returnedDirectory.scrollTop).toBe(320)
    expect(searchInput).toHaveFocus()
  })

  it('shows loading without declaring an empty directory', () => {
    hooks.index.mockReturnValue({ ...queryResult(undefined), isPending: true })
    renderDirectors()
    expect(screen.getByRole('status', { name: 'Loading results' })).toBeVisible()
    expect(screen.queryByText('No directors yet.')).not.toBeInTheDocument()
  })

  it('restores the returned browse position after retrying a failed cached refresh', () => {
    const path = '/movies/directors?q=Varda'
    const cachedResult = queryResult({ data: [director], meta })
    hooks.index.mockReturnValue(cachedResult)
    const view = renderDirectors(path)
    const directory = screen.getByRole('heading', { name: 'Directors' }).closest('section')!
    directory.scrollTop = 240
    fireEvent.click(screen.getByRole('link', { name: 'Agnès Varda 2 movies' }))
    hooks.index.mockReturnValue({ ...cachedResult, isError: true })
    fireEvent.click(screen.getByRole('link', { name: 'Back to Directors' }))
    expect(screen.getByRole('alert')).toHaveTextContent('Unable to load results.')

    fireEvent.click(screen.getByRole('button', { name: 'Retry' }))
    hooks.index.mockReturnValue(cachedResult)
    view.rerender(directorRoutes(path))

    expect(screen.getByRole('heading', { name: 'Directors' }).closest('section')?.scrollTop)
      .toBe(240)
    expect(screen.getByRole('link', { name: 'Agnès Varda 2 movies' })).toHaveFocus()
  })

  it.each([
    ['/movies/directors', 'No directors yet.'],
    ['/movies/directors?q=unknown', 'No directors found.'],
  ])('shows an honest empty result for %s', (path, message) => {
    hooks.index.mockReturnValue(queryResult({ data: [], meta: { ...meta, total: 0 } }))
    renderDirectors(path)
    expect(screen.getByText(message)).toBeVisible()
  })

  it('offers a retry when the directory request fails', () => {
    hooks.index.mockReturnValue({ ...queryResult(undefined), isError: true })
    renderDirectors()
    expect(screen.getByRole('alert')).toHaveTextContent('Unable to load results.')
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }))
    expect(hooks.retry).toHaveBeenCalledOnce()
  })

  it('does not present an inaccessible director as an empty movie list', () => {
    const missing = { ...queryResult(undefined), isError: true, error: { response: { status: 404 } } }
    hooks.show.mockReturnValue(missing)
    hooks.movies.mockReturnValue(missing)
    renderDirectors('/movies/directors/missing')
    expect(screen.getByRole('heading', { name: 'Director not found' })).toBeVisible()
    expect(screen.queryByText('No movies available.')).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Back to Directors' }))
      .toHaveAttribute('href', '/movies/directors')
  })

  it('keeps movie search and pagination scoped to the selected director', () => {
    hooks.movies.mockReturnValue(queryResult({
      data: [{ publicId: 'movie-public-id', title: 'Cléo from 5 to 7' }],
      meta: { ...meta, last_page: 2, total: 51 },
    }))
    renderDirectors('/movies/directors/director-public-id?q=Cléo')
    fireEvent.click(screen.getByRole('button', { name: 'Next' }))
    expect(hooks.movies).toHaveBeenLastCalledWith('director-public-id', {
      page: 2,
      limit: 50,
      q: 'Cléo',
    })
    expect(hooks.index).not.toHaveBeenCalled()
  })

  it('retries failed director and movie requests together', () => {
    hooks.movies.mockReturnValue({ ...queryResult(undefined), isError: true })
    renderDirectors('/movies/directors/director-public-id')
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }))
    expect(hooks.retry).toHaveBeenCalledTimes(2)
  })
})
