import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ThemeProvider as SCTypedThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'

const testTheme = resolveTheme('dark', 'violet')

const mockRefetch = vi.fn()

function makeAlbum(publicId: string, year: number | null) {
  return {
    uuid: `uuid-${publicId}`,
    publicId,
    title: `Album ${publicId}`,
    type: 'album',
    year,
    label: null,
    barcode: null,
    country: null,
    createdAt: '2024-01-01T00:00:00Z',
    coverImage: null,
    artists: [],
  }
}

type MockConfig = {
  data?: unknown
  isLoading?: boolean
  error?: unknown
}

let mockConfig: MockConfig = {}

vi.mock('@/features/catalog/hooks/use-timeline-view-model', () => ({
  useTimelineViewModel: () => {
    const response = mockConfig.data as Record<string, unknown> | undefined
    const rawItems = response?.data as unknown[] | undefined

    // Simple grouping for the mock
    const albums = (rawItems ?? []).map((item) => item as Record<string, unknown>)
    const decades: { label: string; years: { label: string; albums: Record<string, unknown>[] }[] }[] = []

    for (const album of albums) {
      const year = album.year as number | null
      const label = year != null ? `${Math.floor(year / 10) * 10}s` : 'Unknown'
      let decade = decades.find((d) => d.label === label)
      if (!decade) {
        decade = { label, years: [] }
        decades.push(decade)
      }
      const yearLabel = year != null ? String(year) : 'Unknown'
      let yearGroup = decade.years.find((y) => y.label === yearLabel)
      if (!yearGroup) {
        yearGroup = { label: yearLabel, albums: [] }
        decade.years.push(yearGroup)
      }
      yearGroup.albums.push(album)
    }

    return {
      decades,
      isLoading: mockConfig.isLoading ?? false,
      error: mockConfig.error ?? null,
      refetch: mockRefetch,
    }
  },
}))

vi.mock('react-router-dom', () => ({
  useNavigate: () => vi.fn(),
}))

vi.mock('@/features/catalog/stores/selection-store', () => ({
  useSelectionStore: (selector: (s: Record<string, unknown>) => unknown) =>
    selector({
      selectedId: null,
      selectedType: null,
      select: vi.fn(),
      clear: vi.fn(),
    }),
}))

vi.mock('@/shared/hooks/use-image-blob', () => ({
  useImageBlob: () => ({ src: '/blob/cover.jpg', isLoading: false }),
}))

vi.mock('@/shared/utils/blurhash', () => ({
  extractDominantColor: () => '#4a7c59',
}))

vi.mock('@/features/player/stores/player-store', () => ({
  usePlayerStore: (selector: (s: Record<string, unknown>) => unknown) =>
    selector({
      playTrack: vi.fn(),
      insertAfterCurrent: vi.fn(),
      addToQueue: vi.fn(),
    }),
}))

vi.mock('@/features/playlist/components/AddToPlaylistDialog', () => ({
  AddToPlaylistDialog: () => null,
}))

import { TimelineView } from '../TimelineView'

describe('TimelineView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockConfig = {}
  })

  it('renders decades with year labels', () => {
    mockConfig.data = {
      data: [makeAlbum('a1', 2024), makeAlbum('a2', 2015)],
      currentPage: 1,
      lastPage: 1,
      perPage: 100,
      total: 2,
    }

    render(<SCTypedThemeProvider theme={testTheme}><TimelineView /></SCTypedThemeProvider>)
    expect(screen.getByText('2020s')).toBeInTheDocument()
    expect(screen.getByText('2010s')).toBeInTheDocument()
    expect(screen.getByText('2024')).toBeInTheDocument()
    expect(screen.getByText('2015')).toBeInTheDocument()
  })

  it('shows loading skeleton', () => {
    mockConfig.isLoading = true
    mockConfig.data = undefined

    render(<SCTypedThemeProvider theme={testTheme}><TimelineView /></SCTypedThemeProvider>)
    const skeletons = screen.getAllByRole('generic').filter(
      (el) => el.getAttribute('data-slot') === 'skeleton',
    )
    expect(skeletons.length).toBeGreaterThan(0)
  })

  it('shows error state with retry', () => {
    mockConfig.data = undefined
    mockConfig.error = new Error('fail')

    render(<SCTypedThemeProvider theme={testTheme}><TimelineView /></SCTypedThemeProvider>)
    expect(screen.getByText('Failed to load albums')).toBeInTheDocument()
    expect(screen.getByText('Retry')).toBeInTheDocument()
  })

  it('calls refetch on retry click', async () => {
    const user = userEvent.setup()
    mockConfig.data = undefined
    mockConfig.error = new Error('fail')

    render(<SCTypedThemeProvider theme={testTheme}><TimelineView /></SCTypedThemeProvider>)
    await user.click(screen.getByText('Retry'))
    expect(mockRefetch).toHaveBeenCalledOnce()
  })

  it('shows empty state when no albums with year', () => {
    mockConfig.data = {
      data: [],
      currentPage: 1,
      lastPage: 1,
      perPage: 100,
      total: 0,
    }

    render(<SCTypedThemeProvider theme={testTheme}><TimelineView /></SCTypedThemeProvider>)
    expect(screen.getByText('No albums with year information')).toBeInTheDocument()
  })
})
