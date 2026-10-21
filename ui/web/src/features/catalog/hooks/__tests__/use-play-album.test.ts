import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest'
import { act, renderHook } from '@testing-library/react'
import MockAdapter from 'axios-mock-adapter'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { usePlayAlbum } from '../use-play-album'

const { playTrack } = vi.hoisted(() => ({ playTrack: vi.fn() }))
vi.mock('@/features/player/stores/player-store', () => ({
  usePlayerStore: (selector: (state: { playTrack: typeof playTrack }) => unknown) =>
    selector({ playTrack }),
}))
vi.mock('@/features/auth/stores/auth-store', () => ({
  useAuthStore: { getState: () => ({ accessToken: null, refreshToken: null }) },
}))
vi.mock('@/shared/crypto/dpop-store', () => ({
  getDpopKeyPair: () => null,
  getDpopNonce: () => null,
  setDpopNonce: vi.fn(),
}))
vi.mock('@/shared/crypto/dpop-proof', () => ({ createDpopProof: vi.fn() }))

describe('usePlayAlbum with the generated API client', () => {
  let api: MockAdapter

  beforeEach(() => {
    vi.clearAllMocks()
    api = new MockAdapter(AXIOS_INSTANCE, { onNoMatch: 'throwException' })
  })

  afterEach(() => api.restore())

  it('plays the first song with the album queue from the backend JSON body', async () => {
    api.onGet('/api/albums/album-1').reply(200, {
      data: {
        publicId: 'album-1',
        title: 'Album',
        songs: [
          { available: true, publicId: 'song-1', title: 'First', artistName: 'Artist', length: 180 },
          { available: true, publicId: 'song-2', title: 'Second', artistName: null, length: null },
        ],
      },
    })
    const { result } = renderHook(() => usePlayAlbum())

    await act(() => result.current.playAlbum('album-1', 'Album'))

    const tracks = [
      { available: true, publicId: 'song-1', title: 'First', artistName: 'Artist', duration: 180, albumName: 'Album', albumPublicId: 'album-1' },
      { available: true, publicId: 'song-2', title: 'Second', artistName: undefined, duration: undefined, albumName: 'Album', albumPublicId: 'album-1' },
    ]
    expect(playTrack).toHaveBeenCalledWith(tracks[0], tracks)
    expect(api.history.get).toHaveLength(1)
  })

  it('skips an unavailable first song and leaves all unavailable albums unplayed', async () => {
    api.onGet('/api/albums/mixed').reply(200, {
      data: { songs: [
        { publicId: 'missing', title: 'Missing', available: false },
        { publicId: 'present', title: 'Present', available: true },
      ] },
    })
    api.onGet('/api/albums/missing').reply(200, {
      data: { songs: [{ publicId: 'missing', title: 'Missing', available: false }] },
    })
    const { result } = renderHook(() => usePlayAlbum())

    await act(() => result.current.playAlbum('mixed', 'Mixed'))
    expect(playTrack).toHaveBeenCalledWith(
      expect.objectContaining({ publicId: 'present', available: true }),
      [expect.objectContaining({ publicId: 'present', available: true })],
    )
    playTrack.mockClear()
    await act(() => result.current.playAlbum('missing', 'Missing'))
    expect(playTrack).not.toHaveBeenCalled()
  })

  it.each([
    { status: 200, body: {} },
    { status: 200, body: { data: {} } },
    { status: 200, body: { data: { songs: [] } } },
    { status: 404, body: { error: 'Album not found' } },
  ])('keeps playback unchanged for an album request with no songs ($status)', async ({ status, body }) => {
    api.onGet('/api/albums/album-1').reply(status, body)
    const { result } = renderHook(() => usePlayAlbum())

    await act(() => result.current.playAlbum('album-1', 'Album'))

    expect(api.history.get).toHaveLength(1)
    expect(playTrack).not.toHaveBeenCalled()
  })
})
