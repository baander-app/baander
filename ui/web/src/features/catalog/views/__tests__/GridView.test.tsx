import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ThemeProvider as SCTypedThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'

const testTheme = resolveTheme('dark', 'violet')

// --- Mocks ---

const mockRefetch = vi.fn()

function makeAlbumData(count: number, currentPage = 1, lastPage = 2) {
  return {
    data: Array.from({ length: count }, (_, i) => ({
      publicId: `album-${i}`,
      title: `Album ${i}`,
      artists: [{ name: `Artist ${i}`, role: null }],
      coverImage: { url: `/cover/${i}.jpg`, blurhash: 'LEHV6nWB2yk8pyo0adR*.7kCMdnj' },
    })),
    currentPage,
    lastPage,
    perPage: 24,
    total: count * lastPage,
  }
}

type MockConfig = {
  data?: unknown
  isLoading?: boolean
  isError?: boolean
}

let mockConfig: MockConfig = {}

vi.mock('@/shared/api-client/gen/endpoints', () => ({
  useGetAlbumIndex: () => ({
    data: mockConfig.data ?? makeAlbumData(6),
    isLoading: mockConfig.isLoading ?? false,
    isError: mockConfig.isError ?? false,
    error: null,
    refetch: mockRefetch,
  }),
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

vi.mock('react-router-dom', () => ({
  useNavigate: () => vi.fn(),
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

import { GridView } from '../GridView'

describe('GridView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockConfig = {}
  })

  it('renders album cards with title and artist name', () => {
    mockConfig.data = makeAlbumData(6)
    render(<SCTypedThemeProvider theme={testTheme}><GridView /></SCTypedThemeProvider>)
    expect(screen.getByText('Album 0')).toBeInTheDocument()
    expect(screen.getByText('Artist 0')).toBeInTheDocument()
    expect(screen.getByText('Album 5')).toBeInTheDocument()
  })

  it('shows skeleton cards in loading state', () => {
    mockConfig.data = undefined
    mockConfig.isLoading = true
    render(<SCTypedThemeProvider theme={testTheme}><GridView /></SCTypedThemeProvider>)
    const skeletons = screen.getAllByRole('generic').filter(
      (el) => el.getAttribute('data-slot') === 'skeleton',
    )
    expect(skeletons.length).toBeGreaterThan(0)
  })

  it('shows "No albums found" when empty', () => {
    mockConfig.data = makeAlbumData(0, 1, 1)
    render(<SCTypedThemeProvider theme={testTheme}><GridView /></SCTypedThemeProvider>)
    expect(screen.getByText('No albums found')).toBeInTheDocument()
  })

  it('shows retry button in error state', () => {
    mockConfig.data = undefined
    mockConfig.isError = true
    render(<SCTypedThemeProvider theme={testTheme}><GridView /></SCTypedThemeProvider>)
    expect(screen.getByText('Failed to load albums')).toBeInTheDocument()
    expect(screen.getByText('Retry')).toBeInTheDocument()
  })

  it('calls refetch when retry is clicked', async () => {
    const user = userEvent.setup()
    mockConfig.data = undefined
    mockConfig.isError = true
    render(<SCTypedThemeProvider theme={testTheme}><GridView /></SCTypedThemeProvider>)
    await user.click(screen.getByText('Retry'))
    expect(mockRefetch).toHaveBeenCalledOnce()
  })

  it('shows Load more button when there is a next page', () => {
    mockConfig.data = makeAlbumData(6, 1, 3)
    render(<SCTypedThemeProvider theme={testTheme}><GridView /></SCTypedThemeProvider>)
    expect(screen.getByText('Load more')).toBeInTheDocument()
  })

  it('does not show Load more when on last page', () => {
    mockConfig.data = makeAlbumData(3, 2, 2)
    render(<SCTypedThemeProvider theme={testTheme}><GridView /></SCTypedThemeProvider>)
    expect(screen.queryByText('Load more')).not.toBeInTheDocument()
  })

  it('renders a grid with correct aria label', () => {
    mockConfig.data = makeAlbumData(6)
    render(<SCTypedThemeProvider theme={testTheme}><GridView /></SCTypedThemeProvider>)
    expect(screen.getByRole('grid', { name: 'Albums grid' })).toBeInTheDocument()
  })
})
