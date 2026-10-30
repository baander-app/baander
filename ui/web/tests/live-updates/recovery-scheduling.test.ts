import { AxiosError } from 'axios'
import { QueryObserver } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { OwnedQueryClient } from '@/shared/live/query-client'
import { LiveQueryCoordinator } from '@/shared/live/query-coordinator'
import { LiveRecovery, type LiveAuthority } from '@/shared/live/recovery'
import { setAuthorityPending, synchronizeOwnership } from '@/shared/live/ownership'
import type { LiveTransportListener, SharedLiveTransport } from '@/shared/live/transport'

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>(done => {
    resolve = done
  })
  return { promise, resolve }
}
function authority(): LiveAuthority {
  return {
    version: 1,
    families: ['catalog'],
    authorizationFingerprint: 'a'.repeat(64),
    health: { state: 'current', oldestPendingAgeMs: null },
  }
}
function wire(type: 'live.ready' | 'live.changed' | 'live.health') {
  return { ...authority(), type, subscriptionId: 'b'.repeat(32), authorizationGeneration: '1' }
}
function controlledTransport() {
  let listener: LiveTransportListener = {}
  const send = vi.fn()
  const transport = {
    subscribe: (next: LiveTransportListener) => {
      listener = next
      return () => {
        listener = {}
      }
    },
    retain: () => () => undefined,
    isConnected: () => false,
    send,
  } as unknown as SharedLiveTransport
  return {
    transport,
    send,
    open: () => listener.onOpen?.(),
    close: () => listener.onClose?.(),
    error: () => listener.onError?.(new Error('WebSocket disconnected')),
    receive: (message: unknown) => listener.onMessage?.(message),
  }
}
const flush = async () => {
  for (let step = 0; step < 20; step++) {
    await Promise.resolve()
  }
}
const disposals: Array<() => void> = []

beforeEach(() => {
  vi.useFakeTimers({ now: 100_000 })
  vi.spyOn(Math, 'random').mockReturnValue(0)
  synchronizeOwnership('recovery-scheduling-account')
  setAuthorityPending(false)
})
afterEach(() => {
  disposals.splice(0).reverse().forEach(dispose => dispose())
  setAuthorityPending(false)
  synchronizeOwnership(null)
  vi.restoreAllMocks()
  vi.useRealTimers()
})

function recoveredClient(load: (signal: AbortSignal) => Promise<LiveAuthority> = async () => authority()) {
  const bus = controlledTransport()
  const purge = vi.fn()
  const recovery = new LiveRecovery(bus.transport, purge, load, true)
  const client = new OwnedQueryClient()
  const detach = client.attach()
  const coordinator = new LiveQueryCoordinator(client, recovery, [{ family: 'catalog', matches: key => key[0] === 'catalog' }])
  client.coordinator = coordinator
  disposals.push(() => {
    detach()
    client.dispose()
  })
  disposals.push(coordinator.start())
  disposals.push(recovery.start())
  return { bus, purge, recovery, client, coordinator }
}

describe('recovery scheduling lifecycle', () => {
  it.each(['close before error', 'error before close'] as const)(
    'coalesces disconnect notifications with %s while preserving the next connection',
    async order => {
      const pending = deferred<LiveAuthority>()
      const signals: AbortSignal[] = []
      const load = vi.fn((signal: AbortSignal) => {
        signals.push(signal)
        return pending.promise
      })
      const { bus } = recoveredClient(load)
      bus.open()
      bus.receive(wire('live.ready'))
      if (order === 'close before error') {
        bus.close()
        bus.error()
      } else {
        bus.error()
        bus.close()
      }
      expect(load).toHaveBeenCalledOnce()
      expect(signals[0].aborted).toBe(false)
      bus.open()
      expect(signals[0].aborted).toBe(true)
      bus.close()
      bus.error()
      expect(load).toHaveBeenCalledTimes(2)
      expect(signals[1].aborted).toBe(false)
      pending.resolve(authority())
      await flush()
    },
  )

  it('waits ten seconds for ready before falling back to authority HTTP', async () => {
    const load = vi.fn(async () => authority())
    const { bus, recovery } = recoveredClient(load)
    bus.open()
    expect(bus.send).toHaveBeenCalledExactlyOnceWith({ type: 'live.subscribe', version: 1 })
    await vi.advanceTimersByTimeAsync(9999)
    expect(load).not.toHaveBeenCalled()
    expect(recovery.getSnapshot().authorityReady).toBe(false)
    await vi.advanceTimersByTimeAsync(1)
    expect(load).toHaveBeenCalledOnce()
    expect(recovery.getSnapshot().authorityReady).toBe(true)
    expect(recovery.getSnapshot().status).toBe('degraded')
  })

  it('extends the fifteen-second health deadline and continues thirty-second fallback without delivery', async () => {
    const load = vi.fn(async () => authority())
    const { bus, recovery } = recoveredClient(load)
    bus.open()
    bus.receive(wire('live.ready'))
    await vi.advanceTimersByTimeAsync(5000)
    expect(recovery.getSnapshot().status).toBe('current')
    bus.receive(wire('live.health'))
    await vi.advanceTimersByTimeAsync(14_999)
    expect(load).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(1)
    expect(load).toHaveBeenCalledOnce()
    expect(recovery.getSnapshot().status).toBe('degraded')
    await vi.advanceTimersByTimeAsync(10_000)
    expect(load).toHaveBeenCalledTimes(2)
    expect(recovery.getSnapshot().status).toBe('degraded')
    await vi.advanceTimersByTimeAsync(30_000)
    expect(load).toHaveBeenCalledTimes(3)
  })

  it('accepts healthy frames on an open socket after health expiry', async () => {
    const load = vi.fn(async () => authority())
    const { bus, recovery } = recoveredClient(load)
    bus.open()
    bus.receive(wire('live.ready'))
    await vi.advanceTimersByTimeAsync(15_000)
    expect(recovery.getSnapshot().status).toBe('degraded')
    bus.receive(wire('live.health'))
    await vi.advanceTimersByTimeAsync(100)
    expect(recovery.getSnapshot().status).toBe('current')
    bus.receive(wire('live.changed'))
    await vi.advanceTimersByTimeAsync(100)
    expect(recovery.getSnapshot().status).toBe('current')
    expect(load).toHaveBeenCalledOnce()
  })

  it('preserves the wire health deadline through reset authority HTTP success', async () => {
    const changed = { ...authority(), authorizationFingerprint: 'c'.repeat(64) }
    const load = vi.fn(async () => changed)
    const { bus, recovery } = recoveredClient(load)
    bus.open()
    bus.receive(wire('live.ready'))
    await vi.advanceTimersByTimeAsync(5000)
    bus.receive({
      ...wire('live.ready'),
      type: 'live.reset',
      authorizationGeneration: '2',
      authorizationFingerprint: changed.authorizationFingerprint,
    })
    await vi.advanceTimersByTimeAsync(100)
    expect(load).toHaveBeenCalledOnce()
    expect(recovery.getSnapshot().status).toBe('current')
    await vi.advanceTimersByTimeAsync(14_900)
    expect(recovery.getSnapshot().status).toBe('degraded')
    expect(load).toHaveBeenCalledTimes(2)
  })

  it('suspends delivery fallback while hidden and resumes it when visible', async () => {
    const load = vi.fn(async () => authority())
    const { bus } = recoveredClient(load)
    bus.open()
    await vi.advanceTimersByTimeAsync(10_000)
    expect(load).toHaveBeenCalledOnce()
    const visibility = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden')
    await vi.advanceTimersByTimeAsync(20_000)
    expect(load).toHaveBeenCalledOnce()
    visibility.mockReturnValue('visible')
    await vi.advanceTimersByTimeAsync(30_000)
    expect(load).toHaveBeenCalledTimes(2)
  })

  it('retries authority 503 without purging ten previously authorized pages or mirrors', async () => {
    const load = vi.fn().mockRejectedValueOnce({ response: { status: 503 } }).mockResolvedValue(authority())
    const { bus, recovery, client, purge } = recoveredClient(load)
    const pages = {
      pages: Array.from({ length: 10 }, (_, index) => [`song-${index}`]),
      pageParams: Array.from({ length: 10 }, (_, index) => index),
    }
    client.setQueryData(['catalog', 'retained-pages'], pages)
    bus.open()
    bus.receive(wire('live.ready'))
    bus.close()
    await flush()
    expect(recovery.getSnapshot().authorityReady).toBe(false)
    expect(recovery.getSnapshot().status).toBe('degraded')
    expect(client.getQueryData(['catalog', 'retained-pages'])).toBe(pages)
    expect(purge).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(999)
    expect(load).toHaveBeenCalledOnce()
    await vi.advanceTimersByTimeAsync(1)
    expect(load).toHaveBeenCalledTimes(2)
    expect(recovery.getSnapshot().authorityReady).toBe(true)
    expect(client.getQueryData(['catalog', 'retained-pages'])).toBe(pages)
    expect(purge).not.toHaveBeenCalled()
  })

  it.each([403, 404])('settles an unavailable %s snapshot, preserves its error, and reads again after a hint', async status => {
    const { bus, client, recovery } = recoveredClient()
    const failed = vi.spyOn(recovery, 'resourceReadFailed')
    const failure = new AxiosError('Unavailable', undefined, undefined, undefined, {
      status, statusText: 'Unavailable', headers: {}, config: { headers: {} } as never, data: {},
    })
    const read = vi.fn().mockResolvedValue('initial')
    const key = ['catalog', 'terminal']
    const observer = new QueryObserver(client, { queryKey: key, queryFn: read, retry: false })
    disposals.push(observer.subscribe(() => undefined))
    bus.open()
    bus.receive(wire('live.ready'))
    await vi.advanceTimersByTimeAsync(200)
    expect(observer.getCurrentResult().data).toBe('initial')
    read.mockRejectedValue(failure)
    bus.receive(wire('live.changed'))
    await vi.advanceTimersByTimeAsync(500)
    expect(client.getQueryState(key)).toMatchObject({ data: undefined, dataUpdatedAt: 0, status: 'error', fetchStatus: 'idle', error: failure })
    expect(observer.getCurrentResult().data).toBeUndefined()
    expect(observer.getCurrentResult().error).toBe(failure)
    expect(recovery.getSnapshot().status).toBe('current')
    expect(failed).not.toHaveBeenCalled()
    const reads = read.mock.calls.length
    for (let interval = 0; interval < 4; interval++) {
      bus.receive(wire('live.health'))
      await vi.advanceTimersByTimeAsync(10_000)
    }
    expect(read).toHaveBeenCalledTimes(reads)
    read.mockResolvedValue('restored')
    bus.receive(wire('live.changed'))
    await vi.advanceTimersByTimeAsync(500)
    expect(read).toHaveBeenCalledTimes(reads + 1)
    expect(observer.getCurrentResult().data).toBe('restored')
    expect(recovery.getSnapshot().status).toBe('current')
  })

  it('keeps a newer hint pending when an earlier resource read reports absence', async () => {
    const { bus, client, recovery } = recoveredClient()
    const earlier = deferred<string>()
    const later = deferred<string>()
    const failure = new AxiosError('Deleted', undefined, undefined, undefined, {
      status: 404, statusText: 'Deleted', headers: {}, config: { headers: {} } as never, data: {},
    })
    const read = vi.fn().mockResolvedValueOnce('initial')
      .mockImplementationOnce(() => earlier.promise.then(() => { throw failure }))
      .mockImplementationOnce(() => later.promise)
    const observer = new QueryObserver(client, { queryKey: ['catalog', 'terminal-hints'], queryFn: read, retry: false })
    disposals.push(observer.subscribe(() => undefined))
    bus.open()
    bus.receive(wire('live.ready'))
    await vi.advanceTimersByTimeAsync(200)
    bus.receive(wire('live.changed'))
    await vi.advanceTimersByTimeAsync(500)
    bus.receive(wire('live.changed'))
    earlier.resolve('ignored')
    await flush()
    expect(recovery.getSnapshot().status).toBe('recovering')
    await vi.advanceTimersByTimeAsync(500)
    expect(read).toHaveBeenCalledTimes(3)
    expect(observer.getCurrentResult().data).toBeUndefined()
    expect(recovery.getSnapshot().status).toBe('recovering')
    later.resolve('latest')
    await vi.advanceTimersByTimeAsync(200)
    expect(observer.getCurrentResult().data).toBe('latest')
    expect(recovery.getSnapshot().status).toBe('current')
  })

  it('fences a terminal response from before an authorization reset until new authority and a new read settle', async () => {
    const nextAuthority = deferred<LiveAuthority>()
    const { bus, client, recovery } = recoveredClient(() => nextAuthority.promise)
    const oldRead = deferred<string>()
    const newRead = deferred<string>()
    const read = vi.fn().mockResolvedValueOnce('initial')
      .mockImplementationOnce(() => oldRead.promise.then(() => {
        throw new AxiosError('Old denial', undefined, undefined, undefined, {
          status: 403, statusText: 'Forbidden', headers: {}, config: { headers: {} } as never, data: {},
        })
      }))
      .mockImplementationOnce(() => newRead.promise)
    const key = ['catalog', 'reset-terminal']
    const observer = new QueryObserver(client, { queryKey: key, queryFn: read, retry: false })
    const unsubscribe = observer.subscribe(() => undefined)
    disposals.push(unsubscribe)
    bus.open()
    bus.receive(wire('live.ready'))
    await vi.advanceTimersByTimeAsync(200)
    bus.receive(wire('live.changed'))
    await vi.advanceTimersByTimeAsync(500)
    const fingerprint = 'c'.repeat(64)
    bus.receive({ ...wire('live.ready'), type: 'live.reset', authorizationGeneration: '2', authorizationFingerprint: fingerprint })
    unsubscribe()
    await vi.advanceTimersByTimeAsync(500)
    expect(recovery.getSnapshot().authorityReady).toBe(false)
    expect(recovery.getSnapshot().status).toBe('recovering')
    expect(read).toHaveBeenCalledTimes(2)
    expect(client.getQueryData(key)).toBeUndefined()
    nextAuthority.resolve({ ...authority(), authorizationFingerprint: fingerprint })
    await flush()
    const replacement = new QueryObserver(client, { queryKey: key, queryFn: read, retry: false })
    disposals.push(replacement.subscribe(() => undefined))
    await vi.advanceTimersByTimeAsync(100)
    expect(read).toHaveBeenCalledTimes(3)
    oldRead.resolve('obsolete')
    await vi.advanceTimersByTimeAsync(500)
    expect(replacement.getCurrentResult().error).toBeNull()
    expect(replacement.getCurrentResult().data).toBeUndefined()
    expect(recovery.getSnapshot().status).toBe('recovering')
    newRead.resolve('new authority snapshot')
    await vi.advanceTimersByTimeAsync(200)
    expect(replacement.getCurrentResult().data).toBe('new authority snapshot')
    expect(recovery.getSnapshot().status).toBe('current')
  })

  it.each([401, 429, 503])('keeps HTTP %s failures retryable with the previous snapshot', async status => {
    const { bus, client, recovery } = recoveredClient()
    const failure = new AxiosError('Retryable', undefined, undefined, undefined, {
      status, statusText: 'Retryable', headers: {}, config: { headers: {} } as never, data: {},
    })
    const read = vi.fn().mockResolvedValueOnce('initial').mockRejectedValueOnce(failure).mockResolvedValue('recovered')
    const observer = new QueryObserver(client, { queryKey: ['catalog', 'retryable'], queryFn: read, retry: false })
    disposals.push(observer.subscribe(() => undefined))
    bus.open()
    bus.receive(wire('live.ready'))
    await vi.advanceTimersByTimeAsync(200)
    bus.receive(wire('live.changed'))
    await vi.advanceTimersByTimeAsync(500)
    expect(observer.getCurrentResult().data).toBe('initial')
    expect(observer.getCurrentResult().error).toBe(failure)
    expect(recovery.getSnapshot().status).toBe('degraded')
    await vi.advanceTimersByTimeAsync(1500)
    expect(read).toHaveBeenCalledTimes(3)
    expect(observer.getCurrentResult().data).toBe('recovered')
    expect(recovery.getSnapshot().status).toBe('current')
  })

  it('retries a source 503 without another hint while keeping readable pages', async () => {
    const { bus, client, recovery } = recoveredClient()
    const previous = {
      pages: Array.from({ length: 10 }, (_, index) => [`old-${index}`]),
      pageParams: Array.from({ length: 10 }, (_, index) => index),
    }
    const next = { ...previous, pages: previous.pages.map(page => page.map(song => song.replace('old', 'new'))) }
    client.setQueryData(['catalog', 'pages'], previous)
    const read = vi.fn().mockRejectedValueOnce({ response: { status: 503 } }).mockResolvedValue(next)
    const observer = new QueryObserver(client, { queryKey: ['catalog', 'pages'], queryFn: read, retry: false })
    disposals.push(observer.subscribe(() => undefined))
    bus.open()
    bus.receive(wire('live.ready'))
    await vi.advanceTimersByTimeAsync(100)
    expect(read).toHaveBeenCalledOnce()
    expect(client.getQueryData(['catalog', 'pages'])).toBe(previous)
    expect(recovery.getSnapshot().status).toBe('degraded')
    await vi.advanceTimersByTimeAsync(999)
    expect(read).toHaveBeenCalledOnce()
    await vi.advanceTimersByTimeAsync(1)
    expect(read).toHaveBeenCalledTimes(2)
    expect(client.getQueryData(['catalog', 'pages'])).toEqual(next)
    await vi.advanceTimersByTimeAsync(100)
    expect(recovery.getSnapshot().status).toBe('current')
  })

  it('retains a hint arriving during the baseline resource HTTP read', async () => {
    const { bus, client, recovery } = recoveredClient()
    const pending = deferred<string>()
    const read = vi.fn().mockImplementationOnce(() => pending.promise).mockResolvedValue('fresh-after-hint')
    const observer = new QueryObserver(client, { queryKey: ['catalog', 'initial'], queryFn: read, retry: false })
    disposals.push(observer.subscribe(() => undefined))
    await flush()
    expect(read).not.toHaveBeenCalled()
    bus.open()
    bus.receive(wire('live.ready'))
    await vi.advanceTimersByTimeAsync(100)
    expect(read).toHaveBeenCalledOnce()
    bus.receive(wire('live.changed'))
    pending.resolve('snapshot-before-hint')
    await flush()
    expect(recovery.getSnapshot().status).toBe('recovering')
    await vi.advanceTimersByTimeAsync(500)
    expect(read).toHaveBeenCalledTimes(2)
    expect(client.getQueryData(['catalog', 'initial'])).toBe('fresh-after-hint')
    await vi.advanceTimersByTimeAsync(100)
    expect(recovery.getSnapshot().status).toBe('current')
  })

  it('ignores pending authority HTTP from before a reconnect observation handshake', async () => {
    const pending = deferred<LiveAuthority>()
    const load = vi.fn(() => pending.promise)
    const { bus, recovery } = recoveredClient(load)
    bus.open()
    await vi.advanceTimersByTimeAsync(10_000)
    expect(load).toHaveBeenCalledOnce()
    bus.open()
    pending.resolve(authority())
    await flush()
    expect(recovery.getSnapshot().authorityReady).toBe(false)
    bus.receive(wire('live.ready'))
    expect(recovery.getSnapshot().authorityReady).toBe(true)
  })
})
