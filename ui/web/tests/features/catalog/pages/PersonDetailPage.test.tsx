import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { WorkPeople } from '@/features/catalog/components/WorkPeople'
import { PersonDetailPage } from '@/features/catalog/pages/PersonDetailPage'
import { TVSeriesDetailPage } from '@/features/tv/pages/TVDetailPage'
import { ThemeProvider } from '@/shared/theme'

const { read } = vi.hoisted(() => ({ read: vi.fn() }))
vi.mock(import('@/shared/api-client/axios-instance'), async (importOriginal) => ({
  ...await importOriginal(),
  customInstance: read,
}))
const meta = { current_page: 1, last_page: 1, per_page: 50, total: 1 }
const profile = {
  publicId: 'person', name: 'Agnès', profileUrl: null, biography: 'A filmmaker and musician.',
  aliases: ['Stage name'], birthDate: '1928', deathDate: '2019', birthplace: null,
  sex: null, gender: null, age: null, isDeceased: true,
  movieCount: 1, songCount: 1, albumCount: 0, seriesCount: 1, episodeCount: 0,
}
const role = { role: 'director', job: 'Director', characterName: null, creditedName: null }
const clients: QueryClient[] = []
afterEach(() => {
  cleanup()
  clients.splice(0).forEach(client => client.clear())
  read.mockReset()
  vi.restoreAllMocks()
})
function mount(path: string, pattern: string, element: React.ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  clients.push(client)
  render(
    <ThemeProvider>
      <QueryClientProvider client={client}>
        <MemoryRouter initialEntries={[path]}>
          <Routes><Route path={pattern} element={element} /></Routes>
        </MemoryRouter>
      </QueryClientProvider>
    </ThemeProvider>,
  )
}

describe('Person profile', () => {
  it('groups roles on a linked work and opens music and TV credit categories', async () => {
    read.mockImplementation(async (url: string) => {
      if (!url.includes('/credits')) return { data: profile }
      const kind = new URL(url, 'https://baander.app').searchParams.get('kind')
      const title = kind === 'song' ? 'A song' : kind === 'series' ? 'A series' : 'A movie'
      return { data: [{ publicId: 'work', kind, title, roles: [role, { ...role, role: 'actor', job: 'Actor', characterName: 'Guest' }] }], meta }
    })
    mount('/people/person', '/people/:publicId', <PersonDetailPage />)
    expect(await screen.findByRole('heading', { name: 'Agnès' })).toBeInTheDocument()
    expect(await screen.findByRole('link', { name: 'A movie' })).toHaveAttribute('href', '/movies/work')
    expect(screen.getByText('Director · Actor as Guest')).toBeInTheDocument()
    expect(screen.queryByText('Sex')).not.toBeInTheDocument()
    expect(screen.queryByText('Age at death')).not.toBeInTheDocument()
    expect(screen.getByText('1928')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Songs (1)' }))
    expect(await screen.findByRole('link', { name: 'A song' })).toHaveAttribute('href', '/music/songs/work')
    fireEvent.click(screen.getByRole('button', { name: 'TV series (1)' }))
    expect(await screen.findByRole('link', { name: 'A series' })).toHaveAttribute('href', '/tv/series/work')
  })

  it('hides previously loaded profile data when access is denied on refresh', async () => {
    let denied = false
    read.mockImplementation(async (url: string) => {
      if (denied) throw { response: { status: 404 } }
      if (url.includes('/credits')) return { data: [], meta: { ...meta, total: 0 } }
      return { data: profile }
    })
    mount('/people/person', '/people/:publicId', <PersonDetailPage />)
    expect(await screen.findByRole('heading', { name: 'Agnès' })).toBeInTheDocument()
    denied = true
    await clients[0].invalidateQueries()
    expect(await screen.findByText('This person is unavailable.')).toBeInTheDocument()
    expect(screen.queryByText('A filmmaker and musician.')).not.toBeInTheDocument()
  })


  it('links each screen credit to the shared profile with its character and credited name', async () => {
    read.mockResolvedValue({
      data: [{
        publicId: 'credit', personPublicId: 'person', name: 'Agnès', profileUrl: null,
        role: { ...role, role: 'actor', job: 'Actor', characterName: 'Guest', creditedName: 'Stage name' },
      }],
      meta,
    })
    mount('/movies/movie', '/movies/:publicId', <WorkPeople kind="movie" publicId="movie" />)
    expect(await screen.findByRole('link', { name: /Agnès/ })).toHaveAttribute('href', '/people/person')
    expect(screen.getByText('Actor as Guest (credited as Stage name)')).toBeInTheDocument()
  })


  it('retains multiple roles and identical rows for one person without repeated React keys', async () => {
    const warnings = vi.spyOn(console, 'error').mockImplementation(() => undefined)
    const base = { publicId: 'person', personPublicId: 'person', name: 'Agnès', profileUrl: null }
    const director = { ...base, role }
    const actor = { ...base, role: { ...role, role: 'actor', job: 'Actor', characterName: 'Guest' } }
    read.mockResolvedValue({ data: [director, actor, actor], meta: { ...meta, total: 3 } })
    mount('/movies/movie', '/movies/:publicId', <WorkPeople kind="movie" publicId="movie" />)
    expect(await screen.findAllByRole('link', { name: /Agnès/ })).toHaveLength(3)
    expect(screen.getAllByText('Actor as Guest')).toHaveLength(2)
    read.mockResolvedValue({ data: [actor, director, actor], meta: { ...meta, total: 3 } })
    await act(async () => { await clients[0].invalidateQueries() })
    expect(screen.getAllByRole('link', { name: /Agnès/ })).toHaveLength(3)
    expect(warnings).not.toHaveBeenCalled()
    warnings.mockRestore()
  })

  it('shows an extra-only series with no imported credits or available episodes', async () => {
    read.mockImplementation(async (url: string) => {
      if (url.includes('/credits') || url.includes('/episodes')) return { data: [], meta: { ...meta, total: 0 } }
      return { data: {
        publicId: 'series', libraryPublicId: 'library', name: 'Registered show', overview: null,
        posterUrl: null, metadataSource: null, seasons: [], unseasonedCount: 0,
        extras: [{ placementPublicId: 'trailer-placement', publicId: 'trailer-video', videoPublicId: 'trailer-video', kind: 'trailer' }],
      } }
    })
    mount('/tv/series/series', '/tv/series/:publicId', <TVSeriesDetailPage />)
    expect(await screen.findByRole('heading', { name: 'Registered show' })).toBeInTheDocument()
    expect(await screen.findByText('No credits available.')).toBeInTheDocument()
    expect(await screen.findByText('No available episodes.')).toBeInTheDocument()
  })
})
