import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ThemeProvider as SCTypedThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import type { ReactNode } from 'react'
import { ActivityView } from '../ActivityView'
import MockAdapter from 'axios-mock-adapter'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

const testTheme = resolveTheme('dark', 'violet')

vi.mock('@/features/auth/stores/auth-store', () => ({
  useAuthStore: { getState: () => ({ accessToken: null, refreshToken: null }) },
}))
vi.mock('@/shared/crypto/dpop-store', () => ({
  getDpopKeyPair: () => null, getDpopNonce: () => null, setDpopNonce: vi.fn(),
}))
vi.mock('@/shared/crypto/dpop-proof', () => ({ createDpopProof: vi.fn() }))

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
  let api: MockAdapter
  const url = '/api/activity/history?limit=50&offset=0'
  beforeEach(() => {
    vi.clearAllMocks()
    api = new MockAdapter(AXIOS_INSTANCE, { onNoMatch: 'throwException' })
  })

  afterEach(() => { cleanup(); api.restore() })

  it('shows loading skeleton', () => {
    api.onGet(url).reply(() => new Promise(() => {}))
    render(<ActivityView />, { wrapper: createWrapper() })
    // styled-components no longer uses .animate-pulse; check for data-slot="skeleton"
    const skeletons = document.querySelectorAll('[data-slot="skeleton"]')
    expect(skeletons.length).toBeGreaterThan(0)
  })

  it('shows empty state', async () => {
    api.onGet(url).reply(200, { data: [] })
    render(<ActivityView />, { wrapper: createWrapper() })
    expect(await screen.findByText('No listening activity yet')).toBeInTheDocument()
  })

  it('shows error state with retry', async () => {
    api.onGet(url).reply(500, { error: { message: 'Failed' } })
    render(<ActivityView />, { wrapper: createWrapper() })
    expect(await screen.findByText('Failed to load activity history')).toBeInTheDocument()
    expect(screen.getByText('Retry')).toBeInTheDocument()
  })

  it('renders activity groups', async () => {
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

    api.onGet(url).reply(200, { data: entries })

    render(<ActivityView />, { wrapper: createWrapper() })

    expect(await screen.findByText('Today')).toBeInTheDocument()
    expect(screen.getByText('My Song')).toBeInTheDocument()
  })
})
