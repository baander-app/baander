import Axios, { AxiosHeaders, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { QueryObserver } from '@tanstack/react-query'
import { afterEach, expect, it, vi } from 'vitest'

vi.mock('@/features/auth/stores/auth-store', () => ({
  useAuthStore: { getState: () => ({ accessToken: null, refreshToken: null }) },
}))
vi.mock('@/shared/crypto/dpop-store', () => ({
  getDpopKeyPair: () => null,
  getDpopNonce: () => null,
  setDpopNonce: vi.fn(),
}))

import { getGetTranscodeJobListQueryOptions } from '@/shared/api-client/gen/endpoints'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { currentLiveReadSignal, getLiveReadContext, withLiveReadSignal } from '@/shared/live/http-admission'
import { OwnedQueryClient } from '@/shared/live/query-client'
import { LiveQueryCoordinator } from '@/shared/live/query-coordinator'
import { ownershipForSignal, setAuthorityPending, synchronizeOwnership } from '@/shared/live/ownership'
import type { LiveSnapshot } from '@/shared/live/recovery'

const flush = async () => {
  for (let step = 0; step < 30; step++) {
    await Promise.resolve()
  }
}
const disposals: Array<() => void> = []
afterEach(() => {
  disposals.splice(0).reverse().forEach(dispose => dispose())
  setAuthorityPending(false)
  synchronizeOwnership(null)
})

it('restores nested signal scopes synchronously even when a callback throws or returns a pending promise', async () => {
  const outer = new AbortController().signal
  const inner = new AbortController().signal
  let finish!: () => void
  const pending = new Promise<void>(resolve => {
    finish = resolve
  })
  const returned = withLiveReadSignal(outer, () => {
    expect(currentLiveReadSignal()).toBe(outer)
    expect(() => withLiveReadSignal(inner, () => {
      expect(currentLiveReadSignal()).toBe(inner)
      throw new Error('read failed')
    })).toThrow('read failed')
    expect(currentLiveReadSignal()).toBe(outer)
    return pending
  })
  expect(currentLiveReadSignal()).toBeUndefined()
  finish()
  await returned
})

it('normalizes generated option overrides to query ownership, admission, and cancellation', async () => {
  synchronizeOwnership('generated-signal-account')
  setAuthorityPending(false)
  const previousAdapter = AXIOS_INSTANCE.defaults.adapter
  disposals.push(() => {
    AXIOS_INSTANCE.defaults.adapter = previousAdapter
  })
  const client = new OwnedQueryClient()
  disposals.push(() => client.dispose())
  const snapshot: LiveSnapshot = {
    status: 'current',
    families: ['admin-media'],
    authorizationFingerprint: 'a'.repeat(64),
    health: { state: 'current', oldestPendingAgeMs: null },
    authorityReady: true,
  }
  const coordinator = new LiveQueryCoordinator(client, {
    getSnapshot: () => snapshot,
    subscribe: () => () => undefined,
    subscribeEvents: () => () => undefined,
    completeRecovery: () => undefined,
    resourceReadFailed: () => undefined,
  }, [{ family: 'admin-media', matches: () => true }])
  client.coordinator = coordinator
  disposals.push(coordinator.start())
  const dispatched: InternalAxiosRequestConfig[] = []
  const finish: Array<() => void> = []
  AXIOS_INSTANCE.defaults.adapter = config => new Promise((resolve, reject) => {
    dispatched.push(config)
    const result: AxiosResponse = {
      config,
      status: 200,
      statusText: 'OK',
      headers: new AxiosHeaders(),
      data: {},
    }
    finish.push(() => resolve(result))
    config.signal?.addEventListener?.('abort', () => reject(new Axios.CanceledError('Query cancelled', config)))
  })
  const overridden = new AbortController()
  const observers = Array.from({ length: 5 }, (_, index) => new QueryObserver(client, getGetTranscodeJobListQueryOptions({
    query: { queryKey: ['/api/transcode/jobs/list', index], retry: false },
    request: { signal: overridden.signal },
  })))
  observers.forEach(observer => disposals.push(observer.subscribe(() => undefined)))
  await flush()
  expect(dispatched).toHaveLength(4)
  for (const config of dispatched) {
    expect(config.signal).not.toBe(overridden.signal)
    expect(getLiveReadContext(config.signal)).toEqual({ family: 'admin-media', priority: 'background' })
    expect(ownershipForSignal(config.signal!)).toBeDefined()
  }
  overridden.abort()
  expect(dispatched.every(config => !config.signal?.aborted)).toBe(true)
  await client.cancelQueries({ queryKey: ['/api/transcode/jobs/list', 0], exact: true })
  await flush()
  expect(dispatched[0].signal?.aborted).toBe(true)
  await vi.waitFor(() => expect(dispatched).toHaveLength(5))
  finish.forEach(done => done())
  await flush()
  expect(observers.slice(1).every(observer => observer.getCurrentResult().isSuccess)).toBe(true)
})
