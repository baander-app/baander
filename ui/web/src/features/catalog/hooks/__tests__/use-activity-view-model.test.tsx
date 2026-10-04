import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, renderHook, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import MockAdapter from 'axios-mock-adapter'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { useActivityViewModel } from '../use-activity-view-model'

vi.mock('@/features/auth/stores/auth-store', () => ({
  useAuthStore: { getState: () => ({ accessToken: null, refreshToken: null }) },
}))
vi.mock('@/shared/crypto/dpop-store', () => ({
  getDpopKeyPair: () => null, getDpopNonce: () => null, setDpopNonce: vi.fn(),
}))
vi.mock('@/shared/crypto/dpop-proof', () => ({ createDpopProof: vi.fn() }))

function makeEntry(uuid: string, playCount = 1) {
  const timestamp = new Date().toISOString()
  return { uuid, publicId: uuid, userId: 'user1', activityType: 'play', playCount,
    love: false, createdAt: timestamp, lastPlayedAt: timestamp, songTitle: `Song ${uuid}` }
}

function deferredPage() {
  let resolve: (value: [number, { data: ReturnType<typeof makeEntry>[] }]) => void = () => {}
  const promise = new Promise<[number, { data: ReturnType<typeof makeEntry>[] }]>(done => { resolve = done })
  return { promise, resolve }
}

function createWrapper() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
}

const firstUrl = '/api/activity/history?limit=2&offset=0'
const nextUrl = '/api/activity/history?limit=2&offset=2'

describe('useActivityViewModel with the generated API client', () => {
  let api: MockAdapter
  beforeEach(() => { api = new MockAdapter(AXIOS_INSTANCE, { onNoMatch: 'throwException' }) })
  afterEach(() => { cleanup(); api.restore() })

  it.each([{ terminal: [] }, { terminal: [makeEntry('c')] }])('stops after a terminal page and retains previous rows: $terminal', async ({ terminal }) => {
    api.onGet(firstUrl).reply(200, { data: [makeEntry('a'), makeEntry('b')] })
    api.onGet(nextUrl).reply(200, { data: terminal })
    const { result } = renderHook(() => useActivityViewModel({ limit: 2 }), { wrapper: createWrapper() })
    await waitFor(() => expect(result.current.hasMore).toBe(true))
    act(() => result.current.loadMore())
    await waitFor(() => expect(result.current.hasMore).toBe(false))
    expect(result.current.groups.flatMap(group => group.items)).toHaveLength(2 + terminal.length)
    act(() => result.current.loadMore())
    expect(api.history.get).toHaveLength(2)
  })

  it('deduplicates within and across pages while updating existing entries and using raw page counts', async () => {
    api.onGet(firstUrl).reply(200, { data: [makeEntry('a'), makeEntry('a', 2)] })
    api.onGet(nextUrl).reply(200, { data: [makeEntry('a', 3)] })
    const { result } = renderHook(() => useActivityViewModel({ limit: 2 }), { wrapper: createWrapper() })
    await waitFor(() => expect(result.current.hasMore).toBe(true))
    expect(result.current.groups[0].items).toHaveLength(1)
    expect(result.current.groups[0].items[0].playCount).toBe(2)
    act(() => result.current.loadMore())
    await waitFor(() => expect(result.current.hasMore).toBe(false))
    expect(result.current.groups[0].items[0].playCount).toBe(3)
    expect(result.current.groups[0].items[0].songTitle).toBe('Song a')
  })

  it('coalesces concurrent load-more calls and exposes loading state', async () => {
    const pending = deferredPage()
    api.onGet(firstUrl).reply(200, { data: [makeEntry('a'), makeEntry('b')] })
    api.onGet(nextUrl).reply(() => pending.promise)
    const { result } = renderHook(() => useActivityViewModel({ limit: 2 }), { wrapper: createWrapper() })
    await waitFor(() => expect(result.current.hasMore).toBe(true))
    act(() => { result.current.loadMore(); result.current.loadMore(); result.current.loadMore() })
    await waitFor(() => expect(result.current.isFetchingMore).toBe(true))
    expect(api.history.get).toHaveLength(2)
    await act(async () => { pending.resolve([200, { data: [] }]) })
    await waitFor(() => expect(result.current.hasMore).toBe(false))
  })

  it('cancels the old request on a limit change and ignores its late page', async () => {
    const pending = deferredPage()
    api.onGet(firstUrl).reply(200, { data: [makeEntry('a'), makeEntry('b')] })
    api.onGet(nextUrl).reply(() => pending.promise)
    api.onGet('/api/activity/history?limit=3&offset=0').reply(200, { data: [makeEntry('new')] })
    const { result, rerender } = renderHook(({ limit }) => useActivityViewModel({ limit }), {
      initialProps: { limit: 2 }, wrapper: createWrapper(),
    })
    await waitFor(() => expect(result.current.hasMore).toBe(true))
    act(() => result.current.loadMore())
    await waitFor(() => expect(api.history.get).toHaveLength(2))
    const oldSignal = api.history.get[1].signal
    rerender({ limit: 3 })
    await waitFor(() => expect(result.current.groups[0].items[0].uuid).toBe('new'))
    expect(oldSignal?.aborted).toBe(true)
    await act(async () => { pending.resolve([200, { data: [makeEntry('late')] }]) })
    expect(result.current.groups.flatMap(group => group.items).map(entry => entry.uuid)).toEqual(['new'])
    expect(result.current.hasMore).toBe(false)
  })

  it('cancels an in-flight page on unmount', async () => {
    const pending = deferredPage()
    api.onGet(firstUrl).reply(() => pending.promise)
    const { unmount } = renderHook(() => useActivityViewModel({ limit: 2 }), { wrapper: createWrapper() })
    await waitFor(() => expect(api.history.get).toHaveLength(1))
    const signal = api.history.get[0].signal
    unmount()
    expect(signal?.aborted).toBe(true)
    await act(async () => { pending.resolve([200, { data: [] }]) })
  })

  it('retains rows after a page failure and retries the same offset', async () => {
    api.onGet(firstUrl).reply(200, { data: [makeEntry('a'), makeEntry('b')] })
    api.onGet(nextUrl).replyOnce(500, { error: { message: 'Failed' } })
    api.onGet(nextUrl).reply(200, { data: [makeEntry('c')] })
    const { result } = renderHook(() => useActivityViewModel({ limit: 2 }), { wrapper: createWrapper() })
    await waitFor(() => expect(result.current.hasMore).toBe(true))
    act(() => result.current.loadMore())
    await waitFor(() => expect(result.current.isFetchMoreError).toBe(true))
    expect(result.current.groups[0].items).toHaveLength(2)
    expect(result.current.error).toBeTruthy()
    act(() => result.current.loadMore())
    await waitFor(() => expect(result.current.groups[0].items).toHaveLength(3))
    expect(result.current.isFetchMoreError).toBe(false)
    expect(result.current.hasMore).toBe(false)
    expect(api.history.get.map(request => request.url)).toEqual([firstUrl, nextUrl, nextUrl])
  })

  it('replaces updated and removed rows after refetch', async () => {
    api.onGet(firstUrl).replyOnce(200, { data: [makeEntry('a'), makeEntry('b')] })
    api.onGet(firstUrl).reply(200, { data: [makeEntry('a', 4)] })
    const { result } = renderHook(() => useActivityViewModel({ limit: 2 }), { wrapper: createWrapper() })
    await waitFor(() => expect(result.current.hasMore).toBe(true))
    await act(async () => { await result.current.refetch() })
    await waitFor(() => expect(result.current.groups[0].items).toHaveLength(1))
    expect(result.current.groups[0].items[0].playCount).toBe(4)
    expect(result.current.hasMore).toBe(false)
  })

  it('groups entries in period order', async () => {
    api.onGet(firstUrl).reply(200, { data: [
      { ...makeEntry('yesterday'), lastPlayedAt: new Date(Date.now() - 86400000).toISOString() },
      makeEntry('today'),
    ] })
    const { result } = renderHook(() => useActivityViewModel({ limit: 2 }), { wrapper: createWrapper() })
    await waitFor(() => expect(result.current.groups).toHaveLength(2))
    expect(result.current.groups.map(group => group.label)).toEqual(['Today', 'Yesterday'])
  })
})
