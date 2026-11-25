import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { renderHook, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import MockAdapter from 'axios-mock-adapter'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { render } from '../../../../../tests/test-utils'
import { AddToPlaylistDialog } from '../../components/AddToPlaylistDialog'
import { usePlaylistIndex } from '../use-playlist-index'

vi.mock('@/features/auth/stores/auth-store', () => ({
  useAuthStore: { getState: () => ({ accessToken: null, refreshToken: null }) },
}))
vi.mock('@/shared/crypto/dpop-store', () => ({
  getDpopKeyPair: () => null,
  getDpopNonce: () => null,
  setDpopNonce: vi.fn(),
}))
vi.mock('@/shared/crypto/dpop-proof', () => ({ createDpopProof: vi.fn() }))

interface PlaylistRow {
  publicId: string
  name: string
  isSmart: boolean
}

/** 101 playlists, so the server's largest page of 100 leaves one for a second page. */
const PLAYLISTS: PlaylistRow[] = Array.from({ length: 101 }, (_, index) => ({
  publicId: `playlist${String(index).padStart(13, '0')}`,
  name: `Playlist ${String(index + 1).padStart(3, '0')}`,
  isSmart: false,
}))

function client(): QueryClient {
  return new QueryClient({ defaultOptions: { queries: { retry: false } } })
}

describe('usePlaylistIndex', () => {
  let api: MockAdapter

  beforeEach(() => {
    api = new MockAdapter(AXIOS_INSTANCE, { onNoMatch: 'throwException' })
    api.onGet('/api/playlists/').reply((config) => {
      const { limit, offset } = config.params as { limit: number; offset: number }

      return [200, {
        data: PLAYLISTS.slice(offset, offset + limit),
        meta: { total: PLAYLISTS.length, limit, offset },
      }]
    })
  })

  afterEach(() => api.restore())

  it('reads every page of the playlist list under the generated index key', async () => {
    const queryClient = client()
    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    )

    const { result } = renderHook(() => usePlaylistIndex(), { wrapper })

    await waitFor(() => expect(result.current.data?.data).toHaveLength(101))
    expect(api.history.get.map((request) => request.params)).toEqual([
      { limit: 100, offset: 0 },
      { limit: 100, offset: 100 },
    ])
    expect(queryClient.getQueryData(['/api/playlists/'])).toEqual({ data: PLAYLISTS })
  })

  it('lets the add-to-playlist dialog find a playlist on the second page', async () => {
    render(
      <QueryClientProvider client={client()}>
        <AddToPlaylistDialog open onOpenChange={vi.fn()} songId="song_1" />
      </QueryClientProvider>,
    )

    expect(await screen.findByText('Playlist 101')).toBeInTheDocument()
  })
})
