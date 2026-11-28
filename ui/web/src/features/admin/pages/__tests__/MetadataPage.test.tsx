import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { render } from '../../../../../tests/test-utils'
import { AXIOS_INSTANCE, customInstance } from '@/shared/api-client/axios-instance'
import type { MetadataProvider, MetadataSyncStatus } from '../../api/metadata-admin-api'
import { MetadataPage } from '../MetadataPage'

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: { get: vi.fn(), post: vi.fn() },
  customInstance: vi.fn(),
}))

vi.mock('@/features/auth/stores/auth-store', () => ({
  useAuthStore: vi.fn((selector: (state: { user: { roles: string[] } }) => unknown) =>
    selector({ user: { roles: ['ROLE_ADMIN'] } })),
}))

const mockGet = vi.mocked(AXIOS_INSTANCE.get)
const mockRequest = vi.mocked(customInstance)

/** The status as GET /api/admin/metadata/sync-status sends it: sources are the sync jobs by type. */
const SYNC_STATUS: MetadataSyncStatus = {
  lastSyncAt: '2026-10-08T12:00:00+00:00',
  totalTracks: 120,
  syncedTracks: 80,
  pendingTracks: 38,
  failedTracks: 2,
  sources: [
    { name: 'SyncAlbumMessage', synced: 10, failed: 2 },
    { name: 'SyncLibraryMessage', synced: 3, failed: 0 },
  ],
}

const PROVIDERS: MetadataProvider[] = [
  {
    id: 'musicbrainz',
    name: 'MusicBrainz',
    enabled: true,
    configured: true,
    attributionText: '',
    attributionUrl: '',
  },
  {
    id: 'discogs',
    name: 'Discogs',
    enabled: true,
    configured: false,
    attributionText: '',
    attributionUrl: '',
  },
  {
    id: 'tvdb',
    name: 'TheTVDB',
    enabled: true,
    configured: true,
    attributionText: '',
    attributionUrl: '',
  },
]

function mount({ syncFails = false, switchFails = false } = {}) {
  mockRequest.mockImplementation(async (url, request) => {
    if (url === '/api/admin/metadata/providers' && request?.method === 'GET') {
      return { data: PROVIDERS }
    }
    const provider = PROVIDERS.find(({ id }) => url === `/api/admin/metadata/providers/${id}`)
    if (provider && request?.method === 'PATCH') {
      if (switchFails) throw new Error('The setting could not be saved.')
      return { data: { ...provider, enabled: false } }
    }
    if (url.startsWith('/api/admin/metadata/match/reviews?')) {
      return { data: { items: [], has_next_page: false, next_cursor: null, per_page: 50 } }
    }
    if (url.startsWith('/api/admin/video-matches/reviews?')) {
      return { data: { items: [], hasNextPage: false, nextCursor: null, perPage: 50, pausedProviders: [] } }
    }
    throw new Error(`Unexpected request ${url}`)
  })
  mockGet.mockImplementation((url: string) => {
    if (syncFails && url === '/api/admin/metadata/sync-status') {
      return Promise.reject(new Error('The sync status is unavailable.'))
    }
    const data: Record<string, unknown> = {
      '/api/admin/metadata/sync-status': SYNC_STATUS,
      '/api/genres/': [],
      '/api/libraries': [],
    }
    if (url in data) {
      return Promise.resolve({ data: { data: data[url] } })
    }

    return Promise.reject(new Error(`Unexpected GET ${url}`))
  })
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={queryClient}>
      <MetadataPage />
    </QueryClientProvider>,
  )
}

describe('MetadataPage', () => {
  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
  })

  it('renders the sync status counts and the sync jobs by type', async () => {
    mount()

    expect(await screen.findByText('Sync Jobs')).toBeInTheDocument()
    expect(screen.getByText('120')).toBeInTheDocument()
    expect(screen.getByText('80')).toBeInTheDocument()
    expect(screen.getByText('66.7% coverage')).toBeInTheDocument()
    expect(screen.getByText('38')).toBeInTheDocument()
    expect(screen.getByText('SyncAlbumMessage')).toBeInTheDocument()
    expect(screen.getByText('SyncLibraryMessage')).toBeInTheDocument()
    expect(screen.getByText('10')).toBeInTheDocument()
    expect(await screen.findByText('Discogs')).toBeInTheDocument()
    expect(screen.getByText('Not configured')).toBeInTheDocument()
  })

  it('lets admins disable a provider by its ID and shows the confirmed state', async () => {
    mount()

    const user = userEvent.setup()
    const provider = await screen.findByRole('switch', { name: 'TheTVDB' })
    expect(provider).toBeChecked()
    expect(screen.queryByRole('button', { name: 'Trigger Sync' })).not.toBeInTheDocument()
    provider.focus()
    await user.keyboard('[Space]')
    await waitFor(() => expect(provider).not.toBeChecked())

    expect(mockRequest).toHaveBeenCalledWith('/api/admin/metadata/providers/tvdb', expect.objectContaining({
      method: 'PATCH',
      body: '{"enabled":false}',
    }))
    expect(screen.getByText('Off')).toBeInTheDocument()
    expect(provider).toHaveAccessibleDescription(/refreshes.*wait/i)
  })

  it('keeps both review lists and provider controls available when sync status fails', async () => {
    mount({ syncFails: true })

    expect(await screen.findByText('Failed to load metadata status.')).toBeInTheDocument()
    expect(await screen.findByRole('switch', { name: 'Discogs' })).toBeEnabled()
    expect(screen.getByRole('heading', { name: 'Album reviews' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Video reviews' })).toBeInTheDocument()
  })

  it('leaves a provider on and shows its error when a switch request fails', async () => {
    mount({ switchFails: true })

    const provider = await screen.findByRole('switch', { name: 'Discogs' })
    fireEvent.click(provider)
    expect(await screen.findByText(/Could not change Discogs/)).toBeInTheDocument()
    expect(provider).toBeChecked()
    expect(provider).toBeEnabled()
  })
})
