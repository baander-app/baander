import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { TimelineDecade } from '../TimelineDecade'
import type { TimelineDecade as TimelineDecadeType } from '../../hooks/use-timeline-view-model'

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

function makeAlbum(publicId: string, year: number) {
  return {
    uuid: `uuid-${publicId}`,
    publicId,
    title: `Album ${publicId}`,
    type: 'album',
    year,
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

describe('TimelineDecade', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders decade label', () => {
    const decade: TimelineDecadeType = {
      label: '2020s',
      years: [
        { label: '2024', albums: [makeAlbum('a1', 2024)] },
      ],
    }

    render(<TimelineDecade decade={decade} />)
    expect(screen.getByText('2020s')).toBeInTheDocument()
  })

  it('renders year labels', () => {
    const decade: TimelineDecadeType = {
      label: '2020s',
      years: [
        { label: '2024', albums: [makeAlbum('a1', 2024)] },
        { label: '2023', albums: [makeAlbum('a2', 2023)] },
      ],
    }

    render(<TimelineDecade decade={decade} />)
    expect(screen.getByText('2024')).toBeInTheDocument()
    expect(screen.getByText('2023')).toBeInTheDocument()
  })

  it('starts expanded by default', () => {
    const decade: TimelineDecadeType = {
      label: '2020s',
      years: [
        { label: '2024', albums: [makeAlbum('a1', 2024)] },
      ],
    }

    render(<TimelineDecade decade={decade} />)
    const toggle = screen.getByRole('button', { name: /2020s/ })
    expect(toggle).toHaveAttribute('aria-expanded', 'true')
  })

  it('collapses when header is clicked', async () => {
    const user = userEvent.setup()
    const decade: TimelineDecadeType = {
      label: '2020s',
      years: [
        { label: '2024', albums: [makeAlbum('a1', 2024)] },
      ],
    }

    render(<TimelineDecade decade={decade} />)
    const toggle = screen.getByRole('button', { name: /2020s/ })

    await user.click(toggle)
    expect(toggle).toHaveAttribute('aria-expanded', 'false')
  })

  it('expands again when clicked after collapsing', async () => {
    const user = userEvent.setup()
    const decade: TimelineDecadeType = {
      label: '2020s',
      years: [
        { label: '2024', albums: [makeAlbum('a1', 2024)] },
      ],
    }

    render(<TimelineDecade decade={decade} />)
    const toggle = screen.getByRole('button', { name: /2020s/ })

    await user.click(toggle)
    expect(toggle).toHaveAttribute('aria-expanded', 'false')

    await user.click(toggle)
    expect(toggle).toHaveAttribute('aria-expanded', 'true')
  })
})
