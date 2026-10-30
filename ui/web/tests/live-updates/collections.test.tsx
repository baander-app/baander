import type { ReactNode } from 'react'
import { act, cleanup, fireEvent, render, renderHook, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { TestThemeProvider } from '../TestThemeProvider'
import { CountryPicker } from '@/features/radio/components/CountryPicker'
import { PlaylistsPage } from '@/features/playlist/pages/PlaylistsPage'
import { PlaylistDetailPage } from '@/features/playlist/pages/PlaylistDetailPage'
import { SharePlaylistDialog } from '@/features/playlist/components/SharePlaylistDialog'
import { QueryClientProvider } from '@tanstack/react-query'
import MockAdapter from 'axios-mock-adapter'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/shared/crypto/dpop-store', () => ({
  getDpopKeyPair: () => null,
  getDpopNonce: () => null,
  setDpopNonce: vi.fn(),
}))

import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { OwnedQueryClient } from '@/shared/live/query-client'
import { LiveQueryCoordinator } from '@/shared/live/query-coordinator'
import { LiveCoordinatorContext } from '@/shared/live/context'
import { advanceAuthorization, synchronizeOwnership } from '@/shared/live/ownership'
import type { LiveSnapshot } from '@/shared/live/recovery'
import { playlistLiveDescriptors } from '@/features/playlist/live-descriptors'
import { radioLiveDescriptors } from '@/features/radio/live-descriptors'
import { catalogLiveDescriptors } from '@/features/catalog/live-descriptors'
import { useGetPlaylistIndex, useGetPlaylistShow, useGetLyricsSearch } from '@/shared/api-client/gen/endpoints'
import { usePostPlaylistStore, usePostPlaylistAddSong, usePatchPlaylistUpdate, useDeletePlaylistDestroy, useDeletePlaylistRemoveCollaborator } from '@/features/playlist/hooks/use-playlist-mutations'
import { useStations, useSubscriptions, useStarredStations, useStarStation, useUnstarStation } from '@/features/radio/hooks/use-radio-stations'
import { useStarredStations as useCatalogStars } from '@/features/catalog/hooks/use-starred-stations'
import { usePlayAlbum } from '@/features/catalog/hooks/use-play-album'
import { usePlayArtist } from '@/features/catalog/hooks/use-play-artist'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { MergeAlbumsDialog } from '@/features/catalog/components/MergeAlbumsDialog'
import { useMergeStore } from '@/features/catalog/stores/merge-store'
import { useAlbumDuplicates } from '@/features/catalog/api/album-duplicates-api'

let http: MockAdapter
const stops: (() => void)[] = []
beforeEach(() => {
  synchronizeOwnership('collections-reader')
  http = new MockAdapter(AXIOS_INSTANCE)
})
afterEach(() => {
  cleanup()
  stops.splice(0).forEach(stop => stop())
  http.restore()
  synchronizeOwnership(null)
  vi.useRealTimers()
})

function setup(coordinated = true) {
  const client = new OwnedQueryClient()
  client.setDefaultOptions({ queries: { retry: false, staleTime: Infinity } })
  const snapshot: LiveSnapshot = {
    status: 'current', authorityReady: true,
    families: ['playlists', 'radio', 'catalog'],
    authorizationFingerprint: 'a'.repeat(64),
    health: { state: 'current', oldestPendingAgeMs: null },
  }
  const coordinator = new LiveQueryCoordinator(client, {
    getSnapshot: () => snapshot,
    subscribe: () => () => undefined,
    subscribeEvents: () => () => undefined,
    completeRecovery: () => undefined,
    resourceReadFailed: () => undefined,
  }, [...playlistLiveDescriptors, ...radioLiveDescriptors, ...catalogLiveDescriptors])
  if (coordinated) client.setCoordinator(coordinator)
  const stop = coordinated ? coordinator.start() : () => undefined
  stops.push(() => { stop(); client.dispose() })
  function wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>
      <LiveCoordinatorContext value={coordinated ? coordinator : null}>{children}</LiveCoordinatorContext>
    </QueryClientProvider>
  }
  return { client, coordinator, wrapper }
}

describe('personal collection freshness', () => {
  it.each([true, false])('converges every playlist key after local create, rename, collaboration removal and delete without socket delivery (coordinator %s)', async coordinated => {
    let name = 'Before'
    let exists = true
    let shared = true
    let songCount = 0
    const rows = () => exists ? [{ publicId: 'mix', name, songCount, collaboratorPublicIds: shared ? ['friend'] : [] }] : []
    http.onGet('/api/playlists/').reply(() => [200, { data: rows() }])
    http.onGet('/api/playlists/mix').reply(() => [200, { data: rows()[0] ?? null }])
    http.onPost('/api/playlists/mix/songs').reply(() => { songCount++; return [201, {}] })
    http.onPost('/api/playlists/').reply(() => { name = 'Created'; return [201, { publicId: 'mix' }] })
    http.onPatch('/api/playlists/mix').reply(() => { name = 'Renamed'; return [200, { publicId: 'mix' }] })
    http.onDelete('/api/playlists/mix/collaborators/friend').reply(() => { shared = false; return [204] })
    http.onDelete('/api/playlists/mix').reply(() => { exists = false; return [204] })
    const { client, wrapper } = setup(coordinated)
    const inactive = ['/api/playlists/', { filter: 'inactive' }]
    client.setQueryData(inactive, { data: [{ name: 'Inactive old' }] })
    const hook = renderHook(() => ({
      index: useGetPlaylistIndex(),
      variant: useGetPlaylistIndex({ query: { queryKey: ['/api/playlists/', { filter: 'mounted' }] } }),
      detail: useGetPlaylistShow('mix'),
      create: usePostPlaylistStore(), addSong: usePostPlaylistAddSong(), update: usePatchPlaylistUpdate(),
      remove: useDeletePlaylistDestroy(), collaborator: useDeletePlaylistRemoveCollaborator(),
    }), { wrapper })
    await waitFor(() => expect(hook.result.current.detail.data?.data?.name).toBe('Before'))
    await act(() => hook.result.current.create.mutateAsync({ data: { name: 'Created', isSmart: false, isPublic: false, isCollaborative: false, smartRules: [] } }))
    await waitFor(() => expect(hook.result.current.variant.data?.data?.[0]?.name).toBe('Created'))
    await act(() => hook.result.current.update.mutateAsync({ publicId: 'mix', data: { name: 'Renamed' } }))
    await waitFor(() => expect(hook.result.current.detail.data?.data?.name).toBe('Renamed'))
    expect(hook.result.current.index.data?.data?.[0]?.name).toBe('Renamed')
    await act(() => hook.result.current.addSong.mutateAsync({ publicId: 'mix', data: { songId: 'song' } }))
    await waitFor(() => expect(hook.result.current.detail.data?.data?.songCount).toBe(1))
    expect(hook.result.current.variant.data?.data?.[0]?.songCount).toBe(1)
    await act(() => hook.result.current.collaborator.mutateAsync({ publicId: 'mix', userPublicId: 'friend' }))
    await waitFor(() => expect(hook.result.current.detail.data?.data?.collaboratorPublicIds).toEqual([]))
    await act(() => hook.result.current.remove.mutateAsync({ publicId: 'mix' }))
    await waitFor(() => expect(hook.result.current.index.data?.data).toEqual([]))
    expect(hook.result.current.variant.data?.data).toEqual([])
    expect(hook.result.current.detail.data?.data).toBeNull()
    expect(client.getQueryState(inactive)?.isInvalidated).toBe(true)
  })

  it('refreshes radio and catalog stars together, imports stations and cancels actual handwritten reads', async () => {
    let starred = false
    let name = 'Before'
    const station = () => ({ publicId: 'station', name, streams: [] })
    http.onGet('/api/radio/stations').reply(() => [200, { data: [station()] }])
    http.onGet('/api/radio/subscriptions').reply(200, { data: [] })
    http.onGet('/api/radio/starred').reply(() => [200, { data: starred ? [{ stationId: 'station' }] : [] }])
    http.onPost('/api/radio/starred').reply(() => { starred = true; return [201, { stationId: 'station' }] })
    http.onDelete('/api/radio/starred/station').reply(() => { starred = false; return [204] })
    const { coordinator, wrapper } = setup()
    const hook = renderHook(() => ({
      stations: useStations('DK', '', true), subscriptions: useSubscriptions(),
      stars: useStarredStations(), catalog: useCatalogStars(),
      star: useStarStation(), unstar: useUnstarStation(),
    }), { wrapper })
    await waitFor(() => expect(hook.result.current.stations.data?.[0]?.name).toBe('Before'))
    await act(() => hook.result.current.star.mutateAsync('station'))
    await waitFor(() => expect(hook.result.current.catalog.data?.[0]?.name).toBe('Before'))
    expect(hook.result.current.stars.data).toEqual([{ stationId: 'station' }])
    name = 'Imported'
    act(() => coordinator.hint(['radio']))
    await waitFor(() => expect(hook.result.current.catalog.data?.[0]?.name).toBe('Imported'))
    expect(hook.result.current.stations.data?.[0]?.name).toBe('Imported')
    await act(() => hook.result.current.unstar.mutateAsync('station'))
    await waitFor(() => expect(hook.result.current.catalog.data).toEqual([]))
    expect(http.history.post.map(request => request.url)).toEqual(['/api/radio/starred'])
    expect(http.history.get.every(request => request.signal !== undefined)).toBe(true)
    let signal: AbortSignal | undefined
    http.onGet('/api/radio/stations').reply(config => {
      signal = config.signal as AbortSignal
      return new Promise(() => undefined)
    })
    act(() => { coordinator.hint(['radio']) })
    await waitFor(() => expect(signal).toBeDefined())
    hook.unmount()
    expect(signal?.aborted).toBe(true)
  })

  it('refreshes duplicate groups without invoking merge or playback commands', async () => {
    let groups: unknown[] = [{ publicId: 'duplicate' }]
    http.onGet('/api/albums/album/duplicates').reply(() => [200, { data: groups }])
    const { coordinator, wrapper } = setup()
    const hook = renderHook(() => useAlbumDuplicates('album'), { wrapper })
    await waitFor(() => expect(hook.result.current.data).toEqual(groups))
    groups = []
    act(() => coordinator.hint(['catalog']))
    await waitFor(() => expect(hook.result.current.data).toEqual([]))
    expect(http.history.post).toHaveLength(0)
    expect(http.history.get.every(request => request.signal !== undefined)).toBe(true)
  })

  it('retains creation edits made while the previous playlist submission is pending', async () => {
    let finish: (() => void) | undefined
    http.onGet('/api/playlists/').reply(200, { data: [] })
    http.onPost('/api/playlists/').reply(() => new Promise(resolve => { finish = () => resolve([201, { publicId: 'mix' }]) }))
    const { wrapper: Wrapper } = setup()
    render(<Wrapper><TestThemeProvider><MemoryRouter><PlaylistsPage /></MemoryRouter></TestThemeProvider></Wrapper>)
    fireEvent.click(screen.getByRole('button', { name: 'Create playlist' }))
    const input = screen.getByPlaceholderText('Playlist name')
    fireEvent.change(input, { target: { value: 'Submitted' } })
    fireEvent.click(screen.getByRole('button', { name: 'Create' }))
    await waitFor(() => expect(finish).toBeDefined())
    fireEvent.change(input, { target: { value: 'New draft' } })
    await act(async () => { finish?.() })
    await waitFor(() => expect(screen.getByPlaceholderText('Playlist name')).toHaveValue('New draft'))
  })

  it('rereads subscription toggles authoritatively and never starts or stops playback', async () => {
    let subscribed = false
    http.onGet('/api/radio/countries').reply(200, { data: [{ code: 'DK', name: 'Denmark', station_count: 3 }] })
    http.onGet('/api/radio/subscriptions').reply(() => [200, { data: subscribed ? [{ publicId: 'server-normalized', sourceId: 'source', countryCode: 'DK' }] : [] }])
    http.onPost('/api/radio/subscriptions').reply(() => { subscribed = true; return [201, { publicId: 'command-response', countryCode: 'DK' }] })
    const { client, wrapper: Wrapper } = setup()
    render(<Wrapper><TestThemeProvider><CountryPicker /></TestThemeProvider></Wrapper>)
    fireEvent.click(await screen.findByRole('button', { name: /Denmark/ }))
    await waitFor(() => expect(client.getQueryData(['radio', 'subscriptions'])).toEqual([
      { publicId: 'server-normalized', sourceId: 'source', countryCode: 'DK' },
    ]))
    expect(http.history.get.filter(request => request.url === '/api/radio/subscriptions').length).toBeGreaterThan(1)
    expect(http.history.post.map(request => request.url)).toEqual(['/api/radio/subscriptions'])
  })

  it('removes a denied playlist snapshot from mounted detail and open sharing controls', async () => {
    let denied = false
    http.onGet('/api/playlists/mix').reply(() => denied
      ? [403, { message: 'No longer shared' }]
      : [200, { data: { publicId: 'mix', name: 'Shared mix', ownerPublicId: 'owner', collaboratorPublicIds: ['friend'], songs: [] } }])
    const { client, coordinator, wrapper: Wrapper } = setup()
    render(<Wrapper><TestThemeProvider><MemoryRouter initialEntries={['/music/playlists/mix']}>
      <Routes><Route path="/music/playlists/:publicId" element={<><PlaylistDetailPage /><SharePlaylistDialog playlistPublicId="mix" /></>} /></Routes>
    </MemoryRouter></TestThemeProvider></Wrapper>)
    await screen.findByText('Shared mix')
    fireEvent.click(screen.getAllByRole('button', { name: /Share/ })[0])
    await screen.findByText('friend')
    vi.useFakeTimers()
    denied = true
    await act(async () => {
      coordinator.hint(['playlists'])
      await vi.advanceTimersByTimeAsync(500)
    })
    expect(screen.getByText('This playlist is no longer available.')).toBeInTheDocument()
    expect(screen.queryByText('Shared mix')).toBeNull()
    expect(screen.queryByText('friend')).toBeNull()
    const key = ['/api/playlists/mix']
    expect(client.getQueryState(key)).toMatchObject({ data: undefined, status: 'error', error: { response: { status: 403 } } })
    const reads = http.history.get.length
    await act(async () => { await vi.advanceTimersByTimeAsync(40_000) })
    expect(http.history.get).toHaveLength(reads)
    expect(screen.getByText('This playlist is no longer available.')).toBeInTheDocument()
    denied = false
    await act(async () => {
      coordinator.hint(['playlists'])
      await vi.advanceTimersByTimeAsync(500)
    })
    expect(http.history.get).toHaveLength(reads + 1)
    expect(client.getQueryState(key)).toMatchObject({ status: 'success', error: null })
    expect(screen.getByText('Shared mix')).toBeInTheDocument()
    expect(screen.getByText('friend')).toBeInTheDocument()
    expect(http.history.post).toHaveLength(0)
    expect(http.history.put).toHaveLength(0)
    expect(http.history.delete).toHaveLength(0)
  })

  it('reconciles merged duplicates and playlist metadata through scoped families', async () => {
    let merged = false
    http.onGet('/api/albums/album/duplicates').reply(() => [200, { data: merged ? [] : [{ publicId: 'duplicate' }] }])
    http.onGet('/api/playlists/').reply(() => [200, { data: [{ name: merged ? 'Merged metadata' : 'Before' }] }])
    http.onPost('/api/albums/merge').reply(() => { merged = true; return [200, {}] })
    const { wrapper: Wrapper, wrapper } = setup()
    const hook = renderHook(() => ({ duplicates: useAlbumDuplicates('album'), playlists: useGetPlaylistIndex() }), { wrapper })
    await waitFor(() => expect(hook.result.current.duplicates.data).toHaveLength(1))
    useMergeStore.getState().openMerge('source', 'Source', 'album', 'Target')
    render(<Wrapper><TestThemeProvider><MergeAlbumsDialog /></TestThemeProvider></Wrapper>)
    fireEvent.click(screen.getByRole('button', { name: 'Merge Albums' }))
    await waitFor(() => expect(hook.result.current.duplicates.data).toEqual([]))
    expect(hook.result.current.playlists.data?.data?.[0]?.name).toBe('Merged metadata')
    expect(http.history.post).toHaveLength(1)
    expect(useMergeStore.getState().isOpen).toBe(false)
  })

  it('retains external lyrics search as an explicit demand read through unrelated live hints', async () => {
    http.onGet('/api/lyrics/search?q=Song').reply(200, { data: [] })
    const { coordinator, wrapper } = setup()
    const hook = renderHook(() => useGetLyricsSearch({ q: 'Song' }, { query: { enabled: false } }), { wrapper })
    act(() => coordinator.hint(['lyrics', 'catalog']))
    await new Promise(resolve => setTimeout(resolve, 40))
    expect(http.history.get).toHaveLength(0)
    await act(() => hook.result.current.refetch())
    expect(http.history.get).toHaveLength(1)
    act(() => coordinator.hint(['lyrics']))
    await new Promise(resolve => setTimeout(resolve, 40))
    expect(http.history.get).toHaveLength(1)
  })

  it('never replays demand playback on hints and fences delayed explicit commands after authorization changes', async () => {
    const original = usePlayerStore.getState()
    const playTrack = vi.fn()
    usePlayerStore.setState({ playTrack })
    try {
      let finish: (() => void) | undefined
      http.onGet('/api/albums/album').reply(() => new Promise(resolve => {
        finish = () => resolve([200, { data: { songs: [{ publicId: 'song', title: 'Song', available: true }] } }])
      }))
      const { coordinator, wrapper } = setup()
      const hook = renderHook(() => ({ album: usePlayAlbum(), artist: usePlayArtist() }), { wrapper })
      act(() => coordinator.hint(['catalog', 'playlists', 'radio']))
      await new Promise(resolve => setTimeout(resolve, 40))
      expect(http.history.get).toHaveLength(0)
      expect(playTrack).not.toHaveBeenCalled()
      let pending: Promise<void> = Promise.resolve()
      act(() => { pending = hook.result.current.album.playAlbum('album', 'Album') })
      await waitFor(() => expect(finish).toBeDefined())
      act(() => advanceAuthorization())
      await act(async () => { finish?.(); await pending })
      expect(playTrack).not.toHaveBeenCalled()
      http.onGet('/api/songs').reply(200, { data: [{ publicId: 'song', title: 'Song', available: true }] })
      await act(() => hook.result.current.artist.playArtist('artist', 'Artist'))
      expect(playTrack).toHaveBeenCalledTimes(1)
      act(() => coordinator.hint(['catalog']))
      await new Promise(resolve => setTimeout(resolve, 40))
      expect(playTrack).toHaveBeenCalledTimes(1)
      expect(http.history.get).toHaveLength(2)
    } finally {
      usePlayerStore.setState(original, true)
    }
  })

  it('releases a stale authorization mutation lease even when old callbacks are suppressed', async () => {
    let finish: (() => void) | undefined
    http.onPost('/api/playlists/').reply(() => new Promise(resolve => { finish = () => resolve([201, { publicId: 'mix' }]) }))
    http.onGet('/api/playlists/').reply(200, { data: [{ name: 'Current' }] })
    const { coordinator, wrapper } = setup()
    const hook = renderHook(() => ({ list: useGetPlaylistIndex(), create: usePostPlaylistStore() }), { wrapper })
    await waitFor(() => expect(hook.result.current.list.data?.data?.[0]?.name).toBe('Current'))
    let pending: Promise<unknown> = Promise.resolve()
    act(() => { pending = hook.result.current.create.mutateAsync({ data: { name: 'A' } }).catch(error => error) })
    await waitFor(() => expect(finish).toBeDefined())
    act(() => advanceAuthorization())
    await act(async () => { finish?.(); await pending })
    act(() => coordinator.hint(['playlists']))
    await waitFor(() => expect(http.history.get.length).toBeGreaterThan(1))
    expect(hook.result.current.list.data?.data?.[0]?.name).toBe('Current')
  })
})
