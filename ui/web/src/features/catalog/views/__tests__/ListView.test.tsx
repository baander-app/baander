import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ListView } from '../ListView'
import { useListColumnStore } from '../../stores/list-column-store'

// Mock the API hook
const mockUseGetSongIndex = vi.fn()
vi.mock('@/shared/api-client/gen/endpoints', () => ({
  useGetSongIndex: (...args: any[]) => mockUseGetSongIndex(...args),
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
  nextCursor: null,
  hasNextPage: false,
  total: 2,
  perPage: 100,
}

describe('ListView', () => {
  beforeEach(() => {
    useListColumnStore.setState({
      visibleColumns: ['#', 'title', 'artist', 'album', 'year', 'duration'],
      columnOrder: ['#', 'title', 'artist', 'album', 'year', 'genre', 'duration', 'bitrate', 'format', 'createdAt'],
    })
    mockUseGetSongIndex.mockReturnValue({ data: mockSongs, isLoading: false })
  })

  it('renders song rows (via virtualizer)', () => {
    // In jsdom, the virtualizer container has 0 height so no rows render.
    // We verify the data reaches the component by checking the total size.
    render(<ListView />, { wrapper: createQueryWrapper() })

    // The virtualizer should have calculated total height: 2 songs × 32px = 64px
    const container = document.querySelector('[style*="height: 64px"]')
    expect(container).toBeInTheDocument()
  })

  it('passes sort params to API when sorting', async () => {
    mockUseGetSongIndex.mockReturnValue({ data: mockSongs, isLoading: false })

    render(<ListView />, { wrapper: createQueryWrapper() })

    // Initial call should not have sort
    const initialCall = mockUseGetSongIndex.mock.calls[0][0]
    expect(initialCall.sort).toBeUndefined()

    // Simulate sort change by clicking a header
    const titleHeader = screen.getByText('Title')
    titleHeader.click()

    // After click, a new render will re-call the hook with sort params.
    // Since the hook is mocked, we check that the last call has sort params.
    await waitFor(() => {
      const lastCall = mockUseGetSongIndex.mock.calls[mockUseGetSongIndex.mock.calls.length - 1][0]
      expect(lastCall.sort).toBe('title')
      expect(lastCall.order).toBe('asc')
    })
  })

  it('shows loading skeleton while loading', () => {
    mockUseGetSongIndex.mockReturnValue({ data: null, isLoading: true })

    render(<ListView />, { wrapper: createQueryWrapper() })

    // Should show header
    expect(screen.getByText('Title')).toBeInTheDocument()
    // Should not show songs
    expect(screen.queryByText('Song A')).not.toBeInTheDocument()
  })

  it('shows empty state when no songs', () => {
    mockUseGetSongIndex.mockReturnValue({
      data: { data: [], nextCursor: null, hasNextPage: false, total: 0, perPage: 100 },
      isLoading: false,
    })

    render(<ListView />, { wrapper: createQueryWrapper() })

    expect(screen.getByText('No songs')).toBeInTheDocument()
  })

  it('virtualizer sets correct total height for large lists', () => {
    const manySongs = Array.from({ length: 200 }, (_, i) => ({
      publicId: `s${i}`,
      title: `Song ${i}`,
      artistName: 'Artist',
      albumName: 'Album',
      year: 2024,
      length: 180,
    }))

    mockUseGetSongIndex.mockReturnValue({
      data: { data: manySongs, nextCursor: null, hasNextPage: false, total: 200, perPage: 100 },
      isLoading: false,
    })

    render(<ListView />, { wrapper: createQueryWrapper() })

    // Total height: 200 songs × 32px = 6400px
    const container = document.querySelector('[style*="height: 6400px"]')
    expect(container).toBeInTheDocument()
  })
})
