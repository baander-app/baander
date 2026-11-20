import { QueryObserver } from '@tanstack/react-query'
import { afterEach, expect, it, vi } from 'vitest'
import { OwnedQueryClient } from '@/shared/live/query-client'
import { LiveQueryCoordinator } from '@/shared/live/query-coordinator'
import type { LiveSnapshot } from '@/shared/live/recovery'
import { adminLiveDescriptors } from '../../../live-descriptors'

afterEach(() => vi.useRealTimers())

it('removes sampling demand when tail and source observers pause', async () => {
  vi.useFakeTimers()
  const client = new OwnedQueryClient()
  const snapshot: LiveSnapshot = {
    status: 'current', authorityReady: true, families: ['admin-runtime'],
    authorizationFingerprint: 'a'.repeat(64), health: { state: 'current', oldestPendingAgeMs: null },
  }
  const coordinator = new LiveQueryCoordinator(client, {
    getSnapshot: () => snapshot, subscribe: () => () => undefined,
    subscribeEvents: () => () => undefined, completeRecovery: () => undefined,
    resourceReadFailed: vi.fn(),
  }, adminLiveDescriptors)
  client.setCoordinator(coordinator)
  const stop = coordinator.start()
  const read = vi.fn(async () => 'record')
  const variants = ['tail', 'sources-live']
  const observers = variants.map(variant => new QueryObserver(client, {
    queryKey: ['admin', 'logs', variant], queryFn: read,
  }))
  const unsubscribe = observers.map(observer => observer.subscribe(() => undefined))
  await vi.advanceTimersByTimeAsync(100)
  const initialReads = read.mock.calls.length
  expect(initialReads).toBeGreaterThanOrEqual(2)
  await vi.advanceTimersByTimeAsync(5500)
  expect(read).toHaveBeenCalledTimes(initialReads + 2)
  observers.forEach((observer, index) => observer.setOptions({
    queryKey: ['admin', 'logs', variants[index]], queryFn: read, enabled: false,
  }))
  await vi.advanceTimersByTimeAsync(15000)
  expect(read).toHaveBeenCalledTimes(initialReads + 2)
  expect(observers.map(observer => observer.getCurrentResult().data)).toEqual(['record', 'record'])
  unsubscribe.forEach(detach => detach())
  stop()
  client.clear()
})
