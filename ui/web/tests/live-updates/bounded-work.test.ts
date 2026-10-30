import Axios, { AxiosHeaders, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { InfiniteQueryObserver, QueryObserver } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/features/auth/stores/auth-store', () => ({
  useAuthStore: { getState: () => ({ accessToken: null, refreshToken: null }) },
}))
vi.mock('@/shared/crypto/dpop-store', () => ({
  getDpopKeyPair: () => null,
  getDpopNonce: () => null,
  setDpopNonce: vi.fn(),
}))

import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { getLiveReadContext, liveHttpAdmission } from '@/shared/live/http-admission'
import { OwnedQueryClient } from '@/shared/live/query-client'
import { LiveQueryCoordinator } from '@/shared/live/query-coordinator'
import { setAuthorityPending, synchronizeOwnership } from '@/shared/live/ownership'
import type { LiveSnapshot } from '@/shared/live/recovery'

const flush = async () => {
  for (let step = 0; step < 40; step++) {
    await Promise.resolve()
  }
}
const disposals: Array<() => void> = []
let clock = 1_000_000
beforeEach(() => {
  clock += 100_000
  vi.useFakeTimers({ now: clock })
  synchronizeOwnership('bounded-work-account')
  setAuthorityPending(false)
})
afterEach(async () => {
  disposals.splice(0).reverse().forEach(dispose => dispose())
  await flush()
  setAuthorityPending(false)
  synchronizeOwnership(null)
  vi.restoreAllMocks()
  vi.useRealTimers()
})

function harness(dataFor: (url: string) => unknown = url => ({ value: Number(url.split('/').at(-1)) })) {
  const client = new OwnedQueryClient()
  client.setDefaultOptions({ queries: { gcTime: Infinity, retry: false } })
  disposals.push(() => client.dispose())
  const snapshot: LiveSnapshot = {
    status: 'current',
    families: ['catalog'],
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
  }, [{ family: 'catalog', matches: () => true }])
  client.coordinator = coordinator
  disposals.push(coordinator.start())
  const previousAdapter = AXIOS_INSTANCE.defaults.adapter
  disposals.push(() => {
    AXIOS_INSTANCE.defaults.adapter = previousAdapter
  })
  let blocked = true
  let active = 0
  let maxActive = 0
  const starts: Array<{
    time: number
    url: string
    priority: string | undefined
  }> = []
  const held = new Set<() => void>()
  AXIOS_INSTANCE.defaults.adapter = config => new Promise((resolve, reject) => {
    active++
    maxActive = Math.max(maxActive, active)
    starts.push({ time: Date.now(), url: config.url ?? '', priority: getLiveReadContext(config.signal)?.priority })
    let settled = false
    const settle = (error?: Error) => {
      if (settled) return
      settled = true
      held.delete(finish)
      config.signal?.removeEventListener?.('abort', abort)
      active--
      if (error) reject(error)
      else resolve(response(config, dataFor(config.url ?? '')))
    }
    const finish = () => settle()
    const abort = () => settle(new Axios.CanceledError('Query cancelled', config))
    config.signal?.addEventListener?.('abort', abort)
    if (blocked) held.add(finish)
    else finish()
  })
  function unblock() {
    blocked = false
    const pending = [...held]
    pending.forEach(finish => finish())
  }
  function observe(id: number, foreground = false) {
    const observer = new QueryObserver(client, {
      queryKey: ['variant', id],
    gcTime: Infinity,
    retry: false,
      meta: foreground ? { livePriority: 'foreground' } : undefined,
      queryFn: async ({ signal }) => (await AXIOS_INSTANCE.get<{ value: number }>(`/api/resource/${id}`, { signal })).data.value,
    })
    disposals.push(observer.subscribe(() => undefined))
    return observer
  }
  return { client, coordinator, starts, unblock, observe, maxActive: () => maxActive }
}

function response(config: InternalAxiosRequestConfig, data: unknown): AxiosResponse {
  return {
    config,
    status: 200,
    statusText: 'OK',
    headers: new AxiosHeaders(),
    data,
  }
}

function expectStartBudget(starts: Array<{ time: number }>) {
  starts.forEach(start => {
    const window = starts.filter(candidate =>
      candidate.time >= start.time && candidate.time < start.time + 1000,
    )
    expect(window.length).toBeLessThanOrEqual(20)
  })
}

describe('bounded live resource work', () => {
  it('converges 300 active variants with bounded logical work, HTTP attempts, and timers', async () => {
    const { client, coordinator, starts, unblock, observe, maxActive } = harness()
    const acquire = liveHttpAdmission.acquire.bind(liveHttpAdmission)
    let pendingHttp = 0
    let peakPendingHttp = 0
    vi.spyOn(liveHttpAdmission, 'acquire').mockImplementation(async (...args) => {
      pendingHttp++
      peakPendingHttp = Math.max(peakPendingHttp, pendingHttp)
      try {
        return await acquire(...args)
      } finally {
        pendingHttp--
      }
    })
    const observers = Array.from({ length: 300 }, (_, id) => observe(id))
    await flush()
    expect(starts).toHaveLength(4)
    expect(client.isFetching()).toBeLessThanOrEqual(256)
    expect(coordinator.getWorkSnapshot().active).toBeLessThanOrEqual(256)
    expect(coordinator.getWorkSnapshot().background).toBeLessThanOrEqual(252)
    expect(liveHttpAdmission.getSnapshot().active + liveHttpAdmission.getSnapshot().queued).toBeLessThanOrEqual(256)
    expect(pendingHttp).toBeLessThanOrEqual(256)
    await vi.advanceTimersByTimeAsync(100)
    expect(starts).toHaveLength(4)
    expect(vi.getTimerCount()).toBeLessThanOrEqual(2)
    unblock()
    for (
      let second = 0;
      second < 20 && observers.some(observer => !observer.getCurrentResult().isSuccess);
      second++
    ) {
      await vi.advanceTimersByTimeAsync(1000)
      expect(client.isFetching()).toBeLessThanOrEqual(256)
      expect(coordinator.getWorkSnapshot().active).toBeLessThanOrEqual(256)
      expect(liveHttpAdmission.getSnapshot().active + liveHttpAdmission.getSnapshot().queued).toBeLessThanOrEqual(256)
      expect(vi.getTimerCount()).toBeLessThanOrEqual(2)
    }
    expect(observers.every((observer, id) => observer.getCurrentResult().data === id)).toBe(true)
    expect(starts).toHaveLength(300)
    expect(maxActive()).toBeLessThanOrEqual(4)
    expect(peakPendingHttp).toBeLessThanOrEqual(256)
    expectStartBudget(starts)
    await vi.advanceTimersByTimeAsync(100)
    expect(vi.getTimerCount()).toBe(0)
  })

  it('admits a late ordinary read before four retained ten-page chains finish', async () => {
    interface Page {
      rows: string[]
      next: number | undefined
    }
    const { client, coordinator, starts, unblock } = harness(url => {
      if (url === '/api/resource/999') return { value: 999 }
      const [, chain, cursor] = /\/chain\/(\d+)\?cursor=(\d+)/.exec(url) ?? []
      const page = Number(cursor)
      return { rows: [`chain-${chain}-row-${page}`], next: page < 9 ? page + 1 : undefined }
    })
    const chains = Array.from({ length: 4 }, (_, chain) => {
      const key = ['chain', chain]
      client.setQueryData(key, {
        pages: Array.from({ length: 10 }, (_, page) => ({
          rows: [`old-${chain}-${page}`],
          next: page < 9 ? page + 1 : undefined,
        })),
        pageParams: Array.from({ length: 10 }, (_, page) => page),
      })
      const observer = new InfiniteQueryObserver(client, {
        queryKey: key,
        gcTime: Infinity,
        retry: false,
        initialPageParam: 0,
        queryFn: async ({ signal, pageParam }) => {
          const response = await AXIOS_INSTANCE.get<Page>(`/api/chain/${chain}?cursor=${pageParam}`, { signal })
          return response.data
        },
        getNextPageParam: page => page.next,
      })
      disposals.push(observer.subscribe(() => undefined))
      return observer
    })
    coordinator.hint(['catalog'])
    await vi.advanceTimersByTimeAsync(100)
    expect(starts).toHaveLength(4)
    const late = new QueryObserver(client, {
      queryKey: ['ordinary'],
      gcTime: Infinity,
      retry: false,
      queryFn: async ({ signal }) => (await AXIOS_INSTANCE.get<{ value: number }>('/api/resource/999', { signal })).data.value,
    })
    disposals.push(late.subscribe(() => undefined))
    await flush()
    expect(starts).toHaveLength(4)
    unblock()
    await flush()
    const ordinaryStart = starts.findIndex(start => start.url === '/api/resource/999')
    expect(ordinaryStart).toBe(4)
    for (
      let second = 0;
      second < 5 && chains.some(chain => chain.getCurrentResult().isFetching);
      second++
    ) {
      await vi.advanceTimersByTimeAsync(1000)
    }
    expect(late.getCurrentResult().data).toBe(999)
    chains.forEach((chain, index) => {
      const data = chain.getCurrentResult().data
      expect(data?.pages).toHaveLength(10)
      expect(data?.pageParams).toEqual(Array.from({ length: 10 }, (_, page) => page))
      const rows = data?.pages.flatMap(page => page.rows) ?? []
      expect(new Set(rows).size).toBe(10)
      expect(rows).toEqual(Array.from({ length: 10 }, (_, page) => `chain-${index}-row-${page}`))
    })
    expect(starts).toHaveLength(41)
    expectStartBudget(starts)
  })

  it('prioritizes explicit user reads but refreshes their retained metadata as background work', async () => {
    const { coordinator, starts, unblock, observe } = harness()
    const background = Array.from({ length: 5 }, (_, id) => observe(id))
    await flush()
    expect(starts).toHaveLength(4)
    const foreground = observe(999, true)
    await flush()
    expect(starts.at(-1)).toMatchObject({ url: '/api/resource/999', priority: 'foreground' })
    expect(liveHttpAdmission.getSnapshot()).toMatchObject({ active: 5, background: 4 })
    expect(starts.some(start => start.url === '/api/resource/4')).toBe(false)
    unblock()
    await flush()
    expect(background.every(observer => observer.getCurrentResult().isSuccess)).toBe(true)
    expect(foreground.getCurrentResult().isSuccess).toBe(true)
    coordinator.hint(['catalog'])
    await vi.advanceTimersByTimeAsync(600)
    const foregroundReads = starts.filter(start => start.url === '/api/resource/999')
    expect(foregroundReads).toHaveLength(2)
    expect(foregroundReads[1].priority).toBe('background')
    expectStartBudget(starts)
  })
})
