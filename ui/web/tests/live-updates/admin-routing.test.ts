import { QueryObserver } from '@tanstack/react-query'
import { afterEach, expect, it, vi } from 'vitest'
import { adminLiveDescriptors } from '@/features/admin/live-descriptors'
import { OwnedQueryClient } from '@/shared/live/query-client'
import { LiveQueryCoordinator } from '@/shared/live/query-coordinator'
import type { LiveFamily } from '@/shared/live/protocol'
import type { LiveRecovery, LiveSnapshot } from '@/shared/live/recovery'

const rows: { name: string; family: LiveFamily; key: readonly unknown[] }[] = [
  { name: 'activity', family: 'activity', key: ['admin', 'activity', 'summary', 'week'] },
  { name: 'metadata', family: 'admin-metadata', key: ['admin-genres', { flat: true }] },
  { name: 'recommendation jobs', family: 'admin-metadata', key: ['admin', 'recommendations', 'job', 'one'] },
  { name: 'configuration files', family: 'admin-runtime', key: ['config-check'] },
  { name: 'runtime', family: 'admin-runtime', key: ['server-stats'] },
  { name: 'spans', family: 'admin-runtime', key: ['debug', 'spans', 10] },
  { name: 'job variants', family: 'admin-jobs', key: ['job-list', { status: 'running' }] },
  { name: 'analytics', family: 'admin-jobs', key: ['analytics-summary', 'week'] },
  { name: 'login history', family: 'admin-security', key: ['admin-login-blocks', { page: 2 }] },
  { name: 'metadata coverage', family: 'admin-metadata', key: ['admin', 'lyrics', 'coverage'] },
  { name: 'media', family: 'admin-media', key: ['admin-transcode-sessions'] },
  { name: 'radio', family: 'radio', key: ['admin', 'radio', 'stations', { country: 'DK' }] },
  { name: 'rate expiry', family: 'admin-security', key: ['rate-limiters'] },
  { name: 'scheduler', family: 'admin-jobs', key: ['admin-scheduler', 'jobs'] },
  { name: 'users', family: 'admin-users', key: ['admin-users', { page: 2 }] },
  { name: 'alerts', family: 'admin-alerts', key: ['admin-alerts', 'unread'] },
  { name: 'settings', family: 'admin-settings', key: ['/api/admin/settings'] },
]

afterEach(() => vi.useRealTimers())

it.each(rows)('routes $name hints through active queries and leaves inactive variants stale', async ({ family, key }) => {
  vi.useFakeTimers()
  const client = new OwnedQueryClient()
  const snapshot: LiveSnapshot = {
    status: 'current', authorityReady: true, families: [family],
    authorizationFingerprint: 'a'.repeat(64), health: { state: 'current', oldestPendingAgeMs: null },
  }
  const coordinator = new LiveQueryCoordinator(client, {
    getSnapshot: () => snapshot, subscribe: () => () => undefined,
    subscribeEvents: () => () => undefined, completeRecovery: () => undefined,
    resourceReadFailed: vi.fn(),
  }, adminLiveDescriptors)
  client.setCoordinator(coordinator)
  const stop = coordinator.start()
  let value = 'before'
  const observer = new QueryObserver(client, { queryKey: key, queryFn: async () => value, staleTime: Infinity })
  const unsubscribe = observer.subscribe(() => undefined)
  await vi.advanceTimersByTimeAsync(100)
  expect(observer.getCurrentResult().data).toBe('before')
  const inactive = [...key, 'inactive']
  client.setQueryData(inactive, 'old')
  value = 'after'
  coordinator.hint([family])
  await vi.advanceTimersByTimeAsync(1100)
  expect(observer.getCurrentResult().data).toBe('after')
  expect(client.getQueryData(inactive)).toBe('old')
  expect(client.getQueryState(inactive)?.isInvalidated).toBe(true)
  unsubscribe()
  stop()
  client.clear()
})

it('keeps demand scans manual and assigns provider presence to deployment definitions', () => {
  const scan = adminLiveDescriptors.filter(row => row.matches(['admin-media-missing-check']))
  expect(scan).toHaveLength(1)
  expect(scan[0].refreshPolicy).toBe('demand')
  const provider = adminLiveDescriptors.filter(row => row.matches(['admin-metadata-providers']))
  expect(provider).toHaveLength(1)
  expect(provider[0].family).toBe('deployment-definitions')
  expect(provider[0].deploymentFixed).toBe(true)
  const config = adminLiveDescriptors.filter(row => row.matches(['config-check']))
  expect(config).toHaveLength(1)
  expect(config[0].sampleIntervalMs).toBe(30000)
})

it('reloads deployment definitions on a new server baseline while keeping missing-media scans demand driven', async () => {
  vi.useFakeTimers()
  const client = new OwnedQueryClient()
  const snapshot: LiveSnapshot = {
    status: 'current', authorityReady: true, families: ['deployment-definitions', 'admin-media'],
    authorizationFingerprint: 'a'.repeat(64), health: { state: 'current', oldestPendingAgeMs: null },
  }
  let emit!: Parameters<LiveRecovery['subscribeEvents']>[0]
  const coordinator = new LiveQueryCoordinator(client, {
    getSnapshot: () => snapshot, subscribe: () => () => undefined,
    subscribeEvents: listener => { emit = listener; return () => undefined },
    completeRecovery: () => undefined, resourceReadFailed: vi.fn(),
  }, adminLiveDescriptors)
  client.setCoordinator(coordinator)
  const stop = coordinator.start()
  let revision = 'old deployment'
  const keys = [['admin-scheduler-commands'], ['/api/admin/settings/definitions'], ['admin-metadata-providers']]
  const observers = keys.map(queryKey => new QueryObserver(client, {
    queryKey, queryFn: async () => revision, staleTime: Infinity,
  }))
  const unsubscribes = observers.map(observer => observer.subscribe(() => undefined))
  const scan = vi.fn(async () => 'scan')
  const scanObserver = new QueryObserver(client, {
    queryKey: ['admin-media-missing-check'], queryFn: scan, enabled: false,
  })
  const unsubscribeScan = scanObserver.subscribe(() => undefined)
  await vi.advanceTimersByTimeAsync(100)
  expect(observers.map(observer => observer.getCurrentResult().data)).toEqual(keys.map(() => 'old deployment'))
  revision = 'new deployment'
  emit({ type: 'baseline', authority: {
    version: 1, families: [...snapshot.families],
    authorizationFingerprint: 'a'.repeat(64), health: snapshot.health,
  } })
  await vi.advanceTimersByTimeAsync(1100)
  expect(observers.map(observer => observer.getCurrentResult().data)).toEqual(keys.map(() => 'new deployment'))
  coordinator.hint(['admin-media'])
  await vi.advanceTimersByTimeAsync(31000)
  expect(scan).not.toHaveBeenCalled()
  await scanObserver.refetch()
  expect(scan).toHaveBeenCalledTimes(1)
  unsubscribes.forEach(unsubscribe => unsubscribe())
  unsubscribeScan()
  stop()
  client.clear()
})

it.each([
  { key: ['server-stats'], milliseconds: 5000 },
  { key: ['debug', 'coroutines'], milliseconds: 5000 },
  { key: ['debug', 'workers'], milliseconds: 5000 },
  { key: ['debug', 'spans', 10], milliseconds: 10000 },
  { key: ['job-list', { page: 1 }], milliseconds: 5000 },
  { key: ['job-detail', 'job'], milliseconds: 5000 },
  { key: ['status-overview'], milliseconds: 5000 },
  { key: ['analytics-summary'], milliseconds: 30000 },
  { key: ['transport-status'], milliseconds: 30000 },
  { key: ['admin-media-storage-stats'], milliseconds: 5000 },
  { key: ['admin-transcode-sessions'], milliseconds: 5000 },
  { key: ['rate-limiters'], milliseconds: 5000 },
  { key: ['admin', 'activity', 'summary', 'week'], milliseconds: 30000 },
  { key: ['admin-metadata-sync-status'], milliseconds: 30000 },
  { key: ['admin', 'lyrics', 'coverage'], milliseconds: 30000 },
  { key: ['admin', 'recommendations', 'coverage'], milliseconds: 30000 },
  { key: ['admin', 'recommendations', 'jobs', 10], milliseconds: 5000 },
  { key: ['config-check'], milliseconds: 30000 },
])('gives $key one shared $milliseconds ms sampling policy', ({ key, milliseconds }) => {
  const matches = adminLiveDescriptors.filter(descriptor => descriptor.matches(key))
  expect(matches).toHaveLength(1)
  expect(matches[0].sampleIntervalMs).toBe(milliseconds)
})
