import { cleanup, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { render } from '../../../../../tests/test-utils'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import type { MetadataProvider, MetadataSyncStatus } from '../../api/metadata-admin-api'
import { MetadataPage } from '../MetadataPage'

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: { get: vi.fn(), post: vi.fn() },
}))

vi.mock('@/features/auth/stores/auth-store', () => ({
  useAuthStore: vi.fn((selector: (state: { user: { roles: string[] } }) => unknown) =>
    selector({ user: { roles: ['ROLE_ADMIN'] } })),
}))

const mockGet = vi.mocked(AXIOS_INSTANCE.get)

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
  { name: 'MusicBrainz', enabled: true, configured: true },
  { name: 'Discogs', enabled: true, configured: false },
]

function mount() {
  mockGet.mockImplementation((url: string) => {
    const data: Record<string, unknown> = {
      '/api/admin/metadata/sync-status': SYNC_STATUS,
      '/api/admin/metadata/providers': PROVIDERS,
      '/api/genres/': [],
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
})
