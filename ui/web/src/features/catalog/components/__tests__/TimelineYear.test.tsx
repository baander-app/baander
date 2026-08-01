import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { TimelineYear } from '../TimelineYear'
import type { AlbumSummary } from '../../types'

const mockSelect = vi.fn()
const mockNavigate = vi.fn()

vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
}))

vi.mock('@/features/catalog/stores/selection-store', () => ({
  useSelectionStore: (selector: (s: Record<string, unknown>) => unknown) =>
    selector({
      selectedId: null,
      selectedType: null,
      select: mockSelect,
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

function makeAlbum(publicId: string, title?: string): AlbumSummary {
  return {
    uuid: `uuid-${publicId}`,
    publicId,
    title: title ?? `Album ${publicId}`,
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
    coverImage: null,
    artists: [],
  }
}

describe('TimelineYear', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders year label', () => {
    render(<TimelineYear label="2024" albums={[makeAlbum('a1')]} />)
    expect(screen.getByText('2024')).toBeInTheDocument()
  })

  it('renders album thumbnails', () => {
    render(
      <TimelineYear
        label="2024"
        albums={[makeAlbum('a1'), makeAlbum('a2')]}
      />,
    )
    const list = screen.getByRole('list', { name: 'Albums from 2024' })
    expect(list).toBeInTheDocument()
    const items = screen.getAllByRole('listitem')
    expect(items).toHaveLength(2)
  })

  it('selects album on click', async () => {
    const user = userEvent.setup()
    render(<TimelineYear label="2024" albums={[makeAlbum('a1')]} />)

    const thumbnail = screen.getByRole('button')
    await user.click(thumbnail)

    expect(mockSelect).toHaveBeenCalledWith('a1', 'album')
  })

  it('navigates to album detail on Enter', async () => {
    const user = userEvent.setup()
    render(<TimelineYear label="2024" albums={[makeAlbum('a1')]} />)

    const thumbnail = screen.getByRole('button')
    await user.type(thumbnail, '{Enter}')

    expect(mockNavigate).toHaveBeenCalledWith('/albums/a1')
  })

  it('navigates to album detail on Space', async () => {
    const user = userEvent.setup()
    render(<TimelineYear label="2024" albums={[makeAlbum('a1')]} />)

    const thumbnail = screen.getByRole('button')
    await user.type(thumbnail, ' ')

    expect(mockNavigate).toHaveBeenCalledWith('/albums/a1')
  })
})
