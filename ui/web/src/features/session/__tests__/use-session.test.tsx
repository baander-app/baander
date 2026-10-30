import { describe, it, expect, vi, beforeEach } from 'vitest'
import { renderHook, act, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import type { SessionSyncConfig, SessionSyncEvents } from '../services/SessionSyncBus'

vi.mock('@/shared/api-client/axios-instance', () => ({ apiOrigin: () => 'https://api.baander.app',
  AXIOS_INSTANCE: {
    get: vi.fn(),
    put: vi.fn(),
    post: vi.fn().mockResolvedValue({ data: {} }),
    delete: vi.fn(),
  },
}))

vi.mock('@/features/player/stores/player-time-tracker', () => ({
  getCurrentTime: () => 45, subscribe: () => () => {},
}))

vi.mock('@/features/player/stores/player-store', () => ({
  usePlayerStore: Object.assign(
    (selector: (s: unknown) => unknown) => selector({
      queue: [{ publicId: 'track-1', title: 'First track' }, { publicId: 'track-2', title: 'Second track' }],
      currentIndex: 0,
      isPlaying: true,
      audioElement: null,
    }),
    {
      getState: vi.fn(() => ({
        queue: [{ publicId: 'track-1', title: 'First track' }, { publicId: 'track-2', title: 'Second track' }],
        currentIndex: 0,
          isPlaying: true,
        audioElement: null,
      })),
      setState: vi.fn(),
      subscribe: vi.fn(() => vi.fn()),
    },
  ),
}))

vi.mock('../utils/device-id', () => ({
  getDeviceId: vi.fn(() => 'my-device-id'),
  getDeviceName: vi.fn(() => 'Chrome on Linux'),
}))

vi.mock('@/shared/lib/mediator/bus', () => ({
  mediator: { dispatch: vi.fn() },
}))

vi.mock('@/features/auth/stores/auth-store', () => ({
  useAuthStore: Object.assign(
    (selector: (s: unknown) => unknown) => selector({ accessToken: 'test-token', isAuthenticated: true }),
    { getState: () => ({ accessToken: 'test-token', isAuthenticated: true }) },
  ),
}))

const { MockSessionSyncBus } = vi.hoisted(() => {
  class MockSessionSyncBus {
    static events: SessionSyncEvents[] = []
    constructor(_config: SessionSyncConfig, events: SessionSyncEvents) {
      MockSessionSyncBus.events.push(events)
    }
    connect = vi.fn().mockResolvedValue(undefined)
    disconnect = vi.fn().mockResolvedValue(undefined)
    sendSync = vi.fn()
    isConnected = vi.fn(() => false)
  }
  return { MockSessionSyncBus }
})
vi.mock('../services/SessionSyncBus', () => ({
  SessionSyncBus: MockSessionSyncBus,
}))

import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { useSession } from '../hooks/use-session'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { mediator } from '@/shared/lib/mediator/bus'
import { advanceAuthorization } from '@/shared/live/ownership'

const mockAxios = vi.mocked(AXIOS_INSTANCE)

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>
}

describe('useSession', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    MockSessionSyncBus.events = []
    // Default: all posts resolve safely (device registration, etc.)
    mockAxios.post.mockResolvedValue({ data: {} })
    mockAxios.get.mockResolvedValue({ data: { data: [{ publicId: 'local-device-public-id', deviceId: 'my-device-id' }] } })
  })

  it('does not restore a queued song lookup after authorization changes', async () => {
    let finish!: (value: unknown) => void
    const pending = new Promise(resolve => { finish = resolve })
    vi.mocked(usePlayerStore.getState).mockReturnValue({
      ...usePlayerStore.getState(),
      queue: [],
    })
    mockAxios.get.mockImplementation(async url => {
      if (url === '/api/songs/') return pending
      if (url === '/api/devices') return { data: { data: [{ publicId: 'local-device-public-id', deviceId: 'my-device-id' }] } }
      return { data: { data: {
        publicId: 'session-1',
        activeDeviceId: 'local-device-public-id',
        queue: ['track-1'],
        currentTrackIndex: 0,
        position: 12,
      } } }
    })
    const hook = renderHook(() => useSession(), { wrapper })
    await waitFor(() => expect(mockAxios.get).toHaveBeenCalledWith('/api/songs/', expect.any(Object)))
    act(() => { advanceAuthorization() })
    await act(async () => {
      finish({ data: { data: [{ publicId: 'track-1', title: 'Old account song', available: true }] } })
      await pending
    })
    expect(mediator.dispatch).not.toHaveBeenCalled()
    hook.unmount()
    vi.mocked(usePlayerStore.getState).mockReturnValue({
      ...usePlayerStore.getState(),
      queue: [{ publicId: 'track-1', title: 'First track' }, { publicId: 'track-2', title: 'Second track' }],
    })
  })

  it('accepts new session state after access recovery while dropping an earlier hydration', async () => {
    let finishOld!: (value: unknown) => void
    const oldSongs = new Promise(resolve => { finishOld = resolve })
    const originalPlayer = usePlayerStore.getState()
    vi.mocked(usePlayerStore.getState).mockReturnValue({ ...originalPlayer, queue: [] })
    let lookups = 0
    mockAxios.get.mockImplementation(async url => {
      if (url === '/api/songs/') {
        if (++lookups === 1) return oldSongs
        return { data: { data: [{ publicId: 'track-1', title: 'Current song', available: true }] } }
      }
      if (url === '/api/devices') return { data: { data: [{ publicId: 'local-device-public-id', deviceId: 'my-device-id' }] } }
      return { data: { data: {
        publicId: 'session-1',
        activeDeviceId: 'other-device',
        queue: ['track-1'],
        currentTrackIndex: 0,
        position: 12,
      } } }
    })
    const hook = renderHook(() => useSession(), { wrapper })
    await waitFor(() => expect(MockSessionSyncBus.events.length).toBeGreaterThan(0))
    const socketEvents = MockSessionSyncBus.events.at(-1)!
    const state = {
      queue: ['track-1'], currentTrackIndex: 0, position: 12,
      playbackState: 'paused' as const, activeDeviceId: 'other-device',
    }
    act(() => { socketEvents.onStateUpdate(state) })
    await waitFor(() => expect(lookups).toBe(1))
    act(() => {
      advanceAuthorization()
      socketEvents.onStateUpdate(state)
    })
    await waitFor(() => expect(mediator.dispatch).toHaveBeenCalledOnce())
    await act(async () => {
      finishOld({ data: { data: [{ publicId: 'track-1', title: 'Old song', available: true }] } })
      await oldSongs
    })
    expect(mediator.dispatch).toHaveBeenCalledOnce()
    expect(mediator.dispatch).toHaveBeenCalledWith(expect.anything(), expect.objectContaining({
      queue: [expect.objectContaining({ title: 'Current song' })],
    }), 'session')
    hook.unmount()
    vi.mocked(usePlayerStore.getState).mockReturnValue(originalPlayer)
  })

  it('fetches current session on mount', async () => {
    mockAxios.get.mockResolvedValueOnce({
      data: {
        data: {
          publicId: 'session-1',
          userId: 'user-1',
          activeDeviceId: null,
          queue: ['track-1'],
          currentTrackIndex: 0,
          position: 0,
          playbackState: 'stopped',
          createdAt: '2026-01-01T00:00:00Z',
          updatedAt: '2026-01-01T00:00:00Z',
          lastUsedAt: null,
        },
      },
    })

    const { result } = renderHook(() => useSession(), { wrapper })

    await waitFor(() => expect(result.current.session).toBeDefined())

    expect(mockAxios.get).toHaveBeenCalledWith('/api/session', { signal: expect.any(AbortSignal) })
    expect(result.current.session?.publicId).toBe('session-1')
  })

  it('shows transfer prompt when another device has the session', async () => {
    mockAxios.get.mockResolvedValueOnce({
      data: {
        data: {
          publicId: 'session-1',
          userId: 'user-1',
          activeDeviceId: 'other-device',
          queue: ['track-1', 'track-2'],
          currentTrackIndex: 0,
          position: 45,
          playbackState: 'playing',
          createdAt: '2026-01-01T00:00:00Z',
          updatedAt: '2026-01-01T00:00:00Z',
          lastUsedAt: null,
        },
      },
    })

    const { result } = renderHook(() => useSession(), { wrapper })

    await waitFor(() => expect(result.current.showTransferPrompt).toBe(true))

    expect(result.current.pendingSession?.activeDeviceId).toBe('other-device')
  })

  it('claims session via POST /api/session/claim', async () => {
    mockAxios.get.mockResolvedValueOnce({
      data: { data: null },
    })
    mockAxios.post.mockResolvedValue({
      data: {
        data: {
          publicId: 'session-1',
          userId: 'user-1',
          activeDeviceId: 'local-device-public-id',
          queue: ['track-1'],
          currentTrackIndex: 0,
          position: 0,
          playbackState: 'stopped',
          createdAt: '2026-01-01T00:00:00Z',
          updatedAt: '2026-01-01T00:00:00Z',
          lastUsedAt: null,
        },
      },
    })

    const { result } = renderHook(() => useSession(), { wrapper })

    await act(() => {
      result.current.claim()
    })
    await waitFor(() => expect(mockAxios.post).toHaveBeenCalled())

    expect(mockAxios.post).toHaveBeenCalledWith('/api/session/claim', {
      deviceId: 'my-device-id',
    })
  })

  it('creates new session via POST /api/session/new', async () => {
    mockAxios.get.mockResolvedValueOnce({
      data: { data: null },
    })
    mockAxios.post.mockResolvedValue({
      data: {
        data: {
          publicId: 'session-2',
          userId: 'user-1',
          activeDeviceId: null,
          queue: ['track-1', 'track-2'],
          currentTrackIndex: 0,
          position: 45,
          playbackState: 'stopped',
          createdAt: '2026-01-01T00:00:00Z',
          updatedAt: '2026-01-01T00:00:00Z',
          lastUsedAt: null,
        },
      },
    })

    const { result } = renderHook(() => useSession(), { wrapper })

    await act(() => {
      result.current.newSession()
    })
    await waitFor(() => expect(mockAxios.post).toHaveBeenCalled())

    expect(mockAxios.post).toHaveBeenCalledWith('/api/session/new', {
      queue: ['track-1', 'track-2'],
      currentTrackIndex: 0,
      position: 45,
    })
  })

  it('dismisses transfer prompt', async () => {
    mockAxios.get.mockResolvedValueOnce({
      data: {
        data: {
          publicId: 'session-1',
          userId: 'user-1',
          activeDeviceId: 'other-device',
          queue: ['track-1'],
          currentTrackIndex: 0,
          position: 0,
          playbackState: 'stopped',
          createdAt: '2026-01-01T00:00:00Z',
          updatedAt: '2026-01-01T00:00:00Z',
          lastUsedAt: null,
        },
      },
    })

    const { result } = renderHook(() => useSession(), { wrapper })

    await waitFor(() => expect(result.current.showTransferPrompt).toBe(true))

    act(() => {
      result.current.dismissTransfer()
    })

    expect(result.current.showTransferPrompt).toBe(false)
  })
  it('keeps a dismissed transfer hidden on refetch and prompts for a new session or device', async () => {
    const session = { publicId: 'session-1', userId: 'user-1', activeDeviceId: 'other-device', queue: [],
      currentTrackIndex: 0, position: 0, playbackState: 'stopped' as const,
      createdAt: '2026-01-01T00:00:00Z', updatedAt: '2026-01-01T00:00:00Z', lastUsedAt: null }
    mockAxios.get.mockImplementation(async (url) => ({ data: { data: url === '/api/devices' ? [{ publicId: 'local-device-public-id', deviceId: 'my-device-id' }] : session } }))
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    const { result, unmount } = renderHook(() => useSession(), {
      wrapper: ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>,
    })
    await waitFor(() => expect(result.current.showTransferPrompt).toBe(true))
    act(() => { result.current.dismissTransfer() })
    act(() => { client.setQueryData(['session', 'current'], { ...session, position: 10, updatedAt: '2026-01-01T00:00:10Z' }) })
    await waitFor(() => expect(result.current.pendingSession?.position).toBe(10))
    expect(result.current.showTransferPrompt).toBe(false)
    act(() => { client.setQueryData(['session', 'current'], { ...session, activeDeviceId: 'third-device' }) })
    await waitFor(() => expect(result.current.showTransferPrompt).toBe(true))
    act(() => { result.current.dismissTransfer() })
    act(() => { client.setQueryData(['session', 'current'], { ...session, publicId: 'session-2', activeDeviceId: 'third-device' }) })
    await waitFor(() => expect(result.current.showTransferPrompt).toBe(true))
    unmount()
    client.clear()
  })

})
