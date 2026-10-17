import { cleanup, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { customInstance } from '@/shared/api-client/axios-instance'
import type { PlaylistResource } from '@/shared/api-client/gen/endpoints'
import { render } from '../../../../../tests/test-utils'
import { PlaylistsPage } from '../PlaylistsPage'

vi.mock('@/shared/api-client/axios-instance', () => ({
  customInstance: vi.fn(),
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
      if (url === '/api/playlists/') return { data: [MINE, THEIRS] }

      throw new Error(`Unexpected request ${url}`)
    })
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
