import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { renderHook, act } from '@testing-library/react'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: {
    get: vi.fn(),
    put: vi.fn(),
    post: vi.fn(),
  },
}))

import { usePreferenceSync } from '../hooks/use-preference-sync'

const mockAxios = vi.mocked(AXIOS_INSTANCE)

describe('usePreferenceSync', () => {
  const onRemoteUpdate = vi.fn()

  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  function createHook() {
    return renderHook(() =>
      usePreferenceSync<{ volume: number; muted: boolean }>({
        baseUrl: '/api/user/player-preferences',
        toPayload: (state) => ({ volume: state.volume / 100, muted: state.muted }),
        fromPayload: (payload) => ({
          volume: Math.round((payload.volume as number) * 100),
          muted: payload.muted as boolean,
        }),
        onRemoteUpdate,
      }),
    )
  }

  it('fetches preferences from server and calls onRemoteUpdate', async () => {
    mockAxios.get.mockResolvedValueOnce({
      data: {
        data: {
          payload: { volume: 0.8, muted: true },
          version: 3,
        },
      },
    })

    const { result } = createHook()
    await act(async () => {
      await result.current.fetchFromServer()
    })

    expect(onRemoteUpdate).toHaveBeenCalledWith({ volume: 80, muted: true }, 3)
  })

  it('returns false when server returns 404', async () => {
    mockAxios.get.mockRejectedValueOnce({ response: { status: 404 } })

    const { result } = createHook()
    let success: boolean
    await act(async () => {
      success = await result.current.fetchFromServer()
    })

    expect(success!).toBe(false)
    expect(onRemoteUpdate).not.toHaveBeenCalled()
  })

  it('debounces push to server by 500ms', async () => {
    mockAxios.put.mockResolvedValueOnce({
      data: { data: { payload: { volume: 0.75, muted: false }, version: 2 } },
    })

    const { result } = createHook()

    act(() => {
      result.current.pushToServer({ volume: 75, muted: false })
    })

    expect(mockAxios.put).not.toHaveBeenCalled()

    act(() => {
      vi.advanceTimersByTime(500)
    })

    await act(async () => {
      await vi.runAllTimersAsync()
    })

    expect(mockAxios.put).toHaveBeenCalledWith('/api/user/player-preferences', {
      payload: { volume: 0.75, muted: false },
      version: 0,
    })
  })

  it('detects 409 conflict', async () => {
    mockAxios.put.mockRejectedValueOnce({
      response: { status: 409, data: { data: { currentVersion: 5 } } },
    })

    const { result } = createHook()

    act(() => {
      result.current.pushToServer({ volume: 50, muted: false })
    })

    act(() => {
      vi.advanceTimersByTime(500)
    })

    await act(async () => {
      await vi.runAllTimersAsync()
    })

    expect(result.current.conflict).toEqual({
      type: 'conflict',
      serverVersion: 5,
    })
  })

  it('resolves conflict with "theirs" by refetching', async () => {
    mockAxios.get.mockResolvedValueOnce({
      data: {
        data: {
          payload: { volume: 0.6, muted: false },
          version: 5,
        },
      },
    })

    const { result } = createHook()

    // Set up conflict state
    await act(async () => {
      await result.current.resolveConflict('theirs')
    })

    expect(mockAxios.get).toHaveBeenCalledWith('/api/user/player-preferences')
  })

  it('fetches history from server', async () => {
    mockAxios.get.mockResolvedValueOnce({
      data: {
        data: {
          history: [
            { version: 1, createdAt: '2026-01-01T00:00:00Z' },
            { version: 2, createdAt: '2026-01-02T00:00:00Z' },
          ],
        },
      },
    })

    const { result } = createHook()
    let history: Awaited<ReturnType<typeof result.current.fetchHistory>>
    await act(async () => {
      history = await result.current.fetchHistory()
    })

    expect(history!).toEqual([
      { version: 1, createdAt: '2026-01-01T00:00:00Z' },
      { version: 2, createdAt: '2026-01-02T00:00:00Z' },
    ])
    expect(mockAxios.get).toHaveBeenCalledWith('/api/user/player-preferences/history')
  })

  it('rolls back to a previous version', async () => {
    mockAxios.post.mockResolvedValueOnce({
      data: {
        data: {
          payload: { volume: 0.5, muted: false },
          version: 1,
        },
      },
    })

    const { result } = createHook()
    let success: boolean
    await act(async () => {
      success = await result.current.rollback(1)
    })

    expect(success!).toBe(true)
    expect(mockAxios.post).toHaveBeenCalledWith('/api/user/player-preferences/rollback', {
      version: 1,
    })
    expect(onRemoteUpdate).toHaveBeenCalledWith({ volume: 50, muted: false }, 1)
  })
})
