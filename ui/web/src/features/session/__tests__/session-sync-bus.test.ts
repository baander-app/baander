import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { SessionSyncBus } from '../services/SessionSyncBus'

class FakeWebSocket {
  static readonly CONNECTING = 0
  static readonly OPEN = 1
  static readonly CLOSING = 2
  static readonly CLOSED = 3
  static instances: FakeWebSocket[] = []

  readonly url: string
  readyState = FakeWebSocket.CONNECTING
  onopen: (() => void) | null = null
  onmessage: ((event: { data: string }) => void) | null = null
  onerror: (() => void) | null = null
  onclose: (() => void) | null = null
  send = vi.fn()

  constructor(url: string) {
    this.url = url
    FakeWebSocket.instances.push(this)
  }

  close(): void {
    this.readyState = FakeWebSocket.CLOSED
  }

  /** The server or network ends the connection. */
  drop(): void {
    this.readyState = FakeWebSocket.CLOSED
    this.onclose?.()
  }
}

function createBus(getAuthToken: () => string | null): SessionSyncBus {
  return new SessionSyncBus({
    wsEndpoint: '/api/ws',
    getAuthToken,
    deviceId: 'device-1',
    getPosition: () => 0,
    getQueue: () => [],
    getCurrentIndex: () => 0,
    getIsPlaying: () => false,
  }, {
    onStateUpdate: vi.fn(),
    onError: vi.fn(),
  })
}

/** Drops the newest socket and returns how long the bus waited before opening the next one. */
function measureReconnectDelay(): number {
  const before = FakeWebSocket.instances.length
  const droppedAt = Date.now()
  FakeWebSocket.instances[before - 1].drop()
  vi.advanceTimersToNextTimer()
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

  it('reconnects with the access token that is current at reconnect time', async () => {
    let token = 'token-before-refresh'
    const bus = createBus(() => token)

    await bus.connect('session-1')
    expect(FakeWebSocket.instances[0].url).toBe('/api/ws?token=token-before-refresh')

    token = 'token-after-refresh'
    FakeWebSocket.instances[0].drop()
    vi.advanceTimersToNextTimer()

    expect(FakeWebSocket.instances).toHaveLength(2)
    expect(FakeWebSocket.instances[1].url).toBe('/api/ws?token=token-after-refresh')
    bus.disconnect()
  })

  it('connects without a token parameter when no token is available', async () => {
    const bus = createBus(() => null)

    await bus.connect('session-1')

    expect(FakeWebSocket.instances[0].url).toBe('/api/ws')
    bus.disconnect()
  })

  it('keeps every reconnect delay between 1 and 30 seconds', async () => {
    const randoms = [0, 1, 0.999, 0, 1, 1, 1, 1, 1, 1, 1, 1, 0.5, 0.25, 0.75]
    let next = 0
    vi.spyOn(Math, 'random').mockImplementation(() => randoms[next++ % randoms.length])
    const bus = createBus(() => 'token')

    await bus.connect('session-1')
    const delays = randoms.map(() => measureReconnectDelay())

    for (const delay of delays) {
      expect(delay).toBeGreaterThanOrEqual(1_000)
      expect(delay).toBeLessThanOrEqual(30_000)
    }
    expect(Math.max(...delays)).toBe(30_000)
    bus.disconnect()
  })

  it('spreads the reconnects of clients that lost the server at the same moment', async () => {
    vi.spyOn(Math, 'random')
      .mockReturnValueOnce(0.1)
      .mockReturnValueOnce(0.9)
    const first = createBus(() => 'token-1')
    const second = createBus(() => 'token-2')
    await first.connect('session-1')
    await second.connect('session-1')
    const droppedAt = Date.now()

    FakeWebSocket.instances[0].drop()
    FakeWebSocket.instances[1].drop()
    vi.advanceTimersToNextTimer()
    const firstReconnect = Date.now() - droppedAt
    vi.advanceTimersToNextTimer()
    const secondReconnect = Date.now() - droppedAt

    expect(FakeWebSocket.instances.map(socket => socket.url)).toEqual([
      '/api/ws?token=token-1',
      '/api/ws?token=token-2',
      '/api/ws?token=token-1',
      '/api/ws?token=token-2',
    ])
    expect(secondReconnect).toBeGreaterThan(firstReconnect)
    first.disconnect()
    second.disconnect()
  })

  it('starts again from a short delay once a connection opened', async () => {
    vi.spyOn(Math, 'random').mockReturnValue(1)
    const bus = createBus(() => 'token')
    await bus.connect('session-1')
    for (let attempt = 0; attempt < 6; attempt++) measureReconnectDelay()
    expect(measureReconnectDelay()).toBe(30_000)

    const latest = FakeWebSocket.instances[FakeWebSocket.instances.length - 1]
    latest.readyState = FakeWebSocket.OPEN
    latest.onopen?.()

    expect(measureReconnectDelay()).toBeLessThanOrEqual(3_000)
    bus.disconnect()
  })
})
