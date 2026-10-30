import { act, cleanup, renderHook, waitFor, render, screen } from '@testing-library/react'
import { QueryClientProvider } from '@tanstack/react-query'
import MockAdapter from 'axios-mock-adapter'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { OwnedQueryClient } from '@/shared/live/query-client'
import { LiveQueryCoordinator } from '@/shared/live/query-coordinator'
import { LiveCoordinatorContext } from '@/shared/live/context'
import userEvent from '@testing-library/user-event'
import { StrictMode } from 'react'
import { ThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { DevicePicker } from '@/features/session/components/DevicePicker'
import { useSession } from '@/features/session/hooks/use-session'
import { getDeviceId } from '@/features/session/utils/device-id'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { mediator } from '@/shared/lib/mediator/bus'
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

vi.mock('@/features/session/services/SessionSyncBus', () => ({ SessionSyncBus: class {
  connect = async () => {}
  disconnect = () => {}
  isConnected = () => false
  sendSync = () => {}
} }))

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

  it('refreshes the generated device key without invoking session commands', async () => {
    let name = 'Office'
    mock.onGet('/api/devices').reply(() => [200, { data: [{ publicId: 'device', name }, { publicId: 'local', deviceId: getDeviceId(), name: 'Here' }] }])
    mock.onGet('/api/session').reply(200, { data: { activeDeviceId: 'device' } })
    const { client, coordinator, wrapper } = setup()
    const hook = renderHook(() => useGetDeviceList(), { wrapper })
    render(<DevicePicker />, { wrapper })
    await screen.findByRole('button', { name: 'Office' })
    name = 'Living room'
    act(() => coordinator.hint(['devices']))
    await screen.findByRole('button', { name: 'Living room' })
    expect(hook.result.current.data?.data?.[0].name).toBe('Living room')
    expect(client.getQueryCache().getAll().filter(query => query.queryKey[0] === '/api/devices').map(query => query.queryKey)).toEqual([getGetDeviceListQueryKey()])
    expect(mock.history.post).toHaveLength(0)
  })
  it('does not restore or claim playback when a session refresh arrives after the local queue was cleared', async () => {
    const original = usePlayerStore.getState()
    usePlayerStore.setState({ queue: [], currentTrack: null, currentIndex: 0, isPlaying: false })
    const dispatch = vi.spyOn(mediator, 'dispatch')
    let queue: string[] = []
    mock.onGet('/api/devices').reply(200, { data: [{ publicId: 'local', deviceId: getDeviceId(), name: 'Here' }] })
    mock.onPost('/api/devices').reply(200, {})
    mock.onGet('/api/session').reply(() => [200, { data: { publicId: 'session', activeDeviceId: 'local', queue, currentTrackIndex: 0, position: 20 } }])
    mock.onGet('/api/songs/').reply(200, { data: [{ publicId: 'remote-song', title: 'Remote', available: true }] })
    const { coordinator, wrapper } = setup()
    const hook = renderHook(useSession, { wrapper })
    await waitFor(() => expect(hook.result.current.session?.queue).toEqual([]))
    await waitFor(() => expect(mock.history.get.some(request => request.url === '/api/devices')).toBe(true))
    queue = ['remote-song']
    act(() => coordinator.hint(['sessions']))
    await waitFor(() => expect(hook.result.current.session?.queue).toEqual(['remote-song']))
    expect(dispatch).not.toHaveBeenCalled()
    expect(mock.history.get.filter(request => request.url?.includes('/api/songs/'))).toHaveLength(0)
    expect(mock.history.post.filter(request => request.url !== '/api/devices')).toHaveLength(0)
    expect(usePlayerStore.getState().queue).toEqual([])
    hook.unmount()
    dispatch.mockRestore()
    usePlayerStore.setState(original)
  })

  it('hydrates the initial queue once through StrictMode and retains eligibility after a transient hydration failure', async () => {
    const original = usePlayerStore.getState()
    usePlayerStore.setState({ queue: [], currentTrack: null, currentIndex: 0, isPlaying: false })
    const dispatch = vi.spyOn(mediator, 'dispatch')
    const session = { publicId: 'session', activeDeviceId: 'local', queue: ['remote-song'], currentTrackIndex: 0, position: 20 }
    const devices = { data: [{ publicId: 'local', deviceId: getDeviceId(), name: 'Here' }] }
    mock.onGet('/api/devices').reply(200, devices)
    mock.onPost('/api/devices').reply(200, {})
    mock.onGet('/api/session').reply(200, { data: session })
    let failHydration = true
    mock.onGet('/api/songs/').reply(() => failHydration ? [503, {}] : [200, { data: [{ publicId: 'remote-song', title: 'Remote', available: true }] }])
    const { client, coordinator, wrapper: Wrapper } = setup()
    client.setQueryData(['session', 'current'], session)
    client.setQueryData(getGetDeviceListQueryKey(), devices)
    const wrapper = ({ children }: { children: React.ReactNode }) => <StrictMode><Wrapper>{children}</Wrapper></StrictMode>
    const hook = renderHook(useSession, { wrapper })
    await waitFor(() => expect(mock.history.get.some(request => request.url === '/api/songs/')).toBe(true))
    expect(dispatch).not.toHaveBeenCalled()
    failHydration = false
    // A new authoritative snapshot retries the unfinished initial hydration.
    mock.onGet('/api/session').reply(200, { data: { ...session, position: 21 } })
    act(() => coordinator.hint(['sessions']))
    await waitFor(() => expect(dispatch).toHaveBeenCalledTimes(1))
    mock.onGet('/api/session').reply(200, { data: { ...session, position: 22 } })
    act(() => coordinator.hint(['sessions']))
    await waitFor(() => expect(hook.result.current.session?.position).toBe(22))
    expect(dispatch).toHaveBeenCalledTimes(1)
    hook.unmount()
    dispatch.mockRestore()
    usePlayerStore.setState(original)
  })

  it('keeps the device picker claim explicit and hydrates its queue after initial restoration was consumed', async () => {
    const original = usePlayerStore.getState()
    usePlayerStore.setState({ queue: [{ publicId: 'local-song', title: 'Local', available: true }], currentTrack: null, currentIndex: 0, isPlaying: false })
    const dispatch = vi.spyOn(mediator, 'dispatch')
    let activeDeviceId = 'other'
    const session = () => ({ publicId: 'session', activeDeviceId, queue: ['remote-song'], currentTrackIndex: 0, position: 20 })
    mock.onGet('/api/devices').reply(200, { data: [{ publicId: 'local', deviceId: getDeviceId(), name: 'Here' }, { publicId: 'other', name: 'Elsewhere' }] })
    mock.onPost('/api/devices').reply(200, {})
    mock.onGet('/api/session').reply(() => [200, { data: session() }])
    mock.onGet('/api/songs/').reply(200, { data: [{ publicId: 'remote-song', title: 'Remote', available: true }] })
    mock.onPost('/api/session/claim').reply(() => { activeDeviceId = 'local'; return [200, { data: session() }] })
    const { coordinator, wrapper } = setup()
    const hook = renderHook(useSession, { wrapper })
    render(<DevicePicker />, { wrapper })
    await screen.findByRole('button', { name: 'Elsewhere' })
    expect(dispatch).not.toHaveBeenCalled()
    expect(mock.history.post.filter(request => request.url === '/api/session/claim')).toHaveLength(0)
    await userEvent.setup().click(screen.getByRole('button', { name: 'Elsewhere' }))
    await waitFor(() => expect(dispatch).toHaveBeenCalledTimes(1))
    expect(mock.history.post.filter(request => request.url === '/api/session/claim')).toHaveLength(1)
    expect(dispatch.mock.calls[0][1]).toMatchObject({ queue: [{ publicId: 'remote-song' }] })
    act(() => coordinator.hint(['sessions']))
    await waitFor(() => expect(hook.result.current.session?.activeDeviceId).toBe('local'))
    expect(dispatch).toHaveBeenCalledTimes(1)
    hook.unmount()
    dispatch.mockRestore()
    usePlayerStore.setState(original)
  })

  it('treats an absent initial session as a successful empty snapshot without issuing a command', async () => {
    mock.onGet('/api/devices').reply(200, { data: [] })
    mock.onGet('/api/session').reply(404, {})
    const { client, wrapper } = setup()
    render(<DevicePicker />, { wrapper })
    await waitFor(() => expect(client.getQueryState(['session', 'current'])?.status).toBe('success'))
    expect(client.getQueryData(['session', 'current'])).toBeNull()
    expect(mock.history.post).toHaveLength(0)
  })

})
