import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { ThemeProvider as SCTypedThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'

const testTheme = resolveTheme('dark', 'violet')

function renderWithTheme(ui: React.ReactElement) {
  return render(<SCTypedThemeProvider theme={testTheme}>{ui}</SCTypedThemeProvider>)
}

// --- Mocks ---

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

vi.mock('react-router-dom', () => ({
  useNavigate: () => vi.fn(),
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

import { ArtistDiscography } from '../ArtistDiscography'

const makeAlbums = (count: number) =>
  Array.from({ length: count }, (_, i) => ({
    uuid: `uuid-album-${i}`,
    publicId: `album-${i}`,
    title: `Album ${i}`,
    type: 'album',
    year: 2024,
    label: null,
    barcode: null,
    country: null,
    catalogNumber: null,
    language: null,
    disambiguation: null,
    annotation: null,
    mbid: null,
    discogsId: null,
    spotifyId: null,
    createdAt: '2024-01-01T00:00:00Z',
    coverImage: { url: `/cover/${i}.jpg`, blurhash: null },
    artists: [{ name: 'Test Artist', role: null }],
  }))

describe('ArtistDiscography', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders album cards', () => {
    renderWithTheme(<ArtistDiscography albums={makeAlbums(3)} isLoading={false} />)
    expect(screen.getByText('Album 0')).toBeInTheDocument()
    expect(screen.getByText('Album 1')).toBeInTheDocument()
    expect(screen.getByText('Album 2')).toBeInTheDocument()
  })

  it('shows "No albums yet" when empty', () => {
    renderWithTheme(<ArtistDiscography albums={[]} isLoading={false} />)
    expect(screen.getByText('No albums yet')).toBeInTheDocument()
  })

  it('shows "No albums yet" when albums is undefined', () => {
    renderWithTheme(<ArtistDiscography albums={undefined} isLoading={false} />)
    expect(screen.getByText('No albums yet')).toBeInTheDocument()
  })

  it('shows skeleton cards when loading', () => {
    renderWithTheme(<ArtistDiscography albums={undefined} isLoading={true} />)
    const skeletons = screen.getAllByRole('generic').filter(
      (el) => el.getAttribute('data-slot') === 'skeleton',
    )
    expect(skeletons.length).toBeGreaterThan(0)
  })

  it('does not show empty state while loading', () => {
    renderWithTheme(<ArtistDiscography albums={undefined} isLoading={true} />)
    expect(screen.queryByText('No albums yet')).not.toBeInTheDocument()
  })
})
