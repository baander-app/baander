import { renderHook } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { mockGetSongIndex, mockPlayTrack } = vi.hoisted(() => ({
  mockGetSongIndex: vi.fn(),
  mockPlayTrack: vi.fn(),
}))

vi.mock('@/shared/api-client/gen/endpoints', () => ({ getSongIndex: mockGetSongIndex }))
vi.mock('@/features/player/stores/player-store', () => ({
  usePlayerStore: (select: (state: { playTrack: typeof mockPlayTrack }) => unknown) =>
    select({ playTrack: mockPlayTrack }),
}))

import { usePlayArtist } from '../use-play-artist'

interface SongPage {
  data: { publicId: string; title: string; available: true }[]
  meta: { has_next_page: false }
}

function page(artist: string): SongPage {
  return {
    data: [{ publicId: `${artist}-song`, title: `${artist} song`, available: true }],
    meta: { has_next_page: false },
  }
}

function deferredPages(): Map<string, (page: SongPage) => void> {
  const resolvers = new Map<string, (page: SongPage) => void>()
  mockGetSongIndex.mockImplementation(({ artistId }: { artistId: string }) =>
    new Promise<SongPage>((resolve) => {
      resolvers.set(artistId, resolve)
    }))
  return resolvers
}

describe('usePlayArtist', () => {
  beforeEach(() => {
    mockGetSongIndex.mockReset()
    mockPlayTrack.mockReset()
  })

  it('plays the artist asked for last when an earlier read finishes after it', async () => {
    const resolvers = deferredPages()
    const { result } = renderHook(() => usePlayArtist())

    const first = result.current.playArtist('first', 'First')
    const second = result.current.playArtist('second', 'Second')
    resolvers.get('second')?.(page('second'))
    await second
    resolvers.get('first')?.(page('first'))
    await first

    expect(mockPlayTrack).toHaveBeenCalledTimes(1)
    expect(mockPlayTrack).toHaveBeenCalledWith(
      expect.objectContaining({ publicId: 'second-song' }),
      [expect.objectContaining({ publicId: 'second-song' })],
    )
  })
})
