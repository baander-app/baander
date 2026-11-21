import { act, renderHook } from '@testing-library/react'
import { QueryClientProvider } from '@tanstack/react-query'
import { AxiosHeaders } from 'axios'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { catalogLiveDescriptors } from '@/features/catalog/live-descriptors'
import { useVideoPlaybackInformation } from '@/features/catalog/hooks/use-video-playback-information'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { OwnedQueryClient } from '@/shared/live/query-client'
import { LiveQueryCoordinator } from '@/shared/live/query-coordinator'
import type { LiveSnapshot } from '@/shared/live/recovery'

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: { get: vi.fn() },
}))

const resources: Array<() => void> = []
const originalCreateObjectURL = URL.createObjectURL
const originalRevokeObjectURL = URL.revokeObjectURL

afterEach(() => {
  for (const release of resources.splice(0).reverse()) release()
  vi.useRealTimers()
  vi.restoreAllMocks()
  URL.createObjectURL = originalCreateObjectURL
  URL.revokeObjectURL = originalRevokeObjectURL
  vi.mocked(AXIOS_INSTANCE.get).mockReset()
})

function setup() {
  vi.useFakeTimers({ now: 100_000 })
  const client = new OwnedQueryClient()
  const snapshot: LiveSnapshot = {
    status: 'current',
    authorityReady: true,
    families: ['catalog'],
    authorizationFingerprint: 'a'.repeat(64),
    health: { state: 'current', oldestPendingAgeMs: null },
  }
  const recovery = {
    getSnapshot: () => snapshot,
    subscribe: () => () => undefined,
    subscribeEvents: () => () => undefined,
    completeRecovery: vi.fn(),
    resourceReadFailed: vi.fn(),
  }
  const coordinator = new LiveQueryCoordinator(client, recovery, catalogLiveDescriptors)
  client.setCoordinator(coordinator)
  resources.push(() => client.dispose(), client.attach(), coordinator.start())
  return { client, coordinator }
}

describe('video information shared sampling', () => {
  it('refreshes pending metadata and requests the authenticated preview when preparation completes', async () => {
    const { client, coordinator } = setup()
    let state = 'pending'
    const image = new Blob(['preview'], { type: 'image/jpeg' })
    URL.createObjectURL = vi.fn(() => 'blob:https://baander.app/preview')
    URL.revokeObjectURL = vi.fn()
    vi.mocked(AXIOS_INSTANCE.get).mockImplementation(async url => ({
      status: 200,
      statusText: 'OK',
      headers: {},
      config: { headers: new AxiosHeaders() },
      data: url.endsWith('/timeline-preview') ? image : {
        data: {
          audioTracks: [],
          subtitleTracks: [],
          chapters: [],
          preview: { state, url: null, interval: 10, width: 160, height: 90, columns: 10, count: 20 },
        },
      },
    }))
    const hook = renderHook(() => useVideoPlaybackInformation('video-1'), {
      wrapper: ({ children }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>,
    })
    resources.push(() => hook.unmount())
    await act(async () => { await vi.advanceTimersByTimeAsync(200) })
    expect(hook.result.current.data?.preview.state).toBe('pending')
    expect(hook.result.current.previewUrl).toBeNull()
    state = 'ready'
    await act(async () => { await vi.advanceTimersByTimeAsync(5000) })
    expect(hook.result.current.data?.preview.state).toBe('ready')
    expect(AXIOS_INSTANCE.get).toHaveBeenCalledWith('/api/videos/video-1/timeline-preview', {
      responseType: 'blob',
      signal: expect.any(AbortSignal),
    })
    expect(hook.result.current.previewUrl).toBe('blob:https://baander.app/preview')
    const readsBeforeHiding = vi.mocked(AXIOS_INSTANCE.get).mock.calls.length
    coordinator.setVisible(false)
    await act(async () => { await vi.advanceTimersByTimeAsync(15000) })
    expect(AXIOS_INSTANCE.get).toHaveBeenCalledTimes(readsBeforeHiding)
    coordinator.setVisible(true)
    await act(async () => { await vi.advanceTimersByTimeAsync(200) })
    expect(vi.mocked(AXIOS_INSTANCE.get).mock.calls.length).toBeGreaterThan(readsBeforeHiding)
    hook.unmount()
    const readsAfterClose = vi.mocked(AXIOS_INSTANCE.get).mock.calls.length
    await act(async () => { await vi.advanceTimersByTimeAsync(10000) })
    expect(AXIOS_INSTANCE.get).toHaveBeenCalledTimes(readsAfterClose)
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:https://baander.app/preview')
  })
})
