import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ThemeProvider as SCTypedThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { ActivityGroup } from '../ActivityGroup'
import type { ActivityEntry } from '../../types/activity'

const testTheme = resolveTheme('dark', 'violet')

function renderWithProviders(ui: React.ReactElement) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <SCTypedThemeProvider theme={testTheme}>{ui}</SCTypedThemeProvider>
    </QueryClientProvider>,
  )
}

// Mock react-router-dom (required by useContextActions used in SongContextMenu)
vi.mock('react-router-dom', () => ({
  useNavigate: () => vi.fn(),
}))

// Mock player store (required by SongContextMenu)
vi.mock('@/features/player/stores/player-store', () => ({
  usePlayerStore: (selector: (s: Record<string, unknown>) => unknown) =>
    selector({ currentTrack: null, playTrack: vi.fn(), insertAfterCurrent: vi.fn(), addToQueue: vi.fn() }),
}))

// Mock AddToPlaylistDialog (required by useContextActions)
vi.mock('@/features/playlist/components/AddToPlaylistDialog', () => ({
  AddToPlaylistDialog: () => null,
}))

const now = new Date()
const oneHourAgo = new Date(now.getTime() - 3600_000).toISOString()

function makeEntry(overrides: Partial<ActivityEntry> = {}): ActivityEntry {
  return {
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
    ...overrides,
  }
}

describe('ActivityGroup', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders the period label', () => {
    const items = [makeEntry()]
    renderWithProviders(<ActivityGroup label="Today" items={items} />)
    expect(screen.getByText('Today')).toBeInTheDocument()
  })

  it('renders items', () => {
    const items = [
      makeEntry({ publicId: 'p1', songTitle: 'Song A' }),
      makeEntry({ publicId: 'p2', songTitle: 'Song B' }),
    ]
    renderWithProviders(<ActivityGroup label="Today" items={items} />)
    expect(screen.getByText('Song A')).toBeInTheDocument()
    expect(screen.getByText('Song B')).toBeInTheDocument()
  })

  it('renders song IDs as fallback when no title', () => {
    const items = [makeEntry({ publicId: 'p1', songId: 'abc123', songTitle: null })]
    renderWithProviders(<ActivityGroup label="Yesterday" items={items} />)
    expect(screen.getByText('abc123')).toBeInTheDocument()
  })

  it('renders artist and album names', () => {
    const items = [makeEntry({
      publicId: 'p1',
      songTitle: 'Song A',
      artistName: 'Artist A',
      albumName: 'Album A',
    })]
    renderWithProviders(<ActivityGroup label="Today" items={items} />)
    expect(screen.getByText('Artist A')).toBeInTheDocument()
    expect(screen.getByText('Album A')).toBeInTheDocument()
  })
})
