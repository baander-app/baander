import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ThemeProvider as SCTypedThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'

const testTheme = resolveTheme('dark', 'violet')

const mockRefresh = vi.fn()

type MockConfig = {
  clusters?: unknown[]
  isLoading?: boolean
  error?: unknown
}

let mockConfig: MockConfig = {}

vi.mock('@/features/catalog/hooks/use-discover-view-model', () => ({
  useDiscoverViewModel: () => ({
    clusters: mockConfig.clusters ?? [],
    isLoading: mockConfig.isLoading ?? false,
    error: mockConfig.error ?? null,
    refresh: mockRefresh,
  }),
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

import { DiscoverView } from '../DiscoverView'

describe('DiscoverView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockConfig = {}
  })

  it('shows empty state message when no clusters', () => {
    mockConfig.clusters = []
    render(<SCTypedThemeProvider theme={testTheme}><DiscoverView /></SCTypedThemeProvider>)
    expect(
      screen.getByText('No recommendations yet. Listen to more music to get personalized suggestions.'),
    ).toBeInTheDocument()
  })

  it('shows loading skeletons', () => {
    mockConfig.isLoading = true
    mockConfig.clusters = []
    render(<SCTypedThemeProvider theme={testTheme}><DiscoverView /></SCTypedThemeProvider>)
    // Skeleton elements present
    const skeletons = document.querySelectorAll('[data-slot="skeleton"]')
    expect(skeletons.length).toBeGreaterThan(0)
  })

  it('shows error state with retry button', () => {
    mockConfig.error = new Error('API error')
    mockConfig.clusters = []
    render(<SCTypedThemeProvider theme={testTheme}><DiscoverView /></SCTypedThemeProvider>)
    expect(screen.getByText('Failed to load recommendations')).toBeInTheDocument()
    expect(screen.getByText('Retry')).toBeInTheDocument()
  })

  it('calls refresh when retry is clicked', async () => {
    const user = userEvent.setup()
    mockConfig.error = new Error('API error')
    mockConfig.clusters = []
    render(<SCTypedThemeProvider theme={testTheme}><DiscoverView /></SCTypedThemeProvider>)
    await user.click(screen.getByText('Retry'))
    expect(mockRefresh).toHaveBeenCalledOnce()
  })

  it('renders cluster sections when data is present', () => {
    mockConfig.clusters = [
      {
        sourceId: 'album-a',
        sourceType: 'album',
        sourceName: 'Album A',
        items: [
          {
            id: 'rec-1',
            name: 'Because you listened to Album A',
            source_type: 'album',
            source_id: 'album-a',
            target_type: 'album',
            target_id: 'album-b',
            score: 0.9,
            position: 1,
            user_id: null,
            created_at: '',
            updated_at: '',
          },
        ],
      },
    ]
    render(<SCTypedThemeProvider theme={testTheme}><DiscoverView /></SCTypedThemeProvider>)
    expect(screen.getByText('Because you listened to Album A')).toBeInTheDocument()
  })

  it('renders Refresh button', () => {
    mockConfig.clusters = []
    // Need data to show the main content
    mockConfig.clusters = [
      {
        sourceId: 'album-a',
        sourceType: 'album',
        sourceName: 'Album A',
        items: [],
      },
    ]
    render(<SCTypedThemeProvider theme={testTheme}><DiscoverView /></SCTypedThemeProvider>)
    expect(screen.getByText('Refresh')).toBeInTheDocument()
  })

  it('calls refresh when Refresh button is clicked', async () => {
    const user = userEvent.setup()
    mockConfig.clusters = [
      {
        sourceId: 'album-a',
        sourceType: 'album',
        sourceName: 'Album A',
        items: [],
      },
    ]
    render(<SCTypedThemeProvider theme={testTheme}><DiscoverView /></SCTypedThemeProvider>)
    await user.click(screen.getByText('Refresh'))
    expect(mockRefresh).toHaveBeenCalledOnce()
  })
})
