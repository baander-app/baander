import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { SessionSyncBus } from '../services/SessionSyncBus'

class FakeWebSocket {
  static readonly CONNECTING = 0
  static readonly OPEN = 1
  static readonly CLOSED = 3
  static instances: FakeWebSocket[] = []
  readyState = FakeWebSocket.CONNECTING
  onopen: (() => void) | null = null
  onmessage: ((event: { data: string }) => void) | null = null
  onerror: (() => void) | null = null
  onclose: (() => void) | null = null
  send = vi.fn()

  readonly url: string

  constructor(url: string) {
    this.url = url
    FakeWebSocket.instances.push(this)
  }

  close = vi.fn(() => { this.readyState = FakeWebSocket.CLOSED })

  drop(): void {
    this.readyState = FakeWebSocket.CLOSED
    this.onclose?.()
  }
}

type TicketProvider = (signal: AbortSignal) => Promise<string>

const ticket = (character = 'a') => character.repeat(64)
const events = () => ({ onStateUpdate: vi.fn(), onError: vi.fn() })

function createBus(getConnectionTicket = vi.fn<TicketProvider>(async () => ticket()), callbacks = events()) {
  return new SessionSyncBus({
    wsEndpoint: 'wss://api.baander.app/api/ws',
    getConnectionTicket,
    deviceId: 'device-1',
    getPosition: () => 0,
    getQueue: () => [],
    getCurrentIndex: () => 0,
    getIsPlaying: () => false,
  }, callbacks)
}

function pendingTicket() {
  let resolve!: (value: string) => void
  const promise = new Promise<string>(done => { resolve = done })
  return { promise, resolve }
}

async function measureReconnectDelay(): Promise<number> {
  const before = FakeWebSocket.instances.length
  const droppedAt = Date.now()
  FakeWebSocket.instances[before - 1].drop()
  await vi.advanceTimersToNextTimerAsync()
  expect(FakeWebSocket.instances).toHaveLength(before + 1)
  return Date.now() - droppedAt
}

describe('SessionSyncBus', () => {
  beforeEach(() => {
    FakeWebSocket.instances = []
    vi.stubGlobal('WebSocket', FakeWebSocket)
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
  })

  it('obtains a fresh single-use ticket on each reconnect without putting an access token in the URL', async () => {
    const issue = vi.fn<TicketProvider>(async () => ticket())
      .mockResolvedValueOnce(ticket('a')).mockResolvedValueOnce(ticket('b'))
    const bus = createBus(issue)
    await bus.connect('session-1')
    expect(FakeWebSocket.instances[0].url).toBe(`wss://api.baander.app/api/ws?ticket=${ticket('a')}`)
    await measureReconnectDelay()
    expect(issue).toHaveBeenCalledTimes(2)
    expect(FakeWebSocket.instances[1].url).toBe(`wss://api.baander.app/api/ws?ticket=${ticket('b')}`)
    bus.disconnect()
  })

  it('retries ticket issuance failures with backoff before any socket exists', async () => {
    const issue = vi.fn<TicketProvider>(async () => ticket())
      .mockRejectedValueOnce(new Error('secret access token'))
    const callbacks = events()
    const bus = createBus(issue, callbacks)
    await bus.connect('session-1')
    expect(FakeWebSocket.instances).toHaveLength(0)
    expect(callbacks.onError.mock.calls.flat().map(error => error.message).join()).not.toContain('secret')
    await vi.advanceTimersToNextTimerAsync()
    expect(FakeWebSocket.instances).toHaveLength(1)
    expect(issue).toHaveBeenCalledTimes(2)
    bus.disconnect()
  })

  it('rejects malformed tickets without an anonymous or bearer fallback', async () => {
    const bus = createBus(vi.fn<TicketProvider>(async () => 'raw-access-token'))
    await bus.connect('session-1')
    expect(FakeWebSocket.instances).toHaveLength(0)
    bus.disconnect()
    expect(vi.getTimerCount()).toBe(0)
  })

  it('aborts issuance on disconnect and ignores a late successful answer', async () => {
    const pending = pendingTicket()
    const issue = vi.fn<TicketProvider>(() => pending.promise)
    const bus = createBus(issue)
    const connecting = bus.connect('session-1')
    bus.disconnect()
    expect(issue.mock.calls[0][0].aborted).toBe(true)
    pending.resolve(ticket())
    await connecting
    expect(FakeWebSocket.instances).toHaveLength(0)
    expect(vi.getTimerCount()).toBe(0)
  })

  it('ignores an old issuance answer after a newer connection attempt', async () => {
    const pending = pendingTicket()
    const issue = vi.fn<TicketProvider>(() => pending.promise).mockResolvedValueOnce(ticket('a'))
    const bus = createBus(issue)
    await bus.connect('old-session')
    const oldSocket = FakeWebSocket.instances[0]
    const obsolete = bus.connect('middle-session')
    expect(oldSocket.close).toHaveBeenCalledOnce()
    issue.mockResolvedValueOnce(ticket('b'))
    await bus.connect('new-session')
    pending.resolve(ticket('c'))
    await obsolete
    expect(FakeWebSocket.instances).toHaveLength(2)
    expect(FakeWebSocket.instances[1].url).toContain(ticket('b'))
    bus.disconnect()
  })

  it('keeps every reconnect delay between 1 and 30 seconds', async () => {
    const randoms = [0, 1, 0.999, 0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0.5, 0.25]
    let next = 0
    vi.spyOn(Math, 'random').mockImplementation(() => randoms[next++ % randoms.length])
    const bus = createBus()
    await bus.connect('session-1')
    const delays = []
    for (let attempt = 0; attempt < randoms.length; attempt++) delays.push(await measureReconnectDelay())
    expect(delays.every(delay => delay >= 1_000 && delay <= 30_000)).toBe(true)
    expect(Math.max(...delays)).toBe(30_000)
    bus.disconnect()
  })

  it('spreads reconnects for clients that lost their server together', async () => {
    vi.spyOn(Math, 'random').mockReturnValueOnce(0.1).mockReturnValueOnce(0.9)
    const first = createBus()
    const second = createBus()
    await first.connect('session-1')
    await second.connect('session-1')
    const droppedAt = Date.now()
    FakeWebSocket.instances[0].drop()
    FakeWebSocket.instances[1].drop()
    await vi.advanceTimersToNextTimerAsync()
    const firstDelay = Date.now() - droppedAt
    await vi.advanceTimersToNextTimerAsync()
    expect(Date.now() - droppedAt).toBeGreaterThan(firstDelay)
    expect(FakeWebSocket.instances).toHaveLength(4)
    first.disconnect()
    second.disconnect()
  })

  it('closes a connecting socket and ignores its later events after disconnect', async () => {
    const callbacks = events()
    const bus = createBus(undefined, callbacks)
    await bus.connect('session-1')
    const socket = FakeWebSocket.instances[0]
    bus.disconnect()
    socket.onopen?.()
    socket.onmessage?.({ data: JSON.stringify({ type: 'session.state', data: {} }) })
    socket.onerror?.()
    socket.onclose?.()
    await vi.runOnlyPendingTimersAsync()
    expect(socket.close).toHaveBeenCalledOnce()
    expect(socket.send).not.toHaveBeenCalled()
    expect(callbacks.onStateUpdate).not.toHaveBeenCalled()
    expect(callbacks.onError).not.toHaveBeenCalled()
    expect(FakeWebSocket.instances).toHaveLength(1)
    expect(bus.isConnected()).toBe(false)
  })

  it('closes an open socket on disconnect without reconnecting', async () => {
    const bus = createBus()
    await bus.connect('session-1')
    const socket = FakeWebSocket.instances[0]
    socket.readyState = FakeWebSocket.OPEN
    socket.onopen?.()
    expect(bus.isConnected()).toBe(true)
    bus.disconnect()
    socket.onclose?.()
    await vi.runOnlyPendingTimersAsync()
    expect(socket.close).toHaveBeenCalledOnce()
    expect(FakeWebSocket.instances).toHaveLength(1)
    expect(bus.isConnected()).toBe(false)
  })

  it('starts with a short backoff after a connection opens', async () => {
    vi.spyOn(Math, 'random').mockReturnValue(1)
    const bus = createBus()
    await bus.connect('session-1')
    for (let attempt = 0; attempt < 6; attempt++) await measureReconnectDelay()
    expect(await measureReconnectDelay()).toBe(30_000)
    const latest = FakeWebSocket.instances[FakeWebSocket.instances.length - 1]
    latest.readyState = FakeWebSocket.OPEN
    latest.onopen?.()
    expect(await measureReconnectDelay()).toBeLessThanOrEqual(3_000)
    bus.disconnect()
  })
})
