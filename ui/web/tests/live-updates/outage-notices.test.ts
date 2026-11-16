import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { toast } from 'sonner'
import { OwnedQueryClient } from '@/shared/live/query-client'
import { LiveQueryCoordinator } from '@/shared/live/query-coordinator'
import { LiveRecovery, isLiveCurrent, type LiveAuthority, type LiveSnapshot } from '@/shared/live/recovery'
import { setAuthorityPending, synchronizeOwnership } from '@/shared/live/ownership'
import { LIVE_OUTAGE_TOAST_ID, startLiveOutageWatcher } from '@/shared/live/outage-watcher'
import { useLiveOutageStore } from '@/shared/live/outage-store'
import type { LiveTransportListener, SharedLiveTransport } from '@/shared/live/transport'

vi.mock('sonner', () => ({ toast: { warning: vi.fn(), dismiss: vi.fn() } }))

const MINUTE = 60_000

function authority(): LiveAuthority {
  return {
    version: 1,
    families: ['catalog'],
    authorizationFingerprint: 'a'.repeat(64),
    health: { state: 'current', oldestPendingAgeMs: null },
  }
}

function wire(type: 'live.ready' | 'live.changed' | 'live.health', health: LiveAuthority['health'] = authority().health) {
  return { ...authority(), type, health, subscriptionId: 'b'.repeat(32), authorizationGeneration: '1' }
}

const degradedHealth = { state: 'degraded', oldestPendingAgeMs: 6_000 } as const

function controlledTransport() {
  let listener: LiveTransportListener = {}
  const transport = {
    subscribe: (next: LiveTransportListener) => {
      listener = next
      return () => {
        listener = {}
      }
    },
    retain: () => () => undefined,
    isConnected: () => false,
    send: vi.fn(),
  } as unknown as SharedLiveTransport
  return {
    transport,
    open: () => listener.onOpen?.(),
    close: () => listener.onClose?.(),
    receive: (message: unknown) => listener.onMessage?.(message),
  }
}

const disposals: Array<() => void> = []

function setVisibility(state: DocumentVisibilityState) {
  vi.spyOn(document, 'visibilityState', 'get').mockReturnValue(state)
  document.dispatchEvent(new Event('visibilitychange'))
}

function watchedClient(load: () => Promise<LiveAuthority> = async () => authority()) {
  const bus = controlledTransport()
  const recovery = new LiveRecovery(bus.transport, vi.fn(), load, true)
  const client = new OwnedQueryClient()
  const detach = client.attach()
  const coordinator = new LiveQueryCoordinator(client, recovery, [{ family: 'catalog', matches: key => key[0] === 'catalog' }])
  client.setCoordinator(coordinator)
  disposals.push(() => {
    detach()
    client.dispose()
  })
  disposals.push(coordinator.start())
  disposals.push(recovery.start())
  const stopWatcher = startLiveOutageWatcher(recovery, true)
  disposals.push(stopWatcher)
  return { bus, recovery, stopWatcher }
}

async function connect(bus: ReturnType<typeof controlledTransport>) {
  bus.open()
  bus.receive(wire('live.ready'))
  await vi.advanceTimersByTimeAsync(100)
}

/** Advances time while the server keeps sending current health frames, as it does every five seconds. */
async function advanceWithHealth(bus: ReturnType<typeof controlledTransport>, ms: number) {
  for (let elapsed = 0; elapsed < ms; elapsed += 5_000) {
    bus.receive(wire('live.health'))
    await vi.advanceTimersByTimeAsync(Math.min(5_000, ms - elapsed))
  }
}

const outage = () => useLiveOutageStore.getState()

beforeEach(() => {
  vi.useFakeTimers({ now: 100_000 })
  vi.spyOn(Math, 'random').mockReturnValue(0)
  synchronizeOwnership('outage-notices-account')
  setAuthorityPending(false)
})

afterEach(() => {
  disposals.splice(0).reverse().forEach(dispose => dispose())
  setAuthorityPending(false)
  synchronizeOwnership(null)
  vi.restoreAllMocks()
  vi.clearAllMocks()
  vi.useRealTimers()
})

describe('live-update outage notices', () => {
  it('shows nothing for a socket drop that recovers within seconds', async () => {
    const { bus, recovery } = watchedClient()
    await connect(bus)
    expect(recovery.getSnapshot().status).toBe('current')
    bus.close()
    await vi.advanceTimersByTimeAsync(100)
    expect(outage().since).not.toBeNull()
    await vi.advanceTimersByTimeAsync(5_900)
    await connect(bus)
    expect(recovery.getSnapshot().status).toBe('current')
    expect(outage().since).toBeNull()
    expect(toast.warning).not.toHaveBeenCalled()
    expect(toast.dismiss).not.toHaveBeenCalled()
    expect(outage().entry).toBeNull()
  })

  it('raises one toast at fifteen seconds, lists an entry at two minutes and clears both on recovery', async () => {
    const { bus } = watchedClient()
    await connect(bus)
    const droppedAt = Date.now()
    bus.close()
    await vi.advanceTimersByTimeAsync(14_000)
    expect(toast.warning).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(1_000)
    expect(toast.warning).toHaveBeenCalledExactlyOnceWith(
      'Live updates unavailable',
      expect.objectContaining({ id: LIVE_OUTAGE_TOAST_ID, testId: LIVE_OUTAGE_TOAST_ID }),
    )
    await vi.advanceTimersByTimeAsync(2 * MINUTE - 16_000)
    expect(outage().entry).toBeNull()
    await vi.advanceTimersByTimeAsync(1_000)
    expect(outage().entry).toEqual({ since: droppedAt, isRead: false })
    await connect(bus)
    expect(toast.dismiss).toHaveBeenCalledExactlyOnceWith(LIVE_OUTAGE_TOAST_ID)
    expect(outage()).toMatchObject({ since: null, toastRaised: false, entry: null })
    expect(toast.warning).toHaveBeenCalledOnce()
  })

  it('keeps an outage running while the browser refreshes after a change', async () => {
    const { bus, recovery } = watchedClient()
    await connect(bus)
    bus.receive(wire('live.health', degradedHealth))
    await vi.advanceTimersByTimeAsync(10_000)
    expect(recovery.getSnapshot().status).toBe('degraded')
    bus.receive(wire('live.changed', degradedHealth))
    expect(recovery.getSnapshot().status).toBe('recovering')
    await vi.advanceTimersByTimeAsync(100)
    expect(outage().since).not.toBeNull()
    await vi.advanceTimersByTimeAsync(4_900)
    expect(toast.warning).toHaveBeenCalledOnce()
    bus.receive(wire('live.health', degradedHealth))
    await vi.advanceTimersByTimeAsync(10_000)
    expect(toast.warning).toHaveBeenCalledOnce()
  })

  it('continues an outage through a reconnect that is not yet ready', async () => {
    const { bus, recovery } = watchedClient()
    await connect(bus)
    bus.close()
    await vi.advanceTimersByTimeAsync(5_000)
    bus.open()
    expect(recovery.getSnapshot().status).toBe('connecting')
    expect(outage().since).not.toBeNull()
    await vi.advanceTimersByTimeAsync(10_000)
    expect(toast.warning).toHaveBeenCalledOnce()
  })

  it('treats connecting at page load as no outage when it is ready within the deadline', async () => {
    const { bus, recovery } = watchedClient()
    bus.open()
    await vi.advanceTimersByTimeAsync(3_000)
    expect(recovery.getSnapshot().status).toBe('connecting')
    expect(outage().since).toBeNull()
    bus.receive(wire('live.ready'))
    await advanceWithHealth(bus, 30_000)
    expect(recovery.getSnapshot().status).toBe('current')
    expect(outage().since).toBeNull()
    expect(toast.warning).not.toHaveBeenCalled()
  })

  it('counts a later outage from zero', async () => {
    const { bus } = watchedClient()
    await connect(bus)
    bus.close()
    await vi.advanceTimersByTimeAsync(20_000)
    await connect(bus)
    expect(toast.warning).toHaveBeenCalledOnce()
    bus.close()
    await vi.advanceTimersByTimeAsync(14_000)
    expect(toast.warning).toHaveBeenCalledOnce()
    await vi.advanceTimersByTimeAsync(1_000)
    expect(toast.warning).toHaveBeenCalledTimes(2)
  })

  it('does not count time while the tab is hidden', async () => {
    const { bus, recovery } = watchedClient()
    await connect(bus)
    setVisibility('hidden')
    bus.close()
    await vi.advanceTimersByTimeAsync(MINUTE)
    bus.open()
    bus.receive(wire('live.ready'))
    await advanceWithHealth(bus, 4 * MINUTE)
    expect(recovery.getSnapshot().status).not.toBe('current')
    expect(outage().since).not.toBeNull()
    setVisibility('visible')
    await advanceWithHealth(bus, 2_000)
    expect(recovery.getSnapshot().status).toBe('current')
    expect(outage().since).toBeNull()
    expect(toast.warning).not.toHaveBeenCalled()
    expect(outage().entry).toBeNull()
  })

  it('keeps time counted before the tab was hidden and resumes counting when it is shown', async () => {
    const { bus } = watchedClient()
    await connect(bus)
    bus.close()
    await vi.advanceTimersByTimeAsync(10_000)
    setVisibility('hidden')
    await vi.advanceTimersByTimeAsync(5 * MINUTE)
    setVisibility('visible')
    await vi.advanceTimersByTimeAsync(4_000)
    expect(toast.warning).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(1_000)
    expect(toast.warning).toHaveBeenCalledOnce()
  })

  it('begins counting at once when it starts on an already degraded connection', async () => {
    const { bus, recovery, stopWatcher } = watchedClient()
    await connect(bus)
    stopWatcher()
    bus.close()
    await vi.advanceTimersByTimeAsync(100)
    expect(recovery.getSnapshot().status).toBe('degraded')
    disposals.push(startLiveOutageWatcher(recovery, true))
    expect(outage().since).not.toBeNull()
    await vi.advanceTimersByTimeAsync(15_000)
    expect(toast.warning).toHaveBeenCalledOnce()
  })

  it('does not count time while the desktop window is hidden or minimised', async () => {
    let reportVisibility: (visible: boolean) => void = () => undefined
    window.BaanderWindow = {
      minimize: vi.fn(), maximize: vi.fn(), close: vi.fn(), isMaximized: vi.fn(), onMaximizedChange: vi.fn(),
      isVisible: vi.fn(async () => true),
      onVisibilityChange: (cb: (visible: boolean) => void) => {
        reportVisibility = cb
        return () => { reportVisibility = () => undefined }
      },
    }
    disposals.push(() => { delete window.BaanderWindow })
    const { bus } = watchedClient()
    await connect(bus)
    bus.close()
    await vi.advanceTimersByTimeAsync(10_000)
    reportVisibility(false)
    await vi.advanceTimersByTimeAsync(5 * MINUTE)
    expect(toast.warning).not.toHaveBeenCalled()
    reportVisibility(true)
    await vi.advanceTimersByTimeAsync(4_000)
    expect(toast.warning).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(1_000)
    expect(toast.warning).toHaveBeenCalledOnce()
  })

  it('waits for a desktop window that starts hidden to be shown before counting', async () => {
    let reportVisibility: (visible: boolean) => void = () => undefined
    window.BaanderWindow = {
      minimize: vi.fn(), maximize: vi.fn(), close: vi.fn(), isMaximized: vi.fn(), onMaximizedChange: vi.fn(),
      isVisible: vi.fn(async () => false),
      onVisibilityChange: (cb: (visible: boolean) => void) => {
        reportVisibility = cb
        return () => { reportVisibility = () => undefined }
      },
    }
    disposals.push(() => { delete window.BaanderWindow })
    const { bus } = watchedClient()
    await connect(bus)
    bus.close()
    await vi.advanceTimersByTimeAsync(5 * MINUTE)
    expect(toast.warning).not.toHaveBeenCalled()
    reportVisibility(true)
    await vi.advanceTimersByTimeAsync(15_000)
    expect(toast.warning).toHaveBeenCalledOnce()
  })

  it('credits at most one second for a device sleep', async () => {
    const { bus } = watchedClient()
    await connect(bus)
    bus.close()
    await vi.advanceTimersByTimeAsync(10_000)
    vi.setSystemTime(Date.now() + 60 * MINUTE)
    await vi.advanceTimersByTimeAsync(1_000)
    expect(toast.warning).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(2_000)
    await connect(bus)
    expect(outage().since).toBeNull()
    expect(toast.warning).not.toHaveBeenCalled()
  })

  it('keeps the outage and its entry through an access reset', async () => {
    const { bus, recovery } = watchedClient(async () => ({ ...authority(), health: degradedHealth }))
    await connect(bus)
    bus.receive(wire('live.health', degradedHealth))
    await advanceWithDegradedHealth(bus, 2 * MINUTE + 1_000)
    const entry = outage().entry
    expect(entry).not.toBeNull()
    bus.receive({ ...wire('live.ready'), type: 'live.reset', authorizationGeneration: '2' })
    expect(recovery.getSnapshot().authorityReady).toBe(false)
    expect(outage().entry).toBe(entry)
    expect(toast.dismiss).not.toHaveBeenCalled()
  })

  it('dismisses the toast and clears the outage when the watcher stops', async () => {
    const { bus, stopWatcher } = watchedClient()
    await connect(bus)
    bus.close()
    await vi.advanceTimersByTimeAsync(2 * MINUTE + 1_000)
    expect(outage().entry).not.toBeNull()
    stopWatcher()
    expect(toast.dismiss).toHaveBeenCalledExactlyOnceWith(LIVE_OUTAGE_TOAST_ID)
    expect(outage()).toMatchObject({ since: null, toastRaised: false, entry: null })
    await vi.advanceTimersByTimeAsync(5 * MINUTE)
    expect(toast.warning).toHaveBeenCalledOnce()
    expect(outage().since).toBeNull()
  })
})

/** Advances time while the server keeps reporting degraded publication health. */
async function advanceWithDegradedHealth(bus: ReturnType<typeof controlledTransport>, ms: number) {
  for (let elapsed = 0; elapsed < ms; elapsed += 5_000) {
    bus.receive(wire('live.health', degradedHealth))
    await vi.advanceTimersByTimeAsync(Math.min(5_000, ms - elapsed))
  }
}

describe('live data currency', () => {
  const snapshot = (patch: Partial<LiveSnapshot>): LiveSnapshot => ({
    status: 'recovering',
    families: [],
    authorizationFingerprint: null,
    health: { state: 'current', oldestPendingAgeMs: null },
    authorityReady: true,
    ...patch,
  })

  it('is current when the recovery status is current', () => {
    expect(isLiveCurrent(snapshot({ status: 'current' }), true)).toBe(true)
  })

  it('needs completed refresh reads when freshness tracking is on', () => {
    expect(isLiveCurrent(snapshot({}), true)).toBe(false)
  })

  it('treats ready authority with current health as current without freshness tracking', () => {
    expect(isLiveCurrent(snapshot({}), false)).toBe(true)
    expect(isLiveCurrent(snapshot({ authorityReady: false }), false)).toBe(false)
    expect(isLiveCurrent(snapshot({ health: degradedHealth }), false)).toBe(false)
  })
})
