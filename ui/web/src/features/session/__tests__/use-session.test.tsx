import { describe, it, expect, vi, beforeEach } from 'vitest'
import { renderHook, act, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: {
    get: vi.fn(),
    put: vi.fn(),
    post: vi.fn().mockResolvedValue({ data: {} }),
    delete: vi.fn(),
  },
}))

vi.mock('@/features/player/stores/player-store', () => ({
  usePlayerStore: Object.assign(
    (selector: (s: unknown) => unknown) => selector({
      queue: [{ publicId: 'track-1' }, { publicId: 'track-2' }],
      currentIndex: 0,
      currentTime: 45,
      isPlaying: true,
      audioElement: null,
    }),
    {
      getState: vi.fn(() => ({
        queue: [{ publicId: 'track-1' }, { publicId: 'track-2' }],
        currentIndex: 0,
        currentTime: 45,
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
    (selector: (s: unknown) => unknown) => selector({ accessToken: 'test-token' }),
    { getState: () => ({ accessToken: 'test-token' }) },
  ),
}))

const { MockSessionSyncBus } = vi.hoisted(() => {
  class MockSessionSyncBus {
    connect = vi.fn()
    disconnect = vi.fn()
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

const mockAxios = vi.mocked(AXIOS_INSTANCE)

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>
}

describe('useSession', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    // Default: all posts resolve safely (device registration, etc.)
    mockAxios.post.mockResolvedValue({ data: {} })
  })

  it('fetches current session on mount', async () => {
    mockAxios.get.mockResolvedValueOnce({
      data: {
        data: {
          id: 'session-1',
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

    expect(mockAxios.get).toHaveBeenCalledWith('/api/session')
    expect(result.current.session?.id).toBe('session-1')
  })

  it('shows transfer prompt when another device has the session', async () => {
    mockAxios.get.mockResolvedValueOnce({
      data: {
        data: {
          id: 'session-1',
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
          id: 'session-1',
          userId: 'user-1',
          activeDeviceId: 'my-device-id',
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
          id: 'session-2',
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
          id: 'session-1',
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
})
