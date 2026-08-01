import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

const mockSelect = vi.fn()
const mockNavigate = vi.fn()

vi.mock('@/features/catalog/stores/selection-store', () => ({
  useSelectionStore: (selector: (s: Record<string, unknown>) => unknown) =>
    selector({
      selectedId: null,
      selectedType: null,
      select: mockSelect,
      clear: vi.fn(),
    }),
}))

vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
}))

const mockSrc = '/blob/cover.jpg'
const mockIsLoading = false

vi.mock('@/shared/hooks/use-image-blob', () => ({
  useImageBlob: () => ({ src: mockSrc, isLoading: mockIsLoading }),
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

import { AlbumGridCard } from '../AlbumGridCard'

describe('AlbumGridCard', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  const defaultProps = {
    publicId: 'album-123',
    title: 'Test Album',
    artistName: 'Test Artist',
    imageUrl: '/api/images/cover.jpg',
    blurhash: 'LEHV6nWB2yk8pyo0adR*.7kCMdnj',
  }

  it('renders album title and artist name', () => {
    render(<AlbumGridCard {...defaultProps} />)
    expect(screen.getByText('Test Album')).toBeInTheDocument()
    expect(screen.getByText('Test Artist')).toBeInTheDocument()
  })

  it('renders cover image with correct alt text', () => {
    render(<AlbumGridCard {...defaultProps} />)
    const img = screen.getByRole('img')
    expect(img).toHaveAttribute('alt', 'Test Album')
  })

  it('calls selection store select on click', async () => {
    render(<AlbumGridCard {...defaultProps} />)
    const card = screen.getByRole('gridcell')
    await userEvent.setup().click(card)
    expect(mockSelect).toHaveBeenCalledWith('album-123', 'album')
  })

  it('navigates to album detail on Enter', () => {
    render(<AlbumGridCard {...defaultProps} />)
    const card = screen.getByRole('gridcell')
    fireEvent.keyDown(card, { key: 'Enter' })
    expect(mockNavigate).toHaveBeenCalledWith('/albums/album-123')
  })

  it('navigates to album detail on Space', () => {
    render(<AlbumGridCard {...defaultProps} />)
    const card = screen.getByRole('gridcell')
    fireEvent.keyDown(card, { key: ' ' })
    expect(mockNavigate).toHaveBeenCalledWith('/albums/album-123')
  })

  it('renders without artist name when not provided', () => {
    render(<AlbumGridCard {...defaultProps} artistName={undefined} />)
    expect(screen.getByText('Test Album')).toBeInTheDocument()
    expect(screen.queryByText('Test Artist')).not.toBeInTheDocument()
  })

  it('has context menu wrapper (right-click target)', async () => {
    const user = userEvent.setup()
    render(<AlbumGridCard {...defaultProps} />)
    const card = screen.getByRole('gridcell')
    // Right-click the card — the context menu should open
    await user.pointer({ keys: '[MouseRight]', target: card })
    // AlbumContextMenu renders Play All on open
    expect(screen.getByText('Play All')).toBeInTheDocument()
  })

  it('has focusable gridcell for keyboard navigation', () => {
    render(<AlbumGridCard {...defaultProps} />)
    const card = screen.getByRole('gridcell')
    expect(card).toHaveAttribute('tabIndex', '0')
  })
})
