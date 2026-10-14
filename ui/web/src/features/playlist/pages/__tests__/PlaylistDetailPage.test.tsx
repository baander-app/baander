import { cleanup, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { customInstance } from '@/shared/api-client/axios-instance'
import type { GetPlaylistShow200Data } from '@/shared/api-client/gen/endpoints'
import { render } from '../../../../../tests/test-utils'
import { PlaylistDetailPage } from '../PlaylistDetailPage'

vi.mock('@/shared/api-client/axios-instance', () => ({
  customInstance: vi.fn(),
}))

const mockRequest = vi.mocked(customInstance)
const initialAuthState = useAuthStore.getState()

const OWNER = 'ownerPublicId00000001'
const COLLABORATOR = 'collabPublicId0000001'
const STRANGER = 'viewerPublicId0000001'
const PLAYLIST = 'playlistPublicId00001'
const PLAYLIST_URL = `/api/playlists/${PLAYLIST}`

let served: GetPlaylistShow200Data

function playlist(overrides: Partial<GetPlaylistShow200Data> = {}): GetPlaylistShow200Data {
  return {
    publicId: PLAYLIST,
    ownerPublicId: OWNER,
    collaboratorPublicIds: [COLLABORATOR],
    name: 'Road trip',
    description: 'Songs for the drive',
    isPublic: false,
    isCollaborative: true,
    isSmart: false,
    songCount: 0,
    createdAt: '2026-10-14T10:00:00+00:00',
    songs: [],
    ...overrides,
  }
}

function serve() {
  mockRequest.mockImplementation(async (url: string, options: RequestInit) => {
    if (url === PLAYLIST_URL && options.method === 'GET') return { data: served }
    if (url === `${PLAYLIST_URL}/collaborators/${COLLABORATOR}` && options.method === 'DELETE') {
      served = { ...served, collaboratorPublicIds: [] }

      return undefined
    }

    throw new Error(`Unexpected request ${options.method} ${url}`)
  })
}

function signInAs(publicId: string) {
  useAuthStore.setState({
    user: { uuid: '0199c0de-7600-7000-8000-0000000000a1', publicId, email: 'member@baander.app', name: 'Member', roles: ['ROLE_USER'] },
    isAuthenticated: true,
  })
}

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[`/music/playlists/${PLAYLIST}`]}>
        <Routes>
          <Route path="/music/playlists" element={<h1>All playlists</h1>} />
          <Route path="/music/playlists/:publicId" element={<PlaylistDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )

  return client
}

describe('PlaylistDetailPage sharing controls', () => {
  beforeEach(() => {
    served = playlist()
    mockRequest.mockReset()
    serve()
  })

  afterEach(() => {
    cleanup()
    useAuthStore.setState(initialAuthState, true)
  })

  it('offers the owner the share dialog and no leave action', async () => {
    signInAs(OWNER)
    renderPage()

    expect(await screen.findByRole('button', { name: 'Share' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Leave playlist' })).not.toBeInTheDocument()
  })

  it('lets a collaborator leave: their own row is deleted and the list page opens', async () => {
    signInAs(COLLABORATOR)
    const user = userEvent.setup()
    const client = renderPage()
    const invalidate = vi.spyOn(client, 'invalidateQueries')

    await user.click(await screen.findByRole('button', { name: 'Leave playlist' }))

    expect(await screen.findByRole('heading', { name: 'All playlists' })).toBeInTheDocument()
    const deletes = mockRequest.mock.calls.filter(([, options]) => options.method === 'DELETE')
    expect(deletes).toHaveLength(1)
    expect(deletes[0][0]).toBe(`${PLAYLIST_URL}/collaborators/${COLLABORATOR}`)
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['/api/playlists/'] })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: [PLAYLIST_URL] })
    expect(screen.queryByRole('button', { name: 'Share' })).not.toBeInTheDocument()
  })

  it('shows a collaborator no share dialog', async () => {
    signInAs(COLLABORATOR)
    renderPage()

    await screen.findByRole('button', { name: 'Leave playlist' })
    expect(screen.queryByRole('button', { name: 'Share' })).not.toBeInTheDocument()
  })

  it('shows an unrelated viewer neither control', async () => {
    signInAs(STRANGER)
    renderPage()

    expect(await screen.findByRole('heading', { name: 'Road trip' })).toBeInTheDocument()
    await waitFor(() => expect(mockRequest).toHaveBeenCalled())
    expect(screen.queryByRole('button', { name: 'Share' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Leave playlist' })).not.toBeInTheDocument()
  })
})
