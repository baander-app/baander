import { cleanup, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { render } from '../../../../../tests/test-utils'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import type { LyricsCoverage, SyncStatus } from '../../api/lyrics-admin-api'
import { LyricsAdminPage } from '../LyricsAdminPage'

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: { get: vi.fn(), post: vi.fn() },
}))

const authState = vi.hoisted(() => ({ roles: ['ROLE_ADMIN'] as string[] }))

vi.mock('@/features/auth/stores/auth-store', () => ({
  useAuthStore: vi.fn((selector: (state: { user: { roles: string[] } }) => unknown) =>
    selector({ user: { roles: authState.roles } })),
}))

const mockGet = vi.mocked(AXIOS_INSTANCE.get)
const mockPost = vi.mocked(AXIOS_INSTANCE.post)

/** The coverage as GET /api/admin/lyrics/coverage sends it: bySource maps a source to its count. */
const COVERAGE: LyricsCoverage = {
  totalTracks: 200,
  tracksWithLyrics: 50,
  tracksWithoutLyrics: 150,
  coveragePercentage: 25,
  bySource: { lrclib: 45, embedded: 5 },
}

const SYNC_STATUS: SyncStatus = {
  lastSyncAt: '2026-10-08T12:00:00+00:00',
  recentJobs: 12,
  completedJobs: 11,
  failedJobs: 1,
}

function answer(coverage: unknown, syncStatus: unknown) {
  mockGet.mockImplementation((url: string) => {
    if (url === '/api/admin/lyrics/coverage') {
      return Promise.resolve({ data: { data: coverage } })
    }
    if (url === '/api/admin/lyrics/sync-status') {
      return Promise.resolve({ data: { data: syncStatus } })
    }

    return Promise.reject(new Error(`Unexpected GET ${url}`))
  })
}

function mount() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })

  return render(
    <QueryClientProvider client={queryClient}>
      <LyricsAdminPage />
    </QueryClientProvider>,
  )
}

/** The value cell next to a row's label. */
function rowValue(label: string) {
  return screen.getByText(label).nextElementSibling?.textContent
}

describe('LyricsAdminPage', () => {
  beforeEach(() => {
    authState.roles = ['ROLE_ADMIN']
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
  })

  it('renders the jobs of the past 7 days and the lyrics per source', async () => {
    answer(COVERAGE, SYNC_STATUS)

    mount()

    expect(await screen.findByText('Jobs in the past 7 days')).toBeInTheDocument()
    expect(rowValue('Jobs in the past 7 days')).toBe('12')
    expect(rowValue('Completed jobs')).toBe('11')
    expect(rowValue('Failed jobs')).toBe('1')
    expect(screen.getByText('By source')).toBeInTheDocument()
    expect(rowValue('lrclib')).toBe('45')
    expect(rowValue('embedded')).toBe('5')
  })

  it('leaves out the source list while no lyrics are stored', async () => {
    // PHP encodes the empty source map as an empty JSON array.
    answer({ ...COVERAGE, tracksWithLyrics: 0, coveragePercentage: 0, bySource: [] }, { ...SYNC_STATUS, recentJobs: 0 })

    mount()

    expect(await screen.findByText('Jobs in the past 7 days')).toBeInTheDocument()
    expect(rowValue('Jobs in the past 7 days')).toBe('0')
    expect(screen.queryByText('By source')).not.toBeInTheDocument()
  })

  it('posts the bulk fetch without a body and shows how many songs were queued', async () => {
    authState.roles = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']
    answer(COVERAGE, SYNC_STATUS)
    mockPost.mockResolvedValue({ data: { data: { jobsEnqueued: 150 } } })

    mount()
    await userEvent.click(await screen.findByRole('button', { name: /Fetch Missing Lyrics/ }))

    expect(await screen.findByText('Enqueued 150 fetch jobs.')).toBeInTheDocument()
    expect(mockPost).toHaveBeenCalledWith('/api/admin/lyrics/bulk-fetch')
  })
})
