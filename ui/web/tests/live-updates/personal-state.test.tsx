import { act, cleanup, renderHook, waitFor } from '@testing-library/react'
import { QueryClientProvider } from '@tanstack/react-query'
import MockAdapter from 'axios-mock-adapter'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { OwnedQueryClient } from '@/shared/live/query-client'
import { LiveQueryCoordinator } from '@/shared/live/query-coordinator'
import { LiveCoordinatorContext } from '@/shared/live/context'
import { ThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { getDeviceId } from '@/features/session/utils/device-id'
import { notificationLiveDescriptors } from '@/features/notification/live-descriptors'
import { sessionLiveDescriptors } from '@/features/session/live-descriptors'
import { useNotifications } from '@/features/notification/hooks/use-notifications'
import { useNotificationActions } from '@/features/notification/hooks/use-notification-actions'
import { useNotificationStore } from '@/features/notification/stores/notification-store'
import { synchronizeOwnership } from '@/shared/live/ownership'
import { useGetDeviceList, getGetDeviceListQueryKey } from '@/shared/api-client/gen/endpoints'
import type { LiveSnapshot } from '@/shared/live/recovery'

vi.mock('@/features/auth/stores/auth-store', () => ({
  useAuthStore: Object.assign((selector: (state: { isAuthenticated: boolean }) => unknown) => selector({ isAuthenticated: true }), { getState: () => ({ accessToken: null, refreshToken: null }) }),
}))
vi.mock('@/shared/crypto/dpop-store', () => ({
  getDpopKeyPair: () => null, getDpopNonce: () => null, setDpopNonce: vi.fn(),
}))

const notification = (publicId: string, isRead = false) => ({
  publicId, isRead, category: 'security', eventType: 'security.alert', title: publicId,
  body: null, parameters: null, createdAt: '2026-10-30T10:00:00Z',
})
const disposers: (() => void)[] = []
let mock: MockAdapter
function setup() {
  const client = new OwnedQueryClient()
  client.setDefaultOptions({ queries: { retry: false }, mutations: { retry: false } })
  const snapshot: LiveSnapshot = {
    status: 'current', authorityReady: true, families: ['notifications', 'devices', 'sessions'],
    authorizationFingerprint: 'a'.repeat(64), health: { state: 'current', oldestPendingAgeMs: null },
  }
  const recovery = {
    getSnapshot: () => snapshot, subscribe: () => () => {}, subscribeEvents: () => () => {},
    completeRecovery: () => {}, resourceReadFailed: vi.fn(),
  }
  const coordinator = new LiveQueryCoordinator(client, recovery, [...notificationLiveDescriptors, ...sessionLiveDescriptors])
  client.setCoordinator(coordinator)
  disposers.push(coordinator.start(), client.attach(), () => client.dispose())
  const wrapper = ({ children }: { children: React.ReactNode }) => <QueryClientProvider client={client}>
    <LiveCoordinatorContext.Provider value={coordinator}><ThemeProvider theme={resolveTheme('dark', 'violet')}>{children}</ThemeProvider></LiveCoordinatorContext.Provider>
  </QueryClientProvider>
  return { client, coordinator, wrapper }
}
beforeEach(() => {
  synchronizeOwnership('personal-a')
  useNotificationStore.getState().resetForPrincipal()
  mock = new MockAdapter(AXIOS_INSTANCE, { onNoMatch: 'throwException' })
})
afterEach(() => {
  cleanup()
  disposers.splice(0).reverse().forEach(dispose => dispose())
  mock.restore()
  synchronizeOwnership(null)
})

import { PlaybackSessionCoordinator, PLAYBACK_SESSION_KEY } from '@/features/session/services/playback-session-coordinator'
import { usePlaybackSession } from '@/features/session/hooks/use-playback-session'
import { registerPlaybackAdapter } from '@/features/session/services/playback-execution'
import type { PlaybackSnapshotResource } from '@/shared/api-client/gen/endpoints'
import type { LiveTransportListener, SharedLiveTransport } from '@/shared/live/transport'

function playbackSnapshot(overrides: Partial<PlaybackSnapshotResource> = {}): PlaybackSnapshotResource {
  return { schemaVersion: 1, publicId: 'session', revision: 1,
    media: null, queue: [], currentTrackIndex: 0, position: 0,
    playbackState: 'stopped', desiredState: 'stopped', observedState: 'unknown',
    selectedDestinationId: null, activeDestinationId: null, activeIncarnationId: null,
    transfer: null, destinations: [], commands: [], ...overrides }
}

function playbackRuntime(client: OwnedQueryClient) {
  let listener: LiveTransportListener = {}
  const transport = {
    subscribe: (value: LiveTransportListener) => { listener = value; return () => { listener = {} } },
    retain: () => () => {}, isConnected: () => true, send: vi.fn(),
  }
  mock.onPost('/api/devices').reply(200, {})
  const runtime = new PlaybackSessionCoordinator(client, transport as unknown as SharedLiveTransport)
  disposers.push(runtime.start())
  return { runtime, receive: (snapshot: PlaybackSnapshotResource) => {
    act(() => listener.onMessage?.({ type: 'session.snapshot', data: snapshot }))
  } }
}

describe('personal state through owned queries and HTTP', () => {
  it('keeps list and count current after remote creation, read and deletion', async () => {
    let rows = [notification('first')]
    mock.onGet('/api/notifications/').reply(() => [200, { data: rows, nextCursor: null }])
    mock.onGet('/api/notifications/unread-count').reply(() => [200, { data: { count: rows.filter(row => !row.isRead).length } }])
    const { coordinator, wrapper } = setup()
    const hook = renderHook(useNotifications, { wrapper })
    await waitFor(() => expect(hook.result.current.notifications).toHaveLength(1))
    rows = [notification('second'), notification('first')]
    act(() => coordinator.hint(['notifications']))
    await waitFor(() => expect(hook.result.current.notifications).toHaveLength(2))
    await waitFor(() => expect(hook.result.current.unreadCount).toBe(2))
    rows = [notification('second', true)]
    act(() => coordinator.hint(['notifications']))
    await waitFor(() => expect(hook.result.current.notifications.map(row => row.publicId)).toEqual(['second']))
    await waitFor(() => expect(hook.result.current.unreadCount).toBe(0))
  })

  it('cancels an old list during a pending read mutation and converges without double decrement', async () => {
    let rows = [notification('first')]
    let lateList: ((value: [number, unknown]) => void) | undefined
    let finish: ((value: [number, unknown]) => void) | undefined
    let delay = false
    mock.onGet('/api/notifications/').reply(() => delay
      ? new Promise(resolve => { lateList = resolve })
      : [200, { data: rows, nextCursor: null }])
    mock.onGet('/api/notifications/unread-count').reply(() => [200, { data: { count: rows.filter(row => !row.isRead).length } }])
    mock.onPatch('/api/notifications/first/read').reply(() => new Promise(resolve => { finish = resolve }))
    const { coordinator, wrapper } = setup()
    const hook = renderHook(() => ({ ...useNotifications(), ...useNotificationActions() }), { wrapper })
    await waitFor(() => expect(hook.result.current.unreadCount).toBe(1))
    delay = true
    act(() => coordinator.hint(['notifications']))
    await waitFor(() => expect(lateList).toBeDefined())
    act(() => hook.result.current.markRead.mutate('first'))
    await waitFor(() => expect(finish).toBeDefined())
    rows = [notification('first', true)]
    delay = false
    act(() => {
      lateList?.([200, { data: [notification('first')], nextCursor: null }])
      coordinator.hint(['notifications'])
      finish?.([200, {}])
    })
    await waitFor(() => expect(hook.result.current.notifications[0]?.isRead).toBe(true))
    await waitFor(() => expect(hook.result.current.unreadCount).toBe(0))
  })

  it('releases a pending mutation when the principal changes without restoring the old mirror', async () => {
    let finish: ((value: [number, unknown]) => void) | undefined
    mock.onGet('/api/notifications/').reply(200, { data: [notification('private-a')], nextCursor: null })
    mock.onGet('/api/notifications/unread-count').reply(200, { data: { count: 1 } })
    mock.onPatch('/api/notifications/read-all').reply(() => new Promise(resolve => { finish = resolve }))
    const { wrapper } = setup()
    const hook = renderHook(() => ({ ...useNotifications(), ...useNotificationActions() }), { wrapper })
    await waitFor(() => expect(hook.result.current.notifications).toHaveLength(1))
    act(() => hook.result.current.markAllRead.mutate())
    await waitFor(() => expect(finish).toBeDefined())
    hook.unmount()
    act(() => {
      synchronizeOwnership('personal-b')
      useNotificationStore.getState().resetForPrincipal()
      finish?.([200, {}])
    })
    await waitFor(() => expect(useNotificationStore.getState().notifications).toEqual([]))
    expect(useNotificationStore.getState().unreadCount).toBe(0)
  })

  it('refreshes the generated device key independently of playback destinations', async () => {
    let name = 'Office'
    mock.onGet('/api/devices').reply(() => [200, { data: [
      { publicId: 'device', name }, { publicId: 'local', deviceId: getDeviceId(), name: 'Here' },
    ] }])
    const { client, coordinator, wrapper } = setup()
    const destination = playbackSnapshot({ activeDestinationId: 'remote-browser', position: 32 })
    client.setQueryData(PLAYBACK_SESSION_KEY, destination)
    const hook = renderHook(() => useGetDeviceList(), { wrapper })
    await waitFor(() => expect(hook.result.current.data?.data?.[0].name).toBe('Office'))
    name = 'Living room'
    act(() => coordinator.hint(['devices']))
    await waitFor(() => expect(hook.result.current.data?.data?.[0].name).toBe('Living room'))
    expect(client.getQueryCache().getAll().filter(query => query.queryKey[0] === '/api/devices').map(query => query.queryKey)).toEqual([getGetDeviceListQueryKey()])
    expect(client.getQueryData(PLAYBACK_SESSION_KEY)).toEqual(destination)
    expect(mock.history.post).toHaveLength(0)
  })

  it('adopts live snapshots without independently executing or claiming playback', async () => {
    const execute = vi.fn()
    const prepare = vi.fn()
    disposers.push(registerPlaybackAdapter({ kind: 'song', supportsVolume: true, prepare, execute,
      quiesce: vi.fn(async () => ({ observedState: 'stopped' as const, position: 0 })),
      observe: () => ({ observedState: 'stopped', position: 0 }),
    }))
    const { client, coordinator, wrapper } = setup()
    const playback = playbackRuntime(client)
    const hook = renderHook(usePlaybackSession, { wrapper })
    playback.receive(playbackSnapshot())
    await waitFor(() => expect(hook.result.current.snapshot?.queue).toEqual([]))
    playback.receive(playbackSnapshot({ revision: 2, media: { kind: 'song', publicId: 'remote-song' },
      queue: ['remote-song'], activeDestinationId: 'remote-browser', activeIncarnationId: 'remote-tab', position: 20 }))
    act(() => coordinator.hint(['sessions']))
    await waitFor(() => expect(hook.result.current.snapshot?.position).toBe(20))
    expect(prepare).not.toHaveBeenCalled()
    expect(execute).not.toHaveBeenCalled()
    expect(mock.history.get).toHaveLength(0)
    expect(mock.history.post.filter(request => request.url !== '/api/devices')).toHaveLength(0)
  })

  it.each(['failed write', 'account replacement'] as const)('protects current playback from a late control response after %s', async outcome => {
    let finish: ((value: [number, unknown]) => void) | undefined
    mock.onPost('/api/session/control').reply(() => new Promise(resolve => { finish = resolve }))
    const { client, wrapper } = setup()
    const playback = playbackRuntime(client)
    const original = playbackSnapshot({ endpoint: { destinationId: 'local', incarnationId: 'tab', epoch: 1, capability: 'private' } })
    playback.receive(original)
    const hook = renderHook(usePlaybackSession, { wrapper })
    const request = playback.runtime.request({ action: 'pause' }).catch(error => error)
    await waitFor(() => expect(finish).toBeDefined())
    if (outcome === 'account replacement') act(() => synchronizeOwnership('personal-b'))
    await act(async () => {
      finish?.(outcome === 'failed write' ? [503, {}] : [200, { data: playbackSnapshot({ revision: 9, position: 90 }) }])
      await request
    })
    if (outcome === 'account replacement') {
      expect(client.getQueryData(PLAYBACK_SESSION_KEY)).toBeUndefined()
      hook.unmount()
    } else {
      expect(hook.result.current.snapshot?.revision).toBe(1)
      await waitFor(() => expect(hook.result.current.error).toBeTruthy())
    }
    expect(mock.history.post.filter(request => request.url === '/api/session/transfer')).toHaveLength(0)
  })

  it('represents an idle initial session without issuing a playback command', async () => {
    const { client, wrapper } = setup()
    const playback = playbackRuntime(client)
    const hook = renderHook(usePlaybackSession, { wrapper })
    playback.receive(playbackSnapshot())
    await waitFor(() => expect(hook.result.current.snapshot?.media).toBeNull())
    expect(hook.result.current.snapshot?.queue).toEqual([])
    expect(mock.history.post.filter(request => request.url !== '/api/devices')).toHaveLength(0)
  })
})
