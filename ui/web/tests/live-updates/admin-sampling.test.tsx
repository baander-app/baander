import { recommendationsAdminApi } from '@/features/admin/api/recommendations-admin-api'
import { getSpans } from '@/features/admin/api/debug-api'
import { getAnalyticsSummary } from '@/features/admin/api/job-monitor-api'
import { metadataAdminApi } from '@/features/admin/api/metadata-admin-api'
import { transcodeAdminApi } from '@/features/admin/api/transcode-admin-api'
import { getLiveReadContext } from '@/shared/live/http-admission'
import { QueryObserver } from '@tanstack/react-query'
import { AxiosHeaders, type InternalAxiosRequestConfig } from 'axios'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { OwnedQueryClient } from '@/shared/live/query-client'
import { LiveQueryCoordinator } from '@/shared/live/query-coordinator'
import { adminLiveDescriptors } from '@/features/admin/live-descriptors'
import { getServerStats } from '@/features/admin/api/server-stats-api'
import { getJobList, getJobDetail, getStatusOverview } from '@/features/admin/api/job-monitor-api'
import { getConfigCheck } from '@/features/admin/api/config-check-api'
import { getRateLimiters } from '@/features/admin/api/rate-limiter-api'
import { advanceAuthorization } from '@/shared/live/ownership'
import type { LiveRecoveryEvent, LiveSnapshot } from '@/shared/live/recovery'

vi.mock('@/features/auth/stores/auth-store', () => ({
  useAuthStore: { getState: () => ({ accessToken: null, refreshToken: null }) },
}))
vi.mock('@/shared/crypto/dpop-store', () => ({
  getDpopKeyPair: () => null, getDpopNonce: () => null, setDpopNonce: vi.fn(),
}))

const previousAdapter = AXIOS_INSTANCE.defaults.adapter
const resources: Array<() => void> = []
afterEach(() => {
  resources.splice(0).reverse().forEach(cleanup => cleanup())
  AXIOS_INSTANCE.defaults.adapter = previousAdapter
  vi.useRealTimers()
})

function setup() {
  vi.useFakeTimers({ now: 100_000 })
  const client = new OwnedQueryClient()
  const snapshot: LiveSnapshot = {
    status: 'current', authorityReady: true,
    families: ['admin-runtime', 'admin-jobs', 'admin-security', 'admin-metadata', 'admin-media', 'deployment-definitions'],
    authorizationFingerprint: 'a'.repeat(64),
    health: { state: 'current', oldestPendingAgeMs: null },
  }
  let emit: (event: LiveRecoveryEvent) => void = () => undefined
  const recovery = {
    getSnapshot: () => snapshot,
    subscribe: () => () => undefined,
    subscribeEvents: (listener: typeof emit) => { emit = listener; return () => undefined },
    completeRecovery: vi.fn(), resourceReadFailed: vi.fn(),
  }
  const coordinator = new LiveQueryCoordinator(client, recovery, adminLiveDescriptors)
  client.setCoordinator(coordinator)
  resources.push(() => client.dispose(), client.attach(), coordinator.start())
  const baseline = () => emit({ type: 'baseline', authority: {
    version: 1, families: snapshot.families,
    authorizationFingerprint: snapshot.authorizationFingerprint!, health: snapshot.health,
  } })
  baseline()
  return { client, coordinator, baseline }
}

function respond(config: InternalAxiosRequestConfig, data: unknown) {
  return { config, status: 200, statusText: 'OK', headers: new AxiosHeaders(), data: { data } }
}

function observe<T>(client: OwnedQueryClient, key: readonly unknown[], read: (signal: AbortSignal) => Promise<T>) {
  const observer = new QueryObserver(client, {
    queryKey: key,
    queryFn: ({ signal }) => read(signal),
    retry: false,
  })
  resources.push(observer.subscribe(() => undefined))
  return observer
}

describe('administrative shared sampling', () => {
  it('shares the server gauge clock between observers, stops hidden samples and reconciles on return', async () => {
    const { client, coordinator } = setup()
    let version = 0
    const adapter = vi.fn(async (config: InternalAxiosRequestConfig) => respond(config, { version: ++version }))
    AXIOS_INSTANCE.defaults.adapter = adapter
    observe(client, ['server-stats'], getServerStats)
    observe(client, ['server-stats'], getServerStats)
    await vi.advanceTimersByTimeAsync(200)
    const initial = adapter.mock.calls.length
    await vi.advanceTimersByTimeAsync(5000)
    expect(adapter.mock.calls.length).toBe(initial + 1)
    coordinator.setVisible(false)
    await vi.advanceTimersByTimeAsync(15000)
    expect(adapter.mock.calls.length).toBe(initial + 1)
    coordinator.setVisible(true)
    await vi.advanceTimersByTimeAsync(200)
    expect(adapter.mock.calls.length).toBe(initial + 2)
    expect(adapter.mock.calls.every(([config]) => config.signal instanceof AbortSignal)).toBe(true)
  })

  it('routes worker hints to filtered jobs and details and holds local command refreshes during outage', async () => {
    const { client, coordinator } = setup()
    let revision = 'before'
    AXIOS_INSTANCE.defaults.adapter = async config => respond(config, { revision })
    observe(client, ['job-list', { status: 'running' }], signal => getJobList({ status: 'running' }, signal))
    observe(client, ['job-list', { status: 'failed' }], signal => getJobList({ status: 'failed' }, signal))
    observe(client, ['job-detail', 'job'], signal => getJobDetail('job', signal))
    observe(client, ['status-overview'], getStatusOverview)
    await vi.advanceTimersByTimeAsync(200)
    revision = 'worker'
    coordinator.hint(['admin-jobs'])
    await vi.advanceTimersByTimeAsync(1000)
    expect(client.getQueryData(['job-detail', 'job'])).toEqual({ revision: 'worker' })
    const release = coordinator.beginMutation(['admin-jobs'])
    revision = 'command'
    coordinator.hint(['admin-jobs'])
    await vi.advanceTimersByTimeAsync(1000)
    expect(client.getQueryData(['job-detail', 'job'])).toEqual({ revision: 'worker' })
    release()
    await vi.advanceTimersByTimeAsync(1000)
    for (const query of client.getQueryCache().getAll()) expect(query.state.data).toEqual({ revision: 'command' })
  })

  it('samples mutable configuration files and expiring counters without a stored write', async () => {
    const { client } = setup()
    let version = 'before'
    AXIOS_INSTANCE.defaults.adapter = async config => respond(config, { version })
    observe(client, ['config-check'], getConfigCheck)
    observe(client, ['rate-limiters'], getRateLimiters)
    await vi.advanceTimersByTimeAsync(200)
    version = 'after'
    await vi.advanceTimersByTimeAsync(5000)
    expect(client.getQueryData(['rate-limiters'])).toEqual({ version: 'after' })
    expect(client.getQueryData(['config-check'])).toEqual({ version: 'before' })
    await vi.advanceTimersByTimeAsync(25000)
    expect(client.getQueryData(['config-check'])).toEqual({ version: 'after' })
  })

  it.each([
    { key: ['debug', 'spans', 30], interval: 10000, read: (signal: AbortSignal) => getSpans(30, signal) },
    { key: ['analytics-summary', 'from', 'to'], interval: 30000, read: (signal: AbortSignal) => getAnalyticsSummary('from', 'to', signal) },
    { key: ['admin-metadata-sync-status'], interval: 30000, read: (signal: AbortSignal) => metadataAdminApi.getSyncStatus(signal) },
    { key: ['admin-transcode-sessions'], interval: 5000, read: (signal: AbortSignal) => transcodeAdminApi.getSessions(signal) },
    { key: ['admin', 'recommendations', 'job', 'job'], interval: 5000, read: (signal: AbortSignal) => recommendationsAdminApi.getJob('job', signal).then(response => response.data.data) },
  ])('samples $key through its declared $interval ms policy', async ({ key, interval, read }) => {
    const { client } = setup()
    let revision = 'before'
    AXIOS_INSTANCE.defaults.adapter = async config => respond(config, { revision })
    observe<unknown>(client, key, read)
    await vi.advanceTimersByTimeAsync(200)
    revision = 'after'
    await vi.advanceTimersByTimeAsync(interval)
    expect(JSON.stringify(client.getQueryData(key))).toContain('"revision":"after"')
  })

  it('samples transcode sessions through their canonical collection route', async () => {
    const { client } = setup()
    let revision = 'before'
    const adapter = vi.fn(async (config: InternalAxiosRequestConfig) => {
      if (config.url !== '/api/transcode/sessions/') throw new Error('Collection route requires its canonical path')
      return respond(config, [{ publicId: 'session', state: revision }])
    })
    AXIOS_INSTANCE.defaults.adapter = adapter
    observe(client, ['admin-transcode-sessions'], signal => transcodeAdminApi.getSessions(signal))
    await vi.advanceTimersByTimeAsync(200)
    expect(client.getQueryData(['admin-transcode-sessions'])).toEqual([{ publicId: 'session', state: 'before' }])
    const initialReads = adapter.mock.calls.length
    revision = 'after'
    await vi.advanceTimersByTimeAsync(5000)
    expect(client.getQueryData(['admin-transcode-sessions'])).toEqual([{ publicId: 'session', state: 'after' }])
    expect(adapter.mock.calls.length).toBeGreaterThan(initialReads)
  })

  it('aborts handwritten HTTP on authority loss and discards a late adapter result', async () => {
    const { client } = setup()
    let release!: () => void
    let signal: AbortSignal | undefined
    AXIOS_INSTANCE.defaults.adapter = config => new Promise(resolve => {
      signal = config.signal as AbortSignal
      release = () => resolve(respond(config, { protected: true }))
    })
    observe(client, ['job-detail', 'job'], requestSignal => getJobDetail('job', requestSignal))
    await vi.advanceTimersByTimeAsync(200)
    expect(signal?.aborted).toBe(false)
    expect(getLiveReadContext(signal)).toEqual({ family: 'admin-jobs', priority: 'background' })
    advanceAuthorization()
    expect(signal?.aborted).toBe(true)
    release()
    await vi.advanceTimersByTimeAsync(1000)
    expect(client.getQueryData(['job-detail', 'job'])).toBeUndefined()
  })
})
