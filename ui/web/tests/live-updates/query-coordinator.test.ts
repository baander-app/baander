import { QueryClient, QueryObserver, InfiniteQueryObserver } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { LiveQueryCoordinator } from '@/shared/live/query-coordinator'
import type { LiveQueryDescriptor } from '@/shared/live/descriptor-types'
import type { LiveRecoveryEvent, LiveSnapshot } from '@/shared/live/recovery'

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>(done => {
    resolve = done
  })
  return { resolve, promise }
}

function setup(descriptors: readonly LiveQueryDescriptor[] = [
  { family: 'catalog', matches: key => key[0] === 'catalog' },
]) {
  vi.useFakeTimers()
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false, staleTime: Infinity } },
  })
  let snapshot: LiveSnapshot = {
    status: 'recovering',
    authorityReady: false,
    families: ['catalog'],
    authorizationFingerprint: 'a'.repeat(64),
    health: { state: 'current', oldestPendingAgeMs: null },
  }
  let emit: (event: LiveRecoveryEvent) => void = () => undefined
  const recovery = {
    getSnapshot: () => snapshot,
    subscribe: () => () => undefined,
    subscribeEvents: (listener: typeof emit) => {
      emit = listener
      return () => undefined
    },
    completeRecovery: vi.fn(() => {
      snapshot = { ...snapshot, status: 'current' }
    }),
    resourceReadFailed: vi.fn(),
  }
  const coordinator = new LiveQueryCoordinator(client, recovery, descriptors)
  const stop = coordinator.start()
  const baseline = () => {
    snapshot = { ...snapshot, authorityReady: true }
    emit({
      type: 'baseline',
      authority: {
        version: 1,
        families: ['catalog'],
        authorizationFingerprint: 'a'.repeat(64),
        health: snapshot.health,
      },
    })
  }
  return { client, coordinator, recovery, baseline, stop }
}

afterEach(() => {
  vi.useRealTimers()
})

describe('live query coordination', () => {
  it('retains a hint during the initial read and waits for baseline before follow-up', async () => {
    const { client, coordinator, baseline, stop } = setup()
    const initial = deferred<string>()
    const read = vi.fn().mockImplementationOnce(() => initial.promise).mockResolvedValue('fresh')
    const observer = new QueryObserver(client, { queryKey: ['catalog'], queryFn: read })
    const unsubscribe = observer.subscribe(() => undefined)
    coordinator.hint(['catalog'])
    initial.resolve('old')
    await vi.advanceTimersByTimeAsync(1000)
    expect(read).toHaveBeenCalledTimes(1)
    baseline()
    await vi.advanceTimersByTimeAsync(100)
    expect(read).toHaveBeenCalledTimes(2)
    expect(client.getQueryData(['catalog'])).toBe('fresh')
    unsubscribe()
    stop()
    client.clear()
  })

  it('retries a failed read without another hint and keeps inactive variants stale', async () => {
    const { client, coordinator, recovery, baseline, stop } = setup()
    client.setQueryData(['catalog', 'inactive'], 'old')
    const read = vi.fn().mockRejectedValueOnce(new Error('503')).mockResolvedValue('fresh')
    const observer = new QueryObserver(client, { queryKey: ['catalog'], queryFn: read })
    const unsubscribe = observer.subscribe(() => undefined)
    baseline()
    await vi.advanceTimersByTimeAsync(200)
    expect(recovery.resourceReadFailed).toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(1500)
    expect(client.getQueryData(['catalog'])).toBe('fresh')
    expect(client.getQueryState(['catalog', 'inactive'])?.isInvalidated).toBe(true)
    coordinator.hint(['catalog'])
    unsubscribe()
    stop()
    client.clear()
  })

  it('retains disabled and hidden work, then reconciles on re-enable and visibility', async () => {
    const { client, coordinator, baseline, stop } = setup()
    const read = vi.fn(async () => 'fresh')
    const options = { queryKey: ['catalog'], queryFn: read, enabled: false }
    const observer = new QueryObserver(client, options)
    const unsubscribe = observer.subscribe(() => undefined)
    coordinator.setVisible(false)
    baseline()
    await vi.advanceTimersByTimeAsync(1000)
    expect(read).not.toHaveBeenCalled()
    coordinator.setVisible(true)
    observer.setOptions({ ...options, enabled: true })
    await vi.advanceTimersByTimeAsync(1000)
    expect(client.getQueryData(['catalog'])).toBe('fresh')
    unsubscribe()
    stop()
    client.clear()
  })

  it('holds background reads through optimistic writes and coalesces family hints', async () => {
    const { client, coordinator, baseline, stop } = setup()
    const read = vi.fn(async () => 'fresh')
    const observer = new QueryObserver(client, { queryKey: ['catalog'], queryFn: read })
    const unsubscribe = observer.subscribe(() => undefined)
    await vi.advanceTimersByTimeAsync(1)
    const release = coordinator.beginMutation(['catalog'])
    baseline()
    for (let identity = 0; identity < 1000; identity++) {
      coordinator.hint(['catalog'])
    }
    client.setQueryData(['catalog'], 'confirmed')
    await vi.advanceTimersByTimeAsync(1000)
    expect(client.getQueryData(['catalog'])).toBe('confirmed')
    expect(read).toHaveBeenCalledTimes(1)
    release()
    await vi.advanceTimersByTimeAsync(100)
    expect(read).toHaveBeenCalledTimes(2)
    unsubscribe()
    stop()
    client.clear()
  })

  it('serializes non-query readers and retains hints during a complete walk', async () => {
    const { client, coordinator, baseline, stop } = setup()
    const pending = deferred<void>()
    const read = vi.fn().mockImplementationOnce(() => pending.promise).mockResolvedValue(undefined)
    coordinator.registerAdapter({
      id: 'timeline',
      family: 'catalog',
      isActive: () => true,
      read,
    })
    baseline()
    await vi.advanceTimersByTimeAsync(100)
    coordinator.hint(['catalog'])
    await vi.advanceTimersByTimeAsync(600)
    expect(read).toHaveBeenCalledTimes(1)
    pending.resolve()
    await vi.advanceTimersByTimeAsync(100)
    expect(read).toHaveBeenCalledTimes(2)
    stop()
    client.clear()
  })

  it('does not schedule sampling work for an unauthorized family', async () => {
    const { client, coordinator, baseline, stop } = setup([{
      family: 'admin-runtime',
      matches: key => key[0] === 'catalog',
      sampleIntervalMs: 5000,
    }])
    const read = vi.fn(async () => 'authorized snapshot')
    const observer = new QueryObserver(client, {
      queryKey: ['catalog'],
      queryFn: read,
      gcTime: Infinity,
    })
    const unsubscribe = observer.subscribe(() => undefined)
    baseline()
    await vi.advanceTimersByTimeAsync(6000)
    expect(read).toHaveBeenCalledOnce()
    expect(coordinator.getWorkSnapshot().wakeScheduled).toBe(false)
    expect(vi.getTimerCount()).toBe(0)
    unsubscribe()
    stop()
    client.clear()
  })

  it('samples matching active variants together and pauses shared sampling when hidden', async () => {
    const { client, coordinator, baseline, stop } = setup([{
      family: 'catalog',
      matches: key => key[0] === 'catalog',
      sampleIntervalMs: 5000,
    }])
    const read = vi.fn(async () => 'fresh')
    const first = new QueryObserver(client, { queryKey: ['catalog', 'first'], queryFn: read })
    const second = new QueryObserver(client, { queryKey: ['catalog', 'second'], queryFn: read })
    const unsubscribers = [first.subscribe(() => undefined), second.subscribe(() => undefined)]
    baseline()
    await vi.advanceTimersByTimeAsync(1000)
    const beforeSample = read.mock.calls.length
    await vi.advanceTimersByTimeAsync(5000)
    expect(read.mock.calls.length - beforeSample).toBe(2)
    coordinator.setVisible(false)
    await vi.advanceTimersByTimeAsync(10000)
    expect(read.mock.calls.length - beforeSample).toBe(2)
    coordinator.setVisible(true)
    await vi.advanceTimersByTimeAsync(100)
    expect(read.mock.calls.length - beforeSample).toBe(4)
    unsubscribers.forEach(unsubscribe => unsubscribe())
    stop()
    client.clear()
  })

  it('does not acknowledge a newer hint arriving during a background read', async () => {
    const { client, coordinator, recovery, baseline, stop } = setup()
    const pending = deferred<string>()
    const read = vi.fn()
      .mockResolvedValueOnce('initial')
      .mockImplementationOnce(() => pending.promise)
      .mockResolvedValue('latest')
    const observer = new QueryObserver(client, { queryKey: ['catalog'], queryFn: read })
    const unsubscribe = observer.subscribe(() => undefined)
    await vi.advanceTimersByTimeAsync(1)
    baseline()
    await vi.advanceTimersByTimeAsync(100)
    coordinator.hint(['catalog'])
    pending.resolve('earlier')
    await vi.advanceTimersByTimeAsync(100)
    expect(recovery.completeRecovery).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(500)
    expect(read).toHaveBeenCalledTimes(3)
    expect(client.getQueryData(['catalog'])).toBe('latest')
    unsubscribe()
    stop()
    client.clear()
  })

  it('wakes inactive adapters explicitly and shares capacity fairly with continuously dirty queries', async () => {
    const { client, coordinator, baseline, stop } = setup()
    let enabled = false
    const adapterReads = Array.from({ length: 8 }, () => vi.fn(async () => {
      coordinator.hint(['catalog'])
    }))
    const queryReads = Array.from({ length: 8 }, () => vi.fn(async () => {
      coordinator.hint(['catalog'])
      return 'fresh'
    }))
    adapterReads.forEach((read, index) => coordinator.registerAdapter({
      id: String(index),
      family: 'catalog',
      isActive: () => enabled,
      read,
    }))
    const unsubscribers = queryReads.map((queryFn, index) => {
      client.setQueryData(['catalog', index], 'initial')
      return new QueryObserver(client, {
        queryKey: ['catalog', index],
        queryFn,
      }).subscribe(() => undefined)
    })
    baseline()
    await vi.advanceTimersByTimeAsync(100)
    expect(adapterReads.every(read => read.mock.calls.length === 0)).toBe(true)
    enabled = true
    coordinator.notifyAdapterActivityChanged()
    await vi.advanceTimersByTimeAsync(3000)
    expect(adapterReads.every(read => read.mock.calls.length > 0)).toBe(true)
    expect(queryReads.every(read => read.mock.calls.length > 0)).toBe(true)
    unsubscribers.forEach(unsubscribe => unsubscribe())
    stop()
    client.clear()
  })

  it('refreshes retained pages sequentially after a hint races with loading the next page', async () => {
    const { client, coordinator, baseline, stop } = setup()
    const next = deferred<{ value: number; cursor: number }>()
    let racing = false
    const read = vi.fn(async ({ pageParam }: { pageParam: number }) => {
      if (racing && pageParam === 10) return next.promise
      return { value: pageParam, cursor: pageParam + 1 }
    })
    const observer = new InfiniteQueryObserver(client, {
      queryKey: ['catalog'],
      queryFn: read,
      initialPageParam: 0,
      getNextPageParam: page => page.cursor,
    })
    const unsubscribe = observer.subscribe(() => undefined)
    await vi.advanceTimersByTimeAsync(1)
    for (let page = 1; page < 10; page++) {
      await observer.fetchNextPage()
    }
    racing = true
    const append = observer.fetchNextPage()
    baseline()
    coordinator.hint(['catalog'])
    await vi.advanceTimersByTimeAsync(100)
    next.resolve({ value: 10, cursor: 11 })
    await append
    racing = false
    await vi.advanceTimersByTimeAsync(700)
    expect(observer.getCurrentResult().data?.pages.map(page => page.value)).toEqual(
      Array.from({ length: 11 }, (_, index) => index),
    )
    expect(read).toHaveBeenCalledTimes(22)
    unsubscribe()
    stop()
    client.clear()
  })
})
