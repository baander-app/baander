import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { PartySyncBus } from '../../src/party/PartySyncBus';

class FakeWebSocket {
  static readonly CONNECTING = 0;
  static readonly OPEN = 1;
  static readonly CLOSED = 3;
  static instances: FakeWebSocket[] = [];
  readyState = FakeWebSocket.CONNECTING;
  onopen: (() => void) | null = null;
  onmessage: ((event: { data: string }) => void) | null = null;
  onerror: (() => void) | null = null;
  onclose: (() => void) | null = null;
  send = vi.fn();

  readonly url: string;

  constructor(url: string) {
    this.url = url;
    FakeWebSocket.instances.push(this);
  }

  close = vi.fn(() => { this.readyState = FakeWebSocket.CLOSED; });

  drop(): void {
    this.readyState = FakeWebSocket.CLOSED;
    this.onclose?.();
  }
}

type TicketProvider = (signal: AbortSignal) => Promise<string>;

function pendingTicket() {
  let resolve!: (ticket: string) => void;
  const promise = new Promise<string>(done => { resolve = done; });
  return { promise, resolve };
}

function createBus(getConnectionTicket = vi.fn<TicketProvider>(async () => 'a'.repeat(64))) {
  const events = {
    onStateChange: vi.fn(), onEvent: vi.fn(), onSyncCorrection: vi.fn(), onError: vi.fn(),
  };
  const bus = new PartySyncBus({
    wsEndpoint: 'wss://api.baander.app/api/ws',
    getConnectionTicket,
    syncToleranceMs: 500,
    broadcastIntervalMs: 1000,
    getPosition: () => 0,
  }, events);
  return { bus, events };
}

beforeEach(() => {
  FakeWebSocket.instances = [];
  vi.stubGlobal('WebSocket', FakeWebSocket);
  vi.useFakeTimers();
});

afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

it('uses a new host-issued ticket for the exact upgrade route on every reconnect', async () => {
  const issue = vi.fn<TicketProvider>(async () => 'b'.repeat(64)).mockResolvedValueOnce('a'.repeat(64));
  const { bus } = createBus(issue);
  await bus.join('session-1', 'user-1', 'Member');
  expect(FakeWebSocket.instances[0].url).toBe(`wss://api.baander.app/api/ws?ticket=${'a'.repeat(64)}`);
  FakeWebSocket.instances[0].drop();
  await vi.advanceTimersToNextTimerAsync();
  expect(FakeWebSocket.instances[1].url).toBe(`wss://api.baander.app/api/ws?ticket=${'b'.repeat(64)}`);
  expect(issue).toHaveBeenCalledTimes(2);
  bus.leave();
});

it('retries host ticket issuance failures with backoff and sanitized diagnostics', async () => {
  const issue = vi.fn<TicketProvider>(async () => 'a'.repeat(64)).mockRejectedValueOnce(new Error('secret'));
  const { bus, events } = createBus(issue);
  await bus.join('session-1', 'user-1', 'Member');
  expect(FakeWebSocket.instances).toHaveLength(0);
  expect(events.onError.mock.calls.flat().map(error => error.message).join()).not.toContain('secret');
  await vi.advanceTimersToNextTimerAsync();
  expect(FakeWebSocket.instances).toHaveLength(1);
  bus.leave();
});

it('rejects raw bearer values without opening an anonymous connection', async () => {
  const { bus } = createBus(vi.fn<TicketProvider>(async () => 'raw-access-token'));
  await bus.join('session-1', 'user-1', 'Member');
  expect(FakeWebSocket.instances).toHaveLength(0);
  bus.leave();
  expect(vi.getTimerCount()).toBe(0);
});

it('aborts pending host issuance on leave and ignores a late ticket', async () => {
  const pending = pendingTicket();
  const issue = vi.fn<TicketProvider>(() => pending.promise);
  const { bus } = createBus(issue);
  const joining = bus.join('session-1', 'user-1', 'Member');
  bus.leave();
  expect(issue.mock.calls[0][0].aborted).toBe(true);
  pending.resolve('a'.repeat(64));
  await joining;
  expect(FakeWebSocket.instances).toHaveLength(0);
  expect(vi.getTimerCount()).toBe(0);
});

it('ignores tickets from a join replaced by another join', async () => {
  const pending = pendingTicket();
  const issue = vi.fn<TicketProvider>(() => pending.promise).mockResolvedValueOnce('a'.repeat(64));
  const { bus } = createBus(issue);
  await bus.join('first', 'user-1', 'Member');
  const obsolete = bus.join('second', 'user-1', 'Member');
  expect(FakeWebSocket.instances[0].close).toHaveBeenCalledOnce();
  issue.mockResolvedValueOnce('b'.repeat(64));
  await bus.join('third', 'user-1', 'Member');
  pending.resolve('c'.repeat(64));
  await obsolete;
  expect(FakeWebSocket.instances).toHaveLength(2);
  expect(FakeWebSocket.instances[1].url).toContain('b'.repeat(64));
  bus.leave();
});

it('closes connecting sockets and ignores late events after leave', async () => {
  const { bus, events } = createBus();
  await bus.join('session-1', 'user-1', 'Member');
  const socket = FakeWebSocket.instances[0];
  bus.leave();
  socket.onopen?.();
  socket.onerror?.();
  socket.onclose?.();
  await vi.runOnlyPendingTimersAsync();
  expect(socket.close).toHaveBeenCalledOnce();
  expect(socket.send).not.toHaveBeenCalled();
  expect(events.onError).not.toHaveBeenCalled();
  expect(FakeWebSocket.instances).toHaveLength(1);
  expect(vi.getTimerCount()).toBe(0);
});
