import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { act, renderHook, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { getCurrentTime, updateTime } from '@/features/player/stores/player-time-tracker'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { mediator } from '@/shared/lib/mediator/bus'
import { useSession } from '../hooks/use-session'

vi.mock('@/shared/api-client/axios-instance', () => ({ AXIOS_INSTANCE: {
  get: vi.fn(),
  post: vi.fn(() => Promise.resolve({ data: {} })),
  put: vi.fn(() => Promise.resolve({ data: {} })),
} }))
vi.mock('@/features/player/services/audio-service', () => ({ audioService: { getProcessor: () => null } }))
vi.mock('@/features/player/services/activity-service', () => ({ activityService: { recordPlay: () => Promise.resolve() } }))
const auth = vi.hoisted(() => ({ accessToken: 'token-at-connect' as string | null }))
vi.mock('@/features/auth/stores/auth-store', () => ({ useAuthStore: { getState: () => auth } }))
vi.mock('../utils/device-id', () => ({ getDeviceId: () => 'local-device', getDeviceName: () => 'Test Browser' }))
vi.mock('@/shared/lib/mediator/bus', () => ({ mediator: { dispatch: vi.fn() } }))

class FakeWebSocket {
  static readonly CONNECTING = 0
  static readonly OPEN = 1
  static readonly CLOSING = 2
  static readonly CLOSED = 3
  static instances: FakeWebSocket[] = []

  readonly url: string
  readyState = FakeWebSocket.CONNECTING
  onopen: (() => void) | null = null
  onmessage: ((event: { data: string }) => void) | null = null
  onerror: (() => void) | null = null
  onclose: (() => void) | null = null
  send = vi.fn()

  constructor(url: string) {
    this.url = url
    FakeWebSocket.instances.push(this)
  }

  close(): void {
    this.readyState = FakeWebSocket.CLOSED
  }

  open(): void {
    this.readyState = FakeWebSocket.OPEN
    this.onopen?.()
  }

  receive(message: object): void {
    this.onmessage?.({ data: JSON.stringify(message) })
  }

  drop(): void {
    this.readyState = FakeWebSocket.CLOSED
    this.onclose?.()
  }
}

type ActiveDevice = string | null

function sessionFor(activeDeviceId: ActiveDevice, position: number, queue = ['first']) {
  return {
    id: 'session-1',
    userId: 'user-1',
    activeDeviceId,
    queue,
    currentTrackIndex: 0,
    position,
    playbackState: 'playing' as const,
    createdAt: '',
    updatedAt: '',
    lastUsedAt: null,
  }
}

const clients: QueryClient[] = []
function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  clients.push(client)
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>
}

async function mountConnected(activeDeviceId: ActiveDevice) {
  vi.mocked(AXIOS_INSTANCE.get).mockResolvedValue({ data: { data: sessionFor(activeDeviceId, 15) } })
  const hook = renderHook(() => useSession(), { wrapper })
  await waitFor(() => expect(FakeWebSocket.instances).toHaveLength(1))
  const socket = FakeWebSocket.instances[0]
  act(() => { socket.open() })
  return { hook, socket }
}

beforeEach(() => {
  vi.clearAllMocks()
  FakeWebSocket.instances = []
  vi.stubGlobal('WebSocket', FakeWebSocket)
  auth.accessToken = 'token-at-connect'
  usePlayerStore.setState(usePlayerStore.getInitialState(), true)
  usePlayerStore.getState().restoreQueue([{ publicId: 'first', title: 'First' }], 0, 0)
  usePlayerStore.getState().setDuration(300)
  updateTime(45)
})

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
  clients.splice(0).forEach(client => client.clear())
})

it('reconnects the session socket with the token that was refreshed while it was connected', async () => {
  const { hook, socket } = await mountConnected('local-device')
  expect(socket.url).toBe('/api/ws?token=token-at-connect')

  auth.accessToken = 'token-after-refresh'
  vi.useFakeTimers()
  act(() => {
    socket.drop()
    vi.advanceTimersToNextTimer()
  })

  expect(FakeWebSocket.instances).toHaveLength(2)
  expect(FakeWebSocket.instances[1].url).toBe('/api/ws?token=token-after-refresh')
  hook.unmount()
})

it('keeps the position of the active device when it rejoins a session that is 30 seconds behind', async () => {
  usePlayerStore.getState().setIsPlaying(true)
  const { hook, socket } = await mountConnected('local-device')

  act(() => { socket.receive({ type: 'session.joined', data: sessionFor('local-device', 15) }) })

  expect(getCurrentTime()).toBe(45)
  hook.unmount()
})

it('keeps the position of a playing device when no device holds the session', async () => {
  usePlayerStore.getState().setIsPlaying(true)
  const { hook, socket } = await mountConnected(null)

  act(() => { socket.receive({ type: 'session.joined', data: sessionFor(null, 15) }) })

  expect(getCurrentTime()).toBe(45)
  hook.unmount()
})

it('moves a device that is not active to the server position when it rejoins', async () => {
  const { hook, socket } = await mountConnected('other-device')

  act(() => { socket.receive({ type: 'session.joined', data: sessionFor('other-device', 15) }) })

  expect(getCurrentTime()).toBe(15)
  hook.unmount()
})

it('keeps the queue the active device switched to while the server was away', async () => {
  usePlayerStore.getState().setIsPlaying(true)
  const { hook, socket } = await mountConnected('local-device')
  vi.mocked(AXIOS_INSTANCE.get).mockClear()

  act(() => { socket.receive({ type: 'session.joined', data: sessionFor('local-device', 15, ['before-outage']) }) })

  expect(AXIOS_INSTANCE.get).not.toHaveBeenCalledWith('/api/songs/', expect.anything())
  expect(mediator.dispatch).not.toHaveBeenCalled()
  expect(getCurrentTime()).toBe(45)
  hook.unmount()
})
