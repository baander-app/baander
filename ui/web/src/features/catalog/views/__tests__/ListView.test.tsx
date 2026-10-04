import { describe, it, expect, vi, beforeEach } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ListView } from '../ListView'
import type { GetSongIndexParams } from '@/shared/api-client/gen/endpoints'
import { useListColumnStore } from '../../stores/list-column-store'

// Mock the transport while retaining the real query lifecycle
const mockGetSongIndex = vi.fn()
vi.mock('@/shared/api-client/gen/endpoints', () => ({
  getSongIndex: (...args: unknown[]) => mockGetSongIndex(...args),
  getGetSongIndexQueryKey: (params: GetSongIndexParams) => ['/api/songs/', params],
}))

function createQueryWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  }
}

const mockSongs = {
  data: [
    { publicId: 's1', title: 'Song A', artistName: 'Artist A', albumName: 'Album A', year: 2024, length: 180 },
    { publicId: 's2', title: 'Song B', artistName: 'Artist B', albumName: 'Album B', year: 2023, length: 240 },
  ],
  meta: {
    next_cursor: null,
    prev_cursor: null,
    has_next_page: false,
    has_previous_page: false,
    total: 2,
    stale_cursor: false,
    per_page: 100,
  },
}

describe('ListView', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    useListColumnStore.setState({
      visibleColumns: ['#', 'title', 'artist', 'album', 'year', 'duration'],
      columnOrder: ['#', 'title', 'artist', 'album', 'year', 'genre', 'duration', 'bitrate', 'format', 'createdAt'],
    })
    mockGetSongIndex.mockResolvedValue(mockSongs)
  })

  it('renders song rows (via virtualizer)', async () => {
    // In jsdom, the virtualizer container has 0 height so no rows render.
    // We verify the data reaches the component by checking the total size.
    render(<ListView />, { wrapper: createQueryWrapper() })

    // The virtualizer should have calculated total height: 2 songs × 32px = 64px
    await waitFor(() => expect(document.querySelector('[style*="height: 64px"]')).toBeInTheDocument())
  })

  it('passes sort params to API when sorting', async () => {
    mockGetSongIndex.mockResolvedValue(mockSongs)

    render(<ListView />, { wrapper: createQueryWrapper() })

    // Initial call should not have sort
    const initialCall = mockGetSongIndex.mock.calls[0][0]
    expect(initialCall.sort).toBeUndefined()

    // Simulate sort change by clicking a header
    const titleHeader = screen.getByText('Title')
    fireEvent.click(titleHeader)

    // A sort change starts a fresh query with the corresponding transport params.
    await waitFor(() => {
      const lastCall = mockGetSongIndex.mock.calls[mockGetSongIndex.mock.calls.length - 1][0]
      expect(lastCall.sort).toBe('title')
      expect(lastCall.order).toBe('asc')
    })
  })

  it('shows loading skeleton while loading', () => {
    mockGetSongIndex.mockReturnValue(new Promise(() => {}))

    render(<ListView />, { wrapper: createQueryWrapper() })

    // Should show header
    expect(screen.getByText('Title')).toBeInTheDocument()
    // Should not show songs
    expect(screen.queryByText('Song A')).not.toBeInTheDocument()
  })

  it('shows empty state when no songs', async () => {
    mockGetSongIndex.mockResolvedValue({ data: [], meta: { ...mockSongs.meta, total: 0 } })

    render(<ListView />, { wrapper: createQueryWrapper() })

    expect(await screen.findByText('No songs')).toBeInTheDocument()
  })

  it('virtualizer sets correct total height for large lists', async () => {
    const manySongs = Array.from({ length: 200 }, (_, i) => ({
      publicId: `s${i}`,
      title: `Song ${i}`,
      artistName: 'Artist',
      albumName: 'Album',
      year: 2024,
      length: 180,
    }))

    mockGetSongIndex.mockResolvedValue({ data: manySongs, meta: { ...mockSongs.meta, total: 200 } })

    render(<ListView />, { wrapper: createQueryWrapper() })

    // Total height: 200 songs × 32px = 6400px
    await waitFor(() => expect(document.querySelector('[style*="height: 6400px"]')).toBeInTheDocument())
  })
})
