import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { LiveRecovery } from '@/shared/live/recovery'
import { setAuthorityPending, synchronizeOwnership } from '@/shared/live/ownership'
import { SharedLiveTransport } from '@/shared/live/transport'

vi.mock('@/shared/auth/websocket-ticket', () => ({
  requestWebSocketTicket: vi.fn(),
  webSocketEndpoint: () => 'wss://baander.app/api/ws',
}))

class TestSocket {
  static readonly CONNECTING = 0
  static readonly OPEN = 1
  static readonly CLOSED = 3
  static instances: TestSocket[] = []
  readyState = TestSocket.CONNECTING
  readonly sent: { type: string }[] = []
  onopen: (() => void) | null = null
  onclose: (() => void) | null = null
  onmessage: ((event: { data: string }) => void) | null = null
  onerror: (() => void) | null = null
  constructor() {
    TestSocket.instances.push(this)
  }
  open() {
    this.readyState = TestSocket.OPEN
    this.onopen?.()
  }
  send(data: string) {
    this.sent.push(JSON.parse(data) as { type: string })
  }
  receive(message: object) {
    this.onmessage?.({ data: JSON.stringify(message) })
  }
  close() {
    this.readyState = TestSocket.CLOSED
    this.onclose?.()
  }
}

const authority = {
  version: 1 as const,
  families: ['catalog' as const],
  authorizationFingerprint: 'a'.repeat(64),
  health: { state: 'current' as const, oldestPendingAgeMs: null },
}
const frame = (type: 'live.ready' | 'live.health' | 'live.changed', subscriptionId = 'b'.repeat(32)) => ({
  ...authority, type, subscriptionId, authorizationGeneration: '1',
})
const cleanups: (() => void)[] = []

beforeEach(() => {
  vi.useFakeTimers({ now: 100_000 })
  vi.spyOn(Math, 'random').mockReturnValue(0)
  vi.stubGlobal('WebSocket', TestSocket)
  TestSocket.instances = []
  synchronizeOwnership('recovery-transport-account')
  setAuthorityPending(false)
})
afterEach(() => {
  cleanups.splice(0).reverse().forEach(cleanup => cleanup())
  setAuthorityPending(false)
  synchronizeOwnership(null)
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  vi.useRealTimers()
})

async function connect() {
  const ticket = vi.fn(async () => 'a'.repeat(64))
  const transport = new SharedLiveTransport({ wsEndpoint: 'wss://baander.app/api/ws', getConnectionTicket: ticket })
  const load = vi.fn(async () => authority)
  const recovery = new LiveRecovery(transport, vi.fn(), load, true)
  const events = vi.fn()
  cleanups.push(recovery.subscribeEvents(events), recovery.start())
  await transport.waitForAttempt()
  const socket = TestSocket.instances[0]!
  socket.open()
  return { transport, recovery, ticket, load, events, socket }
}

describe('recovery with the shared socket transport', () => {
  it('replaces an orphaned open socket after prior readiness and accepts the new subscription', async () => {
    const { recovery, ticket, load, events, socket } = await connect()
    socket.receive(frame('live.ready'))
    recovery.completeRecovery()
    expect(recovery.getSnapshot().status).toBe('current')
    await vi.advanceTimersByTimeAsync(14_999)
    expect(ticket).toHaveBeenCalledOnce()
    expect(socket.readyState).toBe(TestSocket.OPEN)
    await vi.advanceTimersByTimeAsync(1)
    expect(load).toHaveBeenCalledOnce()
    expect(ticket).toHaveBeenCalledTimes(2)
    expect(socket.readyState).toBe(TestSocket.CLOSED)
    expect(TestSocket.instances).toHaveLength(2)
    const replacement = TestSocket.instances[1]!
    replacement.open()
    const newSubscription = 'c'.repeat(32)
    replacement.receive(frame('live.ready', newSubscription))
    recovery.completeRecovery()
    expect(recovery.getSnapshot().status).toBe('current')
    events.mockClear()
    socket.receive(frame('live.changed'))
    expect(events).not.toHaveBeenCalled()
    replacement.receive(frame('live.changed', newSubscription))
    expect(events).toHaveBeenCalledExactlyOnceWith({ type: 'dirty', families: ['catalog'] })
    for (let index = 0; index < 4; index++) {
      await vi.advanceTimersByTimeAsync(5000)
      replacement.receive(frame('live.health', newSubscription))
    }
    expect(ticket).toHaveBeenCalledTimes(2)
    expect(TestSocket.instances.flatMap(instance => instance.sent).map(message => message.type))
      .toEqual(['live.subscribe', 'live.subscribe'])
  })

  it('replaces an open socket that never supplies initial readiness at the existing deadline', async () => {
    const { ticket, socket } = await connect()
    await vi.advanceTimersByTimeAsync(9999)
    expect(ticket).toHaveBeenCalledOnce()
    await vi.advanceTimersByTimeAsync(1)
    expect(ticket).toHaveBeenCalledTimes(2)
    expect(socket.readyState).toBe(TestSocket.CLOSED)
    expect(TestSocket.instances).toHaveLength(2)
  })

  it('retains a socket delivering explicit degraded health instead of churning connections', async () => {
    const { recovery, ticket, socket } = await connect()
    socket.receive(frame('live.ready'))
    for (let index = 0; index < 8; index++) {
      await vi.advanceTimersByTimeAsync(5000)
      socket.receive({ ...frame('live.health'), health: { state: 'degraded', oldestPendingAgeMs: 6000 } })
    }
    expect(recovery.getSnapshot().status).toBe('degraded')
    expect(ticket).toHaveBeenCalledOnce()
    expect(TestSocket.instances).toHaveLength(1)
    expect(socket.readyState).toBe(TestSocket.OPEN)
  })
})
