import type { ReactNode } from 'react'
import { act, renderHook, waitFor } from '@testing-library/react'
import { QueryClientProvider } from '@tanstack/react-query'
import MockAdapter from 'axios-mock-adapter'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/features/auth/stores/auth-store', () => ({
  useAuthStore: { getState: () => ({ accessToken: null, refreshToken: null }) },
}))
vi.mock('@/shared/crypto/dpop-store', () => ({
  getDpopKeyPair: () => null,
  getDpopNonce: () => null,
  setDpopNonce: vi.fn(),
}))

import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { libraryKeys } from '@/features/library/query-keys'
import { useLibraries } from '@/features/library/hooks/use-libraries'
import { useLibrary } from '@/features/library/hooks/use-library'
import { useLibraryStats } from '@/features/library/hooks/use-library-stats'
import { useScanPolling } from '@/features/library/hooks/use-scan-polling'
import { useUpdateLibrary } from '@/features/library/hooks/use-library-mutations'
import { libraryLiveDescriptors } from '@/features/library/live-descriptors'
import { LiveCoordinatorContext } from '@/shared/live/context'
import { OwnedQueryClient } from '@/shared/live/query-client'
import { LiveQueryCoordinator } from '@/shared/live/query-coordinator'
import { advanceAuthorization, synchronizeOwnership } from '@/shared/live/ownership'
import type { LiveSnapshot } from '@/shared/live/recovery'

let http: MockAdapter
const cleanups: (() => void)[] = []
beforeEach(() => {
  synchronizeOwnership('library-reader')
  http = new MockAdapter(AXIOS_INSTANCE)
})
afterEach(() => {
  cleanups.splice(0).forEach(cleanup => cleanup())
  http.restore()
  synchronizeOwnership(null)
})

function setup() {
  const client = new OwnedQueryClient()
  client.setDefaultOptions({ queries: { retry: false, staleTime: Infinity } })
  const snapshot: LiveSnapshot = {
    status: 'current',
    authorityReady: true,
    families: ['libraries'],
    authorizationFingerprint: 'a'.repeat(64),
    health: { state: 'current', oldestPendingAgeMs: null },
  }
  const coordinator = new LiveQueryCoordinator(client, {
    getSnapshot: () => snapshot,
    subscribe: () => () => undefined,
    subscribeEvents: () => () => undefined,
    completeRecovery: () => undefined,
    resourceReadFailed: () => undefined,
  }, libraryLiveDescriptors)
  client.setCoordinator(coordinator)
  const stop = coordinator.start()
  cleanups.push(() => {
    stop()
    client.dispose()
  })
  function wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>
      <LiveCoordinatorContext value={coordinator}>{children}</LiveCoordinatorContext>
    </QueryClientProvider>
  }
  return { client, coordinator, wrapper }
}

const music = { publicId: 'music', name: 'Music', type: 'music', scanStatus: 'scanning' }

describe('library authoritative live readers', () => {
  it('refreshes mounted filtered lists, details and counts and retains inactive filters until opened', async () => {
    let name = 'Before'
    let songs = 1
    http.onGet('/api/libraries').reply(config => [200, { data: [{ ...music, name, type: config.params?.type }] }])
    http.onGet('/api/libraries/music').reply(() => [200, { data: { ...music, name } }])
    http.onGet('/api/libraries/music/stats').reply(() => [200, { data: { songs } }])
    const { client, coordinator, wrapper } = setup()
    client.setQueryData(libraryKeys.list('movie'), [{ name: 'Old movie filter' }])
    const hook = renderHook(() => ({
      list: useLibraries('music'),
      detail: useLibrary('music'),
      stats: useLibraryStats('music'),
    }), { wrapper })
    await waitFor(() => expect(hook.result.current.stats.data?.songs).toBe(1))
    name = 'After'
    songs = 12
    act(() => coordinator.hint(['libraries']))
    await waitFor(() => expect(hook.result.current.list.data?.[0].name).toBe('After'))
    expect(hook.result.current.detail.data?.name).toBe('After')
    expect(hook.result.current.stats.data?.songs).toBe(12)
    expect(client.getQueryState(libraryKeys.list('movie'))?.isInvalidated).toBe(true)
    expect(http.history.get.filter(request => request.params?.type === 'movie')).toHaveLength(0)
    const movies = renderHook(() => useLibraries('movie'), { wrapper })
    await waitFor(() => expect(movies.result.current.data?.[0].name).toBe('After'))
    hook.unmount()
    movies.unmount()
  })

  it('shares the list with the scan fallback and cancels its actual HTTP read on unmount', async () => {
    http.onGet('/api/libraries').reply(200, { data: [music] })
    const { wrapper } = setup()
    const hook = renderHook(() => ({
      list: useLibraries(),
      scan: useScanPolling(),
    }), { wrapper })
    await waitFor(() => expect(hook.result.current.list.data?.[0].name).toBe('Music'))
    act(() => hook.result.current.scan.startPolling())
    await waitFor(() => expect(hook.result.current.scan.scanningIds.has('music')).toBe(true))
    expect(http.history.get).toHaveLength(1)
    let signal: AbortSignal | undefined
    http.onGet('/api/libraries').reply(config => {
      signal = config.signal as AbortSignal
      return new Promise(() => undefined)
    })
    act(() => {
      hook.result.current.list.refetch().catch(() => undefined)
    })
    await waitFor(() => expect(signal).toBeDefined())
    hook.unmount()
    expect(signal?.aborted).toBe(true)
  })

  it('holds remote refresh until a write settles and fences callbacks after permission loss', async () => {
    http.onGet('/api/libraries').reply(200, { data: [music] })
    let finish!: (response: [number, object]) => void
    http.onPatch('/api/libraries/music').reply(() => new Promise(resolve => {
      finish = resolve
    }))
    const { coordinator, wrapper } = setup()
    const hook = renderHook(() => ({
      list: useLibraries(),
      update: useUpdateLibrary(),
    }), { wrapper })
    await waitFor(() => expect(hook.result.current.list.data).toHaveLength(1))
    const onSuccess = vi.fn()
    act(() => hook.result.current.update.mutate({ id: 'music', payload: { name: 'New' } }, { onSuccess }))
    await waitFor(() => expect(finish).toBeDefined())
    act(() => coordinator.hint(['libraries']))
    await new Promise(resolve => setTimeout(resolve, 650))
    expect(http.history.get).toHaveLength(1)
    act(() => advanceAuthorization())
    await act(async () => finish([200, { data: music }]))
    await waitFor(() => expect(hook.result.current.update.isPending).toBe(false))
    expect(onSuccess).not.toHaveBeenCalled()
    hook.unmount()
  })
})
