import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { SharedLiveTransport } from '@/shared/live/transport'

vi.mock('@/shared/auth/websocket-ticket', () => ({
  requestWebSocketTicket: vi.fn(),
  webSocketEndpoint: () => 'wss://baander.app/api/ws',
}))

/** Models the pinned server's receive-only 300-second idle deadline. */
class IdleSocket {
  static readonly CONNECTING = 0
  static readonly OPEN = 1
  static readonly CLOSED = 3
  static instances: IdleSocket[] = []
  readyState = IdleSocket.CONNECTING
  readonly sent: { type: string }[] = []
  onopen: (() => void) | null = null
  onclose: (() => void) | null = null
  onmessage: ((event: { data: string }) => void) | null = null
  onerror: (() => void) | null = null
  private idle: ReturnType<typeof setTimeout> | undefined
  constructor() {
    IdleSocket.instances.push(this)
  }
  open() {
    this.readyState = IdleSocket.OPEN
    this.receiveFromClient()
    this.onopen?.()
  }
  send(data: string) {
    if (this.readyState !== IdleSocket.OPEN) throw new Error('Sending on a closed socket')
    this.sent.push(JSON.parse(data) as { type: string })
    this.receiveFromClient()
  }
  pushFromServer(message: object) {
    this.onmessage?.({ data: JSON.stringify(message) })
  }
  close() {
    clearTimeout(this.idle)
    this.readyState = IdleSocket.CLOSED
    this.onclose?.()
  }
  private receiveFromClient() {
    clearTimeout(this.idle)
    this.idle = setTimeout(() => this.close(), 300_000)
  }
}
const releases: (() => void)[] = []

beforeEach(() => {
  vi.useFakeTimers()
  vi.spyOn(Math, 'random').mockReturnValue(0)
  vi.stubGlobal('WebSocket', IdleSocket)
  IdleSocket.instances = []
})
afterEach(() => {
  releases.splice(0).reverse().forEach(release => release())
  vi.clearAllTimers()
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  vi.useRealTimers()
})

async function connect() {
  const ticket = vi.fn(async () => 'a'.repeat(64))
  const transport = new SharedLiveTransport({ wsEndpoint: 'wss://baander.app/api/ws', getConnectionTicket: ticket })
  const release = transport.retain()
  releases.push(release)
  await transport.waitForAttempt()
  return { transport, ticket, release, socket: IdleSocket.instances[0]! }
}

describe('shared transport keepalive ownership', () => {
  it('keeps a live-only connection beyond the receive-idle deadline despite only server health traffic', async () => {
    const { socket, ticket } = await connect()
    socket.open()
    for (let step = 0; step < 66; step++) {
      await vi.advanceTimersByTimeAsync(5000)
      socket.pushFromServer({ type: 'live.health' })
    }
    expect(socket.readyState).toBe(IdleSocket.OPEN)
    expect(ticket).toHaveBeenCalledOnce()
    expect(IdleSocket.instances).toHaveLength(1)
    expect(socket.sent.length).toBeGreaterThan(0)
    expect(socket.sent.every(message => message.type === 'ping')).toBe(true)
  })

  it('starts only after open and keeps one timer for retained subscribers until the last release', async () => {
    const { transport, socket, ticket, release } = await connect()
    await vi.advanceTimersByTimeAsync(60_000)
    expect(socket.sent).toEqual([])
    const releaseSecond = transport.retain()
    releases.push(releaseSecond)
    socket.open()
    await vi.advanceTimersByTimeAsync(60_000)
    expect(socket.sent).toEqual([{ type: 'ping' }, { type: 'ping' }])
    release()
    await vi.advanceTimersByTimeAsync(30_000)
    expect(socket.sent).toHaveLength(3)
    releaseSecond()
    await vi.advanceTimersByTimeAsync(330_000)
    expect(socket.readyState).toBe(IdleSocket.CLOSED)
    expect(socket.sent).toHaveLength(3)
    expect(ticket).toHaveBeenCalledOnce()
    expect(vi.getTimerCount()).toBe(0)
  })

  it('cancels disconnected ownership and gives the replacement its own cadence', async () => {
    const { socket, ticket } = await connect()
    socket.open()
    await vi.advanceTimersByTimeAsync(25_000)
    socket.close()
    await vi.advanceTimersByTimeAsync(1000)
    expect(ticket).toHaveBeenCalledTimes(2)
    const replacement = IdleSocket.instances[1]!
    replacement.open()
    socket.onclose?.()
    await vi.advanceTimersByTimeAsync(4000)
    expect(replacement.sent).toEqual([])
    await vi.advanceTimersByTimeAsync(26_000)
    expect(replacement.sent).toEqual([{ type: 'ping' }])
    expect(socket.sent).toEqual([])
  })

  it('does not let an explicitly replaced socket timer send on its successor', async () => {
    const { transport, socket } = await connect()
    socket.open()
    await vi.advanceTimersByTimeAsync(29_000)
    transport.reconnect()
    await transport.waitForAttempt()
    const replacement = IdleSocket.instances[1]!
    replacement.open()
    await vi.advanceTimersByTimeAsync(1000)
    expect(replacement.sent).toEqual([])
    await vi.advanceTimersByTimeAsync(29_000)
    expect(replacement.sent).toEqual([{ type: 'ping' }])
    expect(socket.readyState).toBe(IdleSocket.CLOSED)
    expect(socket.sent).toEqual([])
  })

  it('stops the erroring connection keepalive before the close/reconnect lifecycle', async () => {
    const { socket, ticket } = await connect()
    socket.open()
    await vi.advanceTimersByTimeAsync(10_000)
    socket.onerror?.()
    await vi.advanceTimersByTimeAsync(60_000)
    expect(socket.sent).toEqual([])
    socket.close()
    await vi.advanceTimersByTimeAsync(1000)
    const replacement = IdleSocket.instances[1]!
    replacement.open()
    await vi.advanceTimersByTimeAsync(30_000)
    expect(replacement.sent).toEqual([{ type: 'ping' }])
    expect(ticket).toHaveBeenCalledTimes(2)
  })

  it('ignores a late open event after the final subscriber releases', async () => {
    const { socket, release, ticket } = await connect()
    release()
    socket.onopen?.()
    await vi.advanceTimersByTimeAsync(330_000)
    expect(socket.sent).toEqual([])
    expect(ticket).toHaveBeenCalledOnce()
    expect(vi.getTimerCount()).toBe(0)
  })
})
