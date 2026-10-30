import { act, renderHook, waitFor } from '@testing-library/react'
import { QueryClientProvider } from '@tanstack/react-query'
import { describe, expect, it, vi } from 'vitest'
import { OwnedQueryClient } from '@/shared/live/query-client'
import { LiveQueryCoordinator } from '@/shared/live/query-coordinator'
import { catalogLiveDescriptors } from '@/features/catalog/live-descriptors'
import {
  useGetMovieIndex,
  useGetLyricsSongLyrics,
  useGetSongIndex,
  getGetSongIndexQueryKey,
  useGetAlbumIndex,
  useGetArtistIndex,
  useGetAlbumShow,
  useGetArtistShow,
} from '@/shared/api-client/gen/endpoints'
import { useForYouViewModel } from '@/features/catalog/hooks/use-for-you-view-model'
import { useDiscoverViewModel } from '@/features/catalog/hooks/use-discover-view-model'
import type { LiveSnapshot } from '@/shared/live/recovery'

const { read } = vi.hoisted(() => ({ read: vi.fn() }))
vi.mock('@/shared/api-client/axios-instance', () => ({ customInstance: read }))

describe('catalog consumers', () => {
  it('reconciles filtered music, movies, lyrics and derived recommendations while retaining inactive filters as stale', async () => {
    const { usePlayerStore } = await import('@/features/player/stores/player-store')
    const { updateTime, getCurrentTime } = await import('@/features/player/stores/player-time-tracker')
    const originalPlayer = usePlayerStore.getState()
    const track = { publicId: 'song', title: 'Playing', available: true }
    const audio = document.createElement('audio')
    audio.src = 'https://baander.app/api/stream/track?id=song'
    audio.currentTime = 37
    const play = vi.spyOn(audio, 'play')
    const pause = vi.spyOn(audio, 'pause')
    const load = vi.spyOn(audio, 'load')
    usePlayerStore.setState({
      queue: [track],
      currentTrack: track,
      currentIndex: 0,
      isPlaying: true,
      audioElement: audio,
    })
    updateTime(37)
    const playerBefore = usePlayerStore.getState()
    let revision = 'Before'
    read.mockImplementation(async (url: string) => {
      if (url.includes('/api/albums/album')) {
        return { data: { publicId: 'album', title: revision, coverImage: { url: revision, blurhash: revision } } }
      }
      if (url.includes('/api/artists/artist')) {
        return { data: { publicId: 'artist', name: revision } }
      }
      if (url.includes('/api/albums/?')) {
        return { data: [{ publicId: 'album', title: revision }] }
      }
      if (url.includes('/api/artists/?')) {
        return { data: [{ publicId: 'artist', name: revision }] }
      }
      if (url.includes('/api/songs/?')) {
        return { data: [{ publicId: 'song', title: revision, available: revision === 'After' }], meta: { total: revision === 'After' ? 12 : 1 } }
      }
      if (url.includes('/lyrics')) {
        return { data: { plainLyrics: revision } }
      }
      if (url.includes('/for-you')) {
        return { data: [{ song: { public_id: 'song', title: revision, available: revision === 'After' } }] }
      }
      if (url.includes('/recommendations')) {
        return { data: [{ public_id: 'rec', source_type: 'artist', source_id: 'artist', targetTitle: revision, sourceName: revision }] }
      }
      return { data: [{ publicId: 'movie', title: revision }] }
    })
    const client = new OwnedQueryClient()
    const snapshot: LiveSnapshot = {
      status: 'current',
      authorityReady: true,
      families: ['catalog', 'lyrics', 'recommendations'],
      authorizationFingerprint: 'a'.repeat(64),
      health: { state: 'current', oldestPendingAgeMs: null },
    }
    const recovery = {
      getSnapshot: () => snapshot,
      subscribe: () => () => undefined,
      subscribeEvents: () => () => undefined,
      completeRecovery: () => undefined,
      resourceReadFailed: vi.fn(),
    }
    const coordinator = new LiveQueryCoordinator(client, recovery, catalogLiveDescriptors)
    client.setCoordinator(coordinator)
    const stop = coordinator.start()
    const inactiveKey = ['/api/movies/', { search: 'inactive' }]
    const inactiveMusicKey = getGetSongIndexQueryKey({ q: 'inactive' })
    client.setQueryData(inactiveMusicKey, { data: [{ title: 'Old filter' }], meta: { total: 1 } })
    client.setQueryData(inactiveKey, { data: [{ title: 'Before' }] })
    const hook = renderHook(() => ({
      music: useGetSongIndex({ q: 'mounted' }),
      albums: useGetAlbumIndex({ q: 'mounted', limit: 8 }),
      artists: useGetArtistIndex({ q: 'mounted', limit: 5 }),
      album: useGetAlbumShow('album', { query: { enabled: true } }),
      artist: useGetArtistShow('artist', { query: { enabled: true } }),
      movies: useGetMovieIndex({ search: 'mounted' }),
      lyrics: useGetLyricsSongLyrics('song'),
      forYou: useForYouViewModel(),
      discover: useDiscoverViewModel(),
    }), { wrapper: ({ children }) => <QueryClientProvider client={client}>{children}</QueryClientProvider> })
    await waitFor(() => expect(hook.result.current.forYou.songs[0]?.title).toBe('Before'))
    expect(read.mock.calls.filter(([url]) => String(url).includes('/api/songs/?'))).toHaveLength(1)
    revision = 'After'
    act(() => coordinator.hint(['catalog', 'lyrics', 'recommendations']))
    await waitFor(() => expect(hook.result.current.forYou.songs[0]?.title).toBe('After'))
    expect(hook.result.current.forYou.songs[0].available).toBe(true)
    expect(hook.result.current.albums.data?.data).toEqual([{ publicId: 'album', title: 'After' }])
    expect(hook.result.current.artists.data?.data).toEqual([{ publicId: 'artist', name: 'After' }])
    expect(hook.result.current.album.data?.data?.title).toBe('After')
    expect(hook.result.current.album.data?.data?.coverImage?.url).toBe('After')
    expect(hook.result.current.artist.data?.data?.name).toBe('After')
    expect(hook.result.current.discover.clusters[0].sourceName).toBe('After')
    expect(hook.result.current.movies.data?.data[0].title).toBe('After')
    expect(hook.result.current.lyrics.data?.data).toEqual({ plainLyrics: 'After' })
    expect(hook.result.current.music.data?.data).toEqual([{ publicId: 'song', title: 'After', available: true }])
    expect(hook.result.current.music.data?.meta.total).toBe(12)
    expect(read.mock.calls.filter(([url]) => String(url).includes('/api/songs/?q=mounted'))).toHaveLength(2)
    expect(client.getQueryState(inactiveKey)?.isInvalidated).toBe(true)
    expect(client.getQueryState(inactiveMusicKey)?.isInvalidated).toBe(true)
    expect(read.mock.calls.filter(([url]) => String(url).includes('q=inactive'))).toHaveLength(0)
    const opened = renderHook(() => useGetSongIndex({ q: 'inactive' }), {
      wrapper: ({ children }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>,
    })
    await waitFor(() => expect(opened.result.current.data?.meta.total).toBe(12))
    expect(opened.result.current.data?.data).toEqual([{ publicId: 'song', title: 'After', available: true }])
    expect(read.mock.calls.filter(([url]) => String(url).includes('q=inactive'))).toHaveLength(1)
    const playerAfter = usePlayerStore.getState()
    expect(playerAfter.currentTrack).toBe(playerBefore.currentTrack)
    expect(playerAfter.queue).toBe(playerBefore.queue)
    expect(playerAfter.currentIndex).toBe(0)
    expect(playerAfter.isPlaying).toBe(true)
    expect(playerAfter.audioElement).toBe(audio)
    expect(audio.src).toBe('https://baander.app/api/stream/track?id=song')
    expect(audio.currentTime).toBe(37)
    expect(getCurrentTime()).toBe(37)
    expect(play).not.toHaveBeenCalled()
    expect(pause).not.toHaveBeenCalled()
    expect(load).not.toHaveBeenCalled()
    usePlayerStore.setState(originalPlayer, true)
    updateTime(0)
    opened.unmount()
    hook.unmount()
    stop()
    client.clear()
  })
})
