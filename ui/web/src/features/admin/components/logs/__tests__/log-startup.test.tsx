import { act, cleanup, renderHook } from '@testing-library/react'
import { QueryClientProvider } from '@tanstack/react-query'
import { afterEach, expect, it, vi } from 'vitest'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { OwnedQueryClient } from '@/shared/live/query-client'
import { LiveQueryCoordinator } from '@/shared/live/query-coordinator'
import type { LiveSnapshot } from '@/shared/live/recovery'
import { adminLiveDescriptors } from '../../../live-descriptors'
import { useLogs } from '../../../hooks/use-logs'

vi.mock('@/shared/api-client/axios-instance', () => ({ AXIOS_INSTANCE: { get: vi.fn() } }))

afterEach(() => { cleanup(); vi.useRealTimers() })

it('loads the initial history after live authority becomes ready and then keeps the snapshot fixed', async () => {
  vi.useFakeTimers()
  const client = new OwnedQueryClient()
  let snapshot: LiveSnapshot = {
    status: 'recovering', authorityReady: false, families: ['admin-runtime'],
    authorizationFingerprint: 'a'.repeat(64), health: { state: 'current', oldestPendingAgeMs: null },
  }
  let ready = () => {}
  const coordinator = new LiveQueryCoordinator(client, {
    getSnapshot: () => snapshot,
    subscribe: listener => { ready = listener; return () => {} },
    subscribeEvents: () => () => {}, completeRecovery: () => {}, resourceReadFailed: vi.fn(),
  }, adminLiveDescriptors)
  client.setCoordinator(coordinator)
  const stop = coordinator.start()
  vi.mocked(AXIOS_INSTANCE.get).mockImplementation(async (_url, options) => ({ data: { data: {
    items: [], nextCursor: (options?.params as { cursor?: string } | undefined)?.cursor ? null : 'older', coverage: { complete: true, reasons: [] },
    snapshot: { id: 'initial-snapshot', createdAt: '2026-11-20T10:00:00Z' },
  } } }))
  const { result, unmount } = renderHook(() => useLogs({ sources: [], levels: [], channels: [], search: '' }, false), {
    wrapper: ({ children }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>,
  })
  await act(async () => { await vi.advanceTimersByTimeAsync(50) })
  expect(AXIOS_INSTANCE.get).not.toHaveBeenCalled()
  expect(result.current.history.isPending).toBe(true)
  await act(async () => {
    snapshot = { ...snapshot, status: 'current', authorityReady: true }
    ready()
    await vi.advanceTimersByTimeAsync(1500)
  })
  expect(AXIOS_INSTANCE.get).toHaveBeenCalledTimes(1)
  expect(result.current.history.isSuccess).toBe(true)
  await act(async () => { await result.current.history.fetchNextPage(); await vi.advanceTimersByTimeAsync(20) })
  expect(AXIOS_INSTANCE.get).toHaveBeenCalledTimes(2)
  expect(result.current.history.data?.pages).toHaveLength(2)
  await act(async () => {
    coordinator.hint(['admin-runtime'])
    await vi.advanceTimersByTimeAsync(15000)
  })
  expect(AXIOS_INSTANCE.get).toHaveBeenCalledTimes(2)
  unmount()
  stop()
  client.clear()
})
