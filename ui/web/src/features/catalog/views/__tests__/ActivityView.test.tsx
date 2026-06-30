import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ThemeProvider as SCTypedThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import type { ReactNode } from 'react'
import { ActivityView } from '../ActivityView'

const testTheme = resolveTheme('dark', 'violet')

const mockUseGetActivityHistory = vi.fn()
vi.mock('@/shared/api-client/gen/endpoints', async (importOriginal) => {
  const actual = await importOriginal() as Record<string, unknown>
  return {
    ...actual,
    useGetActivityHistory: (...args: any[]) => mockUseGetActivityHistory(...args),
  }
})

// Mock react-router-dom (required by SongContextMenu chain)
vi.mock('react-router-dom', () => ({
  useNavigate: () => vi.fn(),
}))

// Mock player store (required by SongContextMenu)
vi.mock('@/features/player/stores/player-store', () => ({
  usePlayerStore: (selector: (s: Record<string, unknown>) => unknown) =>
    selector({ currentTrack: null, playTrack: vi.fn(), insertAfterCurrent: vi.fn(), addToQueue: vi.fn() }),
}))

// Mock AddToPlaylistDialog
vi.mock('@/features/playlist/components/AddToPlaylistDialog', () => ({
  AddToPlaylistDialog: () => null,
}))

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={qc}>
        <SCTypedThemeProvider theme={testTheme}>{children}</SCTypedThemeProvider>
      </QueryClientProvider>
    )
  }
}

const now = new Date()
const oneHourAgo = new Date(now.getTime() - 3600_000).toISOString()

describe('ActivityView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('shows loading skeleton', () => {
    mockUseGetActivityHistory.mockReturnValue({ data: null, isLoading: true, error: null, refetch: vi.fn() })
    render(<ActivityView />, { wrapper: createWrapper() })
    // styled-components no longer uses .animate-pulse; check for data-slot="skeleton"
    const skeletons = document.querySelectorAll('[data-slot="skeleton"]')
    expect(skeletons.length).toBeGreaterThan(0)
  })

  it('shows empty state', () => {
    mockUseGetActivityHistory.mockReturnValue({
      data: { data: [] },
      isLoading: false,
      error: null,
      refetch: vi.fn(),
    })
    render(<ActivityView />, { wrapper: createWrapper() })
    expect(screen.getByText('No listening activity yet')).toBeInTheDocument()
  })

  it('shows error state with retry', () => {
    mockUseGetActivityHistory.mockReturnValue({
      data: null,
      isLoading: false,
      error: new Error('fail'),
      refetch: vi.fn(),
    })
    render(<ActivityView />, { wrapper: createWrapper() })
    expect(screen.getByText('Failed to load activity history')).toBeInTheDocument()
    expect(screen.getByText('Retry')).toBeInTheDocument()
  })

  it('renders activity groups', () => {
    const entries = [
      {
        uuid: 'u1',
        publicId: 'p1',
        userId: 'user1',
        activityType: 'play',
        songId: 'song1',
        albumId: null,
        artistId: null,
        movieId: null,
        playCount: 1,
        love: false,
        lastPlayedAt: oneHourAgo,
        lastPlatform: 'web',
        lastPlayer: null,
        createdAt: oneHourAgo,
        songTitle: 'My Song',
        artistName: null,
        albumName: null,
      },
    ]

    mockUseGetActivityHistory.mockReturnValue({
      data: { data: entries },
      isLoading: false,
      error: null,
      refetch: vi.fn(),
    })

    render(<ActivityView />, { wrapper: createWrapper() })

    expect(screen.getByText('Today')).toBeInTheDocument()
    expect(screen.getByText('My Song')).toBeInTheDocument()
  })
})
