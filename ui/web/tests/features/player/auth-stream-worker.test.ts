// @vitest-environment node
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { transformSync } from 'esbuild';
import { beforeEach, expect, it, vi } from 'vitest';

const code = transformSync(readFileSync('src/features/player/services/auth-stream-worker.ts', 'utf8'), { loader: 'ts' }).code;
const origin = 'https://web.baander.test';
const api = 'https://api.baander.test';
let handlers: Record<string, (event: unknown) => void>;
let clients: Map<string, { id: string; url: string; postMessage: ReturnType<typeof vi.fn> }>;
let fetchMock: ReturnType<typeof vi.fn>;
let ports: Array<{ close: ReturnType<typeof vi.fn> }>;

beforeEach(() => {
  handlers = {}; clients = new Map(); ports = [];
  fetchMock = vi.fn().mockResolvedValue(new Response('ok'));
  class Channel {
    port1 = { onmessage: null as ((event: unknown) => void) | null, close: vi.fn() };
    port2 = { postMessage: (data: unknown) => this.port1.onmessage?.({ data }), close: vi.fn() };
    constructor() { ports.push(this.port1, this.port2); }
  }
  runInNewContext(code, {
    self: {
      location: { origin },
      addEventListener: (name: string, callback: (event: unknown) => void) => { handlers[name] = callback; },
      clients: { get: async (id: string) => clients.get(id), matchAll: async () => [...clients.values()], claim: async () => {} },
      skipWaiting: vi.fn(),
    },
    URL, Headers, Request, Response, fetch: fetchMock, MessageChannel: Channel,
    setTimeout: (callback: () => void, ms: number) => setTimeout(callback, ms),
    clearTimeout: (timer: ReturnType<typeof setTimeout>) => clearTimeout(timer),
  });
});

function configure(id: string, token: string) {
  const client = { id, url: `${origin}/player`, postMessage: vi.fn((_message, channels) => {
    channels?.[0].postMessage({ type: 'SW_DPOP_PROOF', proof: `proof-${id}` });
  }) };
  clients.set(id, client);
  handlers.message({ source: client, data: { type: 'SW_SET_API_URL', apiUrl: api } });
  handlers.message({ source: client, data: { type: 'SW_SET_TOKEN', token } });
  return client;
}

function request(clientId: string, url = `${api}/api/images/cover`) {
  let response: Promise<Response> | undefined;
  handlers.fetch({ clientId, request: new Request(url), respondWith: (value: Promise<Response>) => { response = value; } });
  return response;
}

it('does not intercept foreign origins even when their path matches', () => {
  configure('a', 'token-a');
  expect(request('a', 'https://foreign.test/api/images/cover')).toBeUndefined();
  expect(fetchMock).not.toHaveBeenCalled();
});

it('uses only the requesting tab for both signing and its token', async () => {
  const first = configure('a', 'token-a');
  const second = configure('b', 'token-b');
  await request('b');
  expect(first.postMessage).not.toHaveBeenCalled();
  expect(second.postMessage).toHaveBeenCalledOnce();
  expect(fetchMock.mock.calls[0][1].headers.get('Authorization')).toBe('DPoP token-b');
  expect(fetchMock.mock.calls[0][1].headers.get('DPoP')).toBe('proof-b');
  expect(fetchMock.mock.calls[0][1].redirect).toBe('error');
  expect(ports.every(port => port.close.mock.calls.length === 1)).toBe(true);
});

it('never borrows credentials for a client missing after a worker restart', () => {
  configure('a', 'token-a');
  expect(request('unknown')).toBeUndefined();
});

it('does not attach credentials if logout happens while signing', async () => {
  const client = configure('a', 'token-a');
  client.postMessage.mockImplementation((_message, channels) => {
    handlers.message({ source: client, data: { type: 'SW_SET_TOKEN', token: null } });
    channels[0].postMessage({ type: 'SW_DPOP_PROOF', proof: 'late-proof' });
  });
  await request('a');
  expect(fetchMock.mock.calls[0][1]?.headers?.has('Authorization') ?? false).toBe(false);
});

it('closes both ports when a tab fails to answer before the deadline', async () => {
  vi.useFakeTimers();
  try {
    const client = configure('a', 'token-a');
    client.postMessage.mockImplementation(() => {});
    const response = request('a');
    await vi.advanceTimersByTimeAsync(3001);
    await response;
    expect(ports.every(port => port.close.mock.calls.length === 1)).toBe(true);
    expect(fetchMock.mock.calls[0][1]?.headers?.has('Authorization') ?? false).toBe(false);
  } finally { vi.useRealTimers(); }
});
