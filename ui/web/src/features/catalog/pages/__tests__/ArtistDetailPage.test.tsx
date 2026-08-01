import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ThemeProvider as SCTypedThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'

const testTheme = resolveTheme('dark', 'violet')

// --- Mocks ---

const mockNavigate = vi.fn()
const mockSetSelectedItem = vi.fn()

const mockArtistData = {
  name: 'Radiohead',
  publicId: 'artist-1',
  type: 'group',
  country: 'UK',
}

const makeAlbumsData = (count: number) => ({
  data: Array.from({ length: count }, (_, i) => ({
    publicId: `album-${i}`,
    title: `Album ${i}`,
    artists: [{ name: 'Radiohead', role: null }],
    coverImage: { url: `/cover/${i}.jpg`, blurhash: null },
  })),
  currentPage: 1,
  lastPage: 1,
  perPage: 24,
  total: count,
})

type MockConfig = {
  artistData?: unknown
  albumsData?: unknown
  artistLoading?: boolean
  albumsLoading?: boolean
  isError?: boolean
}

let mockConfig: MockConfig = {}

vi.mock('@/shared/api-client/gen/endpoints', () => ({
  useGetArtistShow: () => ({
    data: mockConfig.artistData ?? mockArtistData,
    isLoading: mockConfig.artistLoading ?? false,
    isError: mockConfig.isError ?? false,
    error: null,
  }),
  useGetAlbumIndex: () => ({
    data: mockConfig.albumsData ?? makeAlbumsData(3),
    isLoading: mockConfig.albumsLoading ?? false,
    isError: false,
    error: null,
  }),
}))

vi.mock('react-router-dom', () => ({
  useParams: () => ({ publicId: 'artist-1' }),
  useNavigate: () => mockNavigate,
}))

vi.mock('@/features/layout/stores/context-panel-store', () => ({
  useContextPanelStore: (selector: (s: Record<string, unknown>) => unknown) =>
    selector({
      setSelectedItem: mockSetSelectedItem,
    }),
}))

vi.mock('@/features/catalog/hooks/use-artist-detail', () => {
  return {
    useArtistDetail: () => {
      const artist = mockConfig.artistLoading ? undefined : mockConfig.artistData ?? mockArtistData
      const albumsRaw = (mockConfig.albumsData ?? makeAlbumsData(3))
      const albums = mockConfig.albumsLoading
        ? undefined
        : (albumsRaw as Record<string, unknown>).data as Array<Record<string, unknown>>
      // Shape as AlbumSummary objects
      const albumSummaries = albums?.map((a) => ({
        uuid: '',
        publicId: a.publicId as string,
        title: a.title as string,
        type: 'album',
        year: null,
        label: null,
        barcode: null,
        country: null,
        createdAt: '',
        coverImage: (a.coverImage as Record<string, unknown>) ?? null,
        artists: (a.artists as Array<Record<string, unknown>>) ?? [],
      }))
      const total = (albumsRaw as Record<string, unknown>).total as number
      return {
        artist,
        albums: albumSummaries,
        albumCount: total ?? 0,
        isLoading: mockConfig.artistLoading || mockConfig.albumsLoading || false,
        error: mockConfig.isError ? new Error('fail') : null,
        loadMore: vi.fn(),
        hasNextPage: false,
      }
    },
  }
})

vi.mock('@/shared/hooks/use-image-blob', () => ({
  useImageBlob: () => ({ src: '/blob/cover.jpg', isLoading: false }),
}))

vi.mock('@/shared/utils/blurhash', () => ({
  extractDominantColor: () => '#4a7c59',
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

vi.mock('@/features/player/stores/player-store', () => ({
  usePlayerStore: (selector: (s: Record<string, unknown>) => unknown) =>
    selector({
      playTrack: vi.fn(),
      insertAfterCurrent: vi.fn(),
      addToQueue: vi.fn(),
    }),
}))

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: {
    get: vi.fn(),
  },
}))

vi.mock('@/features/playlist/components/AddToPlaylistDialog', () => ({
  AddToPlaylistDialog: () => null,
}))

import { ArtistDetailPage } from '../ArtistDetailPage'

describe('ArtistDetailPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockConfig = {}
  })

  it('renders artist name and album count in header', () => {
    render(<SCTypedThemeProvider theme={testTheme}><ArtistDetailPage /></SCTypedThemeProvider>)
    expect(screen.getByRole('heading', { name: 'Radiohead' })).toBeInTheDocument()
    expect(screen.getByText('3 albums')).toBeInTheDocument()
  })

  it('renders album grid cards', () => {
    render(<SCTypedThemeProvider theme={testTheme}><ArtistDetailPage /></SCTypedThemeProvider>)
    expect(screen.getByText('Album 0')).toBeInTheDocument()
    expect(screen.getByText('Album 1')).toBeInTheDocument()
    expect(screen.getByText('Album 2')).toBeInTheDocument()
  })

  it('shows loading skeletons while loading', () => {
    mockConfig.artistLoading = true
    mockConfig.albumsLoading = true
    render(<SCTypedThemeProvider theme={testTheme}><ArtistDetailPage /></SCTypedThemeProvider>)
    const skeletons = screen.getAllByRole('generic').filter(
      (el) => el.getAttribute('data-slot') === 'skeleton',
    )
    expect(skeletons.length).toBeGreaterThan(0)
  })

  it('shows error state when artist fails to load', () => {
    mockConfig.isError = true
    render(<SCTypedThemeProvider theme={testTheme}><ArtistDetailPage /></SCTypedThemeProvider>)
    expect(screen.getByText('Failed to load artist')).toBeInTheDocument()
    expect(screen.getByText('Go back')).toBeInTheDocument()
  })

  it('navigates back when back button is clicked', async () => {
    const user = userEvent.setup()
    render(<SCTypedThemeProvider theme={testTheme}><ArtistDetailPage /></SCTypedThemeProvider>)
    await user.click(screen.getByLabelText('Go back'))
    expect(mockNavigate).toHaveBeenCalledWith(-1)
  })

  it('shows singular "album" for one album', () => {
    mockConfig.albumsData = makeAlbumsData(1)
    render(<SCTypedThemeProvider theme={testTheme}><ArtistDetailPage /></SCTypedThemeProvider>)
    expect(screen.getByText('1 album')).toBeInTheDocument()
  })

  it('renders Play and Shuffle buttons', () => {
    render(<SCTypedThemeProvider theme={testTheme}><ArtistDetailPage /></SCTypedThemeProvider>)
    expect(screen.getByLabelText('Play all')).toBeInTheDocument()
    expect(screen.getByLabelText('Shuffle all')).toBeInTheDocument()
  })

  it('sets context panel selected item on mount', () => {
    render(<SCTypedThemeProvider theme={testTheme}><ArtistDetailPage /></SCTypedThemeProvider>)
    expect(mockSetSelectedItem).toHaveBeenCalledWith({
      type: 'artist',
      publicId: 'artist-1',
    })
  })

  it('shows empty discography message', () => {
    mockConfig.albumsData = makeAlbumsData(0)
    render(<SCTypedThemeProvider theme={testTheme}><ArtistDetailPage /></SCTypedThemeProvider>)
    expect(screen.getByText('No albums yet')).toBeInTheDocument()
  })
})
