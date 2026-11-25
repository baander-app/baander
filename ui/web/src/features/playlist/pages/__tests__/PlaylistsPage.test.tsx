import { cleanup, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, AxiosHeaders } from 'axios'
import { toast } from 'sonner'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { customInstance } from '@/shared/api-client/axios-instance'
import type { PlaylistResource } from '@/shared/api-client/gen/endpoints'
import { render } from '../../../../../tests/test-utils'
import { PlaylistsPage } from '../PlaylistsPage'

const { mockGet } = vi.hoisted(() => ({ mockGet: vi.fn() }))

vi.mock('@/shared/api-client/axios-instance', () => ({
  customInstance: vi.fn(),
  AXIOS_INSTANCE: { get: mockGet },
}))

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

const mockRequest = vi.mocked(customInstance)
const initialAuthState = useAuthStore.getState()

const VIEWER = 'viewerPublicId0000001'
const FRIEND = 'friendPublicId0000001'

function playlist(overrides: Partial<PlaylistResource>): PlaylistResource {
  return {
    publicId: 'playlistPublicId00001',
    ownerPublicId: VIEWER,
    collaboratorPublicIds: [],
    name: 'Mine',
    description: null,
    isPublic: false,
    isCollaborative: false,
    isSmart: false,
    songCount: 0,
    createdAt: '2026-10-14T10:00:00+00:00',
    ...overrides,
  }
}

const MINE = playlist({ name: 'Mine' })
const THEIRS = playlist({
  publicId: 'playlistPublicId00002',
  ownerPublicId: FRIEND,
  collaboratorPublicIds: [VIEWER],
  name: 'Theirs',
})

/** Answers the playlist list a page at a time, as the server does. */
function servePlaylists(rows: PlaylistResource[]) {
  mockGet.mockReset()
  mockGet.mockImplementation(async (url: string, config?: { params?: { limit: number; offset: number } }) => {
    if (url !== '/api/playlists/') throw new Error(`Unexpected read ${url}`)
    const { limit, offset } = config?.params ?? { limit: 100, offset: 0 }

    return { data: { data: rows.slice(offset, offset + limit), meta: { total: rows.length, limit, offset } } }
  })
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <PlaylistsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('PlaylistsPage shared badge', () => {
  beforeEach(() => {
    mockRequest.mockReset()
    mockRequest.mockImplementation(async (url: string) => {
      throw new Error(`Unexpected request ${url}`)
    })
    servePlaylists([MINE, THEIRS])
    useAuthStore.setState({
      user: { publicId: VIEWER, email: 'viewer@baander.app', name: 'Viewer', roles: ['ROLE_USER'] },
      isAuthenticated: true,
    })
  })

  afterEach(() => {
    cleanup()
    useAuthStore.setState(initialAuthState, true)
  })

  it('marks a playlist shared with the viewer and leaves their own unmarked', async () => {
    renderPage()

    const theirs = await screen.findByRole('link', { name: /Theirs/ })
    const mine = screen.getByRole('link', { name: /Mine/ })
    expect(within(theirs).getByText('Shared with you')).toBeInTheDocument()
    expect(within(mine).queryByText('Shared with you')).not.toBeInTheDocument()
    expect(screen.getAllByText('Shared with you')).toHaveLength(1)
  })
})

describe('PlaylistsPage list', () => {
  afterEach(() => {
    cleanup()
  })

  it('lists the playlists of every page', async () => {
    const rows = Array.from({ length: 101 }, (_, index) => playlist({
      publicId: `playlistPublicId${String(index).padStart(5, '0')}`,
      name: `Playlist ${String(index + 1).padStart(3, '0')}`,
    }))
    servePlaylists(rows)
    renderPage()

    expect(await screen.findByRole('link', { name: /Playlist 101/ })).toBeInTheDocument()
    expect(mockGet.mock.calls.map(([, config]) => config.params)).toEqual([
      { limit: 100, offset: 0 },
      { limit: 100, offset: 100 },
    ])
  })
})

describe('PlaylistsPage create form', () => {
  beforeEach(() => {
    mockRequest.mockReset()
    vi.mocked(toast.error).mockReset()
    mockRequest.mockImplementation(async (url: string, options?: { method?: string }) => {
      if (url === '/api/playlists/' && options?.method === 'POST') {
        const config = { headers: new AxiosHeaders() }
        throw new AxiosError('Request failed with status code 409', 'ERR_BAD_REQUEST', config, undefined, {
          data: { error: { code: 409, message: 'An owner may keep at most 1000 playlists.', details: { reason: 'playlist_limit', limit: 1000 } } },
          status: 409,
          statusText: 'Conflict',
          headers: {},
          config,
        })
      }
      throw new Error(`Unexpected request ${url}`)
    })
    servePlaylists([MINE])
    useAuthStore.setState({
      user: { publicId: VIEWER, email: 'viewer@baander.app', name: 'Viewer', roles: ['ROLE_USER'] },
      isAuthenticated: true,
    })
  })

  afterEach(() => {
    cleanup()
    useAuthStore.setState(initialAuthState, true)
  })

  it('bounds the name and description and shows the server refusal', async () => {
    const user = userEvent.setup()
    renderPage()
    await screen.findByRole('link', { name: /Mine/ })

    await user.click(screen.getByRole('button', { name: 'Create playlist' }))
    const name = screen.getByPlaceholderText('Playlist name')
    expect(name).toHaveAttribute('maxLength', '255')
    expect(screen.getByPlaceholderText('Description (optional)')).toHaveAttribute('maxLength', '2000')
    await user.type(name, 'One too many')
    await user.click(screen.getByRole('button', { name: 'Create' }))

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith('An owner may keep at most 1000 playlists.'))
  })
})
