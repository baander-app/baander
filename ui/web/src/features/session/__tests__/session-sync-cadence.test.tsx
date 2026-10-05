import { it, expect, beforeEach, afterEach, vi } from 'vitest'
import { renderHook, act, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { getCurrentTime, updateTime } from '@/features/player/stores/player-time-tracker'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { useSession } from '../hooks/use-session'

vi.mock('@/shared/api-client/axios-instance', () => ({ AXIOS_INSTANCE: {
  get: vi.fn(), post: vi.fn(() => Promise.resolve({ data: {} })), put: vi.fn(() => Promise.resolve({ data: {} })),
} }))
vi.mock('@/features/player/services/audio-service', () => ({ audioService: { getProcessor: () => null } }))
vi.mock('@/features/player/services/activity-service', () => ({ activityService: { recordPlay: () => Promise.resolve() } }))
vi.mock('@/features/auth/stores/auth-store', () => ({ useAuthStore: { getState: () => ({ accessToken: 'token' }) } }))
vi.mock('../utils/device-id', () => ({ getDeviceId: () => 'local-device', getDeviceName: () => 'Test Browser' }))
vi.mock('@/shared/lib/mediator/bus', () => ({ mediator: { dispatch: vi.fn() } }))

const buses = vi.hoisted(() => ({ connected: false, instances: [] as { sendSync: ReturnType<typeof vi.fn>; disconnect: ReturnType<typeof vi.fn> }[] }))
vi.mock('../services/SessionSyncBus', () => ({ SessionSyncBus: class {
  sendSync = vi.fn()
  disconnect = vi.fn()
  connect = vi.fn()
  isConnected = () => buses.connected
  constructor() { buses.instances.push(this) }
} }))

const session = { id: 'session-1', userId: 'user-1', activeDeviceId: 'local-device',
  queue: ['first'], currentTrackIndex: 0, position: 0, playbackState: 'paused', createdAt: '', updatedAt: '', lastUsedAt: null }
const clients: QueryClient[] = []
function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  clients.push(client)
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>
}

beforeEach(() => {
  vi.clearAllMocks()
  buses.connected = false
  buses.instances = []
  usePlayerStore.setState(usePlayerStore.getInitialState(), true)
  usePlayerStore.getState().restoreQueue([{ publicId: 'first', title: 'First' }], 0, 0)
  vi.mocked(AXIOS_INSTANCE.get).mockResolvedValue({ data: { data: session } })
})
afterEach(() => {
  vi.useRealTimers()
  clients.splice(0).forEach(client => client.clear())
})

it('syncs continuously at a bounded cadence using the latest sole-clock position without hook renders', async () => {
  let renders = 0
  const hook = renderHook(() => { renders++; return useSession() }, { wrapper })
  await waitFor(() => expect(hook.result.current.session?.id).toBe('session-1'))
  vi.useFakeTimers()
  act(() => { usePlayerStore.getState().setIsPlaying(true) })
  const initialRenders = renders
  for (let tick = 1; tick <= 24; tick++) act(() => { updateTime(tick / 4); vi.advanceTimersByTime(250) })
  expect(AXIOS_INSTANCE.put).toHaveBeenCalledTimes(3)
  expect(vi.mocked(AXIOS_INSTANCE.put).mock.calls.map(call => (call[1] as { position: number }).position)).toEqual([2, 4, 6])
  expect(getCurrentTime()).toBe(6)
  expect(renders).toBe(initialRenders)
  act(() => { usePlayerStore.getState().setIsPlaying(false); vi.advanceTimersByTime(2000) })
  expect(AXIOS_INSTANCE.put).toHaveBeenLastCalledWith('/api/session', expect.objectContaining({ position: 6, playbackState: 'paused' }),
    { headers: { 'X-Baander-Device-Id': 'local-device' } })
  act(() => { updateTime(7) })
  hook.unmount()
  act(() => { vi.advanceTimersByTime(2500); updateTime(8); vi.advanceTimersByTime(2500) })
  expect(AXIOS_INSTANCE.put).toHaveBeenCalledTimes(4)
  expect(buses.instances[0].disconnect).toHaveBeenCalledOnce()
})

it('uses the connected websocket without duplicate REST writes', async () => {
  buses.connected = true
  const hook = renderHook(() => useSession(), { wrapper })
  await waitFor(() => expect(hook.result.current.session?.id).toBe('session-1'))
  vi.useFakeTimers()
  for (let tick = 1; tick <= 8; tick++) act(() => { updateTime(tick / 4); vi.advanceTimersByTime(250) })
  expect(buses.instances[0].sendSync).toHaveBeenCalledOnce()
  expect(AXIOS_INSTANCE.put).not.toHaveBeenCalled()
  hook.unmount()
})

it('never syncs an inactive device despite a continuously changing clock', async () => {
  vi.mocked(AXIOS_INSTANCE.get).mockResolvedValue({ data: { data: { ...session, activeDeviceId: 'other-device' } } })
  const hook = renderHook(() => useSession(), { wrapper })
  await waitFor(() => expect(hook.result.current.session?.id).toBe('session-1'))
  vi.useFakeTimers()
  for (let tick = 1; tick <= 16; tick++) act(() => { updateTime(tick / 4); vi.advanceTimersByTime(250) })
  expect(AXIOS_INSTANCE.put).not.toHaveBeenCalled()
  expect(buses.instances[0].sendSync).not.toHaveBeenCalled()
  hook.unmount()
})
