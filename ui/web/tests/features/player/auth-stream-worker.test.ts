// @vitest-environment node
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { transformSync } from 'esbuild';
import { beforeEach, expect, it, vi } from 'vitest';

const code = transformSync(readFileSync('src/features/player/services/auth-stream-worker.ts', 'utf8'), { loader: 'ts' }).code;
const origin = 'https://web.baander.app';
const api = 'https://api.baander.app';
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
  publish(client, token);
  return client;
}

function publish(client: { id: string; url: string }, token: string | null, revision = 1, session = 1, apiUrl = api) {
  handlers.message({ source: client, data: { type: 'SW_SET_AUTH', apiUrl, token, session, revision } });
}

function request(clientId: string, url = `${api}/api/images/cover`, init?: RequestInit) {
  let response: Promise<Response> | undefined;
  handlers.fetch({ clientId, request: new Request(url, init), respondWith: (value: Promise<Response>) => { response = value; } });
  return response;
}

it('does not intercept foreign origins even when their path matches', () => {
  configure('a', 'token-a');
  expect(request('a', 'https://foreign.baander.app/api/images/cover')).toBeUndefined();
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
    publish(client, null, 2, 0);
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

it.each(['GET', 'HEAD'])('forwards authorized no-cors %s as CORS without cookies', async method => {
  configure('a', 'token-a');
  await request('a', `${api}/api/images/cover`, {
    method, mode: 'no-cors', credentials: 'include', cache: 'force-cache',
    headers: { Range: 'bytes=0-1023', Accept: 'image/*' },
  });

  expect(fetchMock).toHaveBeenCalledOnce();
  const [input, init] = fetchMock.mock.calls[0];
  expect(input.mode).toBe('no-cors');
  // Node does not enforce the browser's no-cors header guard. Assert the final
  // Request contract here; real-browser qualification verifies header delivery.
  const forwarded = new Request(input, init);
  expect(forwarded.mode).toBe('cors');
  expect(forwarded.credentials).toBe('omit');
  expect(forwarded.redirect).toBe('error');
  expect(forwarded.method).toBe(method);
  expect(forwarded.cache).toBe('force-cache');
  expect(forwarded.headers.get('Range')).toBe('bytes=0-1023');
  expect(forwarded.headers.get('Accept')).toBe('image/*');
  expect(forwarded.headers.get('Authorization')).toBe('DPoP token-a');
  expect(forwarded.headers.get('DPoP')).toBe('proof-a');
});

it('refreshes an authorized 401 once with a fresh token and proof, preserving the request', async () => {
  const unauthorized = new Response('expired', { status: 401, headers: { 'dpop-nonce': 'old-nonce' } });
  const cancel = vi.spyOn(unauthorized.body!, 'cancel');
  fetchMock.mockResolvedValueOnce(unauthorized).mockResolvedValueOnce(new Response('ok'));
  const client = configure('a', 'old');
  client.postMessage.mockImplementation((message, channels) => {
    if (message.type === 'SW_SIGN_DPOP') {
      channels[0].postMessage({ type: 'SW_DPOP_PROOF', proof: `proof-${message.token}` });
    } else {
      expect(message).toEqual({ type: 'SW_REFRESH_AUTH', method: 'GET', url: `${api}/api/images/cover`, token: 'old', session: 1 });
      expect(cancel).not.toHaveBeenCalled();
      channels[0].postMessage({ type: 'SW_AUTH_REFRESHED', auth: { apiUrl: api, token: 'fresh', session: 1, revision: 2 } });
    }
  });
  const result = await request('a', `${api}/api/images/cover?size=large`, { headers: { Range: 'bytes=2-99' }, mode: 'no-cors' });
  expect(await result!.text()).toBe('ok');
  expect(fetchMock).toHaveBeenCalledTimes(2);
  const [input, init] = fetchMock.mock.calls[1];
  const retry = new Request(input, init);
  expect(retry.url).toBe(`${api}/api/images/cover?size=large`);
  expect(retry.headers.get('Range')).toBe('bytes=2-99');
  expect(retry.headers.get('Authorization')).toBe('DPoP fresh');
  expect(retry.headers.get('DPoP')).toBe('proof-fresh');
  expect(retry.mode).toBe('cors');
  expect(retry.credentials).toBe('omit');
  expect(retry.redirect).toBe('error');
  expect(cancel).toHaveBeenCalledOnce();
  expect(ports.every(port => port.close.mock.calls.length === 1)).toBe(true);
});

it.each(['before', 'after'])('accepts refresh publications arriving %s the reply without invalidating proof', async order => {
  fetchMock.mockResolvedValueOnce(new Response('expired', { status: 401 }));
  const client = configure('a', 'old');
  client.postMessage.mockImplementation((message, channels) => {
    if (message.type === 'SW_REFRESH_AUTH') {
      if (order === 'before') publish(client, 'fresh', 3);
      channels[0].postMessage({ type: 'SW_AUTH_REFRESHED', auth: { apiUrl: api, token: 'fresh', session: 1, revision: 2 } });
      if (order === 'after') publish(client, 'fresh', 3);
    } else {
      // Equal and higher revisions for the same credentials preserve this pending proof.
      if (message.token === 'fresh') { publish(client, 'fresh', 3); publish(client, 'fresh', 4); }
      channels[0].postMessage({ type: 'SW_DPOP_PROOF', proof: `proof-${message.token}` });
    }
  });
  await request('a');
  expect(fetchMock).toHaveBeenCalledTimes(2);
  expect(fetchMock.mock.calls[1][1].headers.get('Authorization')).toBe('DPoP fresh');
});

it('never overwrites a newer token publication with an older refresh reply', async () => {
  fetchMock.mockResolvedValueOnce(new Response('expired', { status: 401 }));
  const client = configure('a', 'old');
  client.postMessage.mockImplementation((message, channels) => {
    if (message.type === 'SW_REFRESH_AUTH') {
      publish(client, 'newest', 3);
      channels[0].postMessage({ type: 'SW_AUTH_REFRESHED', auth: { apiUrl: api, token: 'older', session: 1, revision: 2 } });
    } else channels[0].postMessage({ type: 'SW_DPOP_PROOF', proof: `proof-${message.token}` });
  });
  await request('a');
  expect(fetchMock.mock.calls[1][1].headers.get('Authorization')).toBe('DPoP newest');
});

it.each(['logout', 'account', 'origin', 'null', 'session', 'foreignOrigin'])('returns the original readable 401 on %s during refresh', async failure => {
  const unauthorized = new Response('original error', { status: 401 });
  fetchMock.mockResolvedValueOnce(unauthorized);
  const client = configure('a', 'old');
  client.postMessage.mockImplementation((message, channels) => {
    if (message.type === 'SW_SIGN_DPOP') channels[0].postMessage({ type: 'SW_DPOP_PROOF', proof: 'proof' });
    else {
      if (failure === 'logout') publish(client, null, 3, 0);
      if (failure === 'account') publish(client, 'another-account', 3, 2);
      if (failure === 'origin') publish(client, 'fresh', 3, 1, 'https://other.baander.app');
      channels[0].postMessage({ type: 'SW_AUTH_REFRESHED', auth: failure === 'null' ? null : {
        apiUrl: failure === 'foreignOrigin' ? 'https://other.baander.app' : api,
        token: 'fresh', session: failure === 'session' ? 2 : 1, revision: 2,
      } });
    }
  });
  const result = await request('a');
  expect(result).toBe(unauthorized);
  expect(await result!.text()).toBe('original error');
  expect(fetchMock).toHaveBeenCalledOnce();
  expect(ports.every(port => port.close.mock.calls.length === 1)).toBe(true);
});

it('does not refresh a second 401', async () => {
  fetchMock.mockResolvedValue(new Response('expired', { status: 401 }));
  const client = configure('a', 'old');
  client.postMessage.mockImplementation((message, channels) => {
    channels[0].postMessage(message.type === 'SW_SIGN_DPOP'
      ? { type: 'SW_DPOP_PROOF', proof: 'proof' }
      : { type: 'SW_AUTH_REFRESHED', auth: { apiUrl: api, token: 'fresh', session: 1, revision: 2 } });
  });
  expect((await request('a'))!.status).toBe(401);
  expect(fetchMock).toHaveBeenCalledTimes(2);
  expect(client.postMessage.mock.calls.filter(([message]) => message.type === 'SW_REFRESH_AUTH')).toHaveLength(1);
});

it('keeps the first 401 body readable if the retry proof fails', async () => {
  const unauthorized = new Response('expired', { status: 401 });
  fetchMock.mockResolvedValueOnce(unauthorized);
  const client = configure('a', 'old');
  client.postMessage.mockImplementation((message, channels) => {
    if (message.type === 'SW_REFRESH_AUTH') channels[0].postMessage({ type: 'SW_AUTH_REFRESHED', auth: { apiUrl: api, token: 'fresh', session: 1, revision: 2 } });
    else channels[0].postMessage({ type: 'SW_DPOP_PROOF', proof: message.token === 'old' ? 'proof' : null });
  });
  expect(await (await request('a'))!.text()).toBe('expired');
  expect(fetchMock).toHaveBeenCalledOnce();
});

it('bounds concurrent refreshes and releases all ports and capacity on timeout', async () => {
  vi.useFakeTimers();
  try {
    fetchMock.mockImplementation(async () => new Response('expired', { status: 401 }));
    const client = configure('a', 'old');
    client.postMessage.mockImplementation((message, channels) => {
      if (message.type === 'SW_SIGN_DPOP') channels[0].postMessage({ type: 'SW_DPOP_PROOF', proof: 'proof' });
    });
    const requests: Array<Promise<Response> | undefined> = [];
    for (let i = 0; i < 33; i++) {
      requests.push(request('a'));
      await vi.advanceTimersByTimeAsync(0);
    }
    expect(client.postMessage.mock.calls.filter(([message]) => message.type === 'SW_REFRESH_AUTH')).toHaveLength(32);
    await vi.advanceTimersByTimeAsync(35001);
    const responses = await Promise.all(requests);
    expect(responses.every(response => response?.status === 401)).toBe(true);
    expect(await responses[0]!.text()).toBe('expired');
    expect(ports.every(port => port.close.mock.calls.length === 1)).toBe(true);
    const next = request('a');
    await vi.advanceTimersByTimeAsync(0);
    expect(client.postMessage.mock.calls.filter(([message]) => message.type === 'SW_REFRESH_AUTH')).toHaveLength(33);
    await vi.advanceTimersByTimeAsync(35001);
    await next;
    expect(vi.getTimerCount()).toBe(0);
  } finally { vi.useRealTimers(); }
});

it('isolates concurrent refresh replies to their requesting clients', async () => {
  fetchMock.mockImplementationOnce(async () => new Response('a expired', { status: 401 }))
    .mockImplementationOnce(async () => new Response('b expired', { status: 401 }));
  const first = configure('a', 'old-a');
  const second = configure('b', 'old-b');
  for (const client of [first, second]) client.postMessage.mockImplementation((message, channels) => {
    channels[0].postMessage(message.type === 'SW_SIGN_DPOP'
      ? { type: 'SW_DPOP_PROOF', proof: `proof-${client.id}-${message.token}` }
      : { type: 'SW_AUTH_REFRESHED', auth: { apiUrl: api, token: `fresh-${client.id}`, session: 1, revision: 2 } });
  });
  await Promise.all([request('a'), request('b')]);
  const retryHeaders = fetchMock.mock.calls.slice(2).map(([, init]) => init.headers);
  expect(retryHeaders.map(headers => headers.get('Authorization')).sort()).toEqual(['DPoP fresh-a', 'DPoP fresh-b']);
  expect(retryHeaders.map(headers => headers.get('DPoP')).sort()).toEqual(['proof-a-fresh-a', 'proof-b-fresh-b']);
});

it('rejects invalid snapshots and messages from another origin', async () => {
  const client = configure('a', 'valid');
  const invalid = [
    { apiUrl: 'https://user:pass@api.baander.app', token: 'bad', session: 1, revision: 2 },
    { apiUrl: api, token: 'bad', session: NaN, revision: 2 },
    { apiUrl: api, token: 'bad', session: 1, revision: Infinity },
    { apiUrl: api, token: 'bad', session: 0, revision: 2 },
    { apiUrl: api, token: 'bad', session: 1 },
  ];
  invalid.forEach(snapshot => handlers.message({ source: client, data: { type: 'SW_SET_AUTH', ...snapshot } }));
  publish({ id: 'a', url: 'https://foreign.baander.app' }, 'bad', 5);
  publish(client, 'lower', 0);
  publish(client, 'conflicting', 1);
  await request('a');
  expect(fetchMock.mock.calls[0][1].headers.get('Authorization')).toBe('DPoP valid');
});

it('preserves the nonce through duplicate snapshots while rejecting stale token nonce updates', async () => {
  const client = configure('a', 'old');
  fetchMock.mockImplementationOnce(async () => {
    publish(client, 'fresh', 2);
    return new Response('ok', { headers: { 'dpop-nonce': 'stale-nonce' } });
  });
  await request('a');
  client.postMessage.mockClear();
  await request('a');
  expect(client.postMessage.mock.calls[0][0].nonce).toBeUndefined();
  fetchMock.mockResolvedValueOnce(new Response('ok', { headers: { 'dpop-nonce': 'current-nonce' } }));
  await request('a');
  publish(client, 'fresh', 2);
  publish(client, 'fresh', 3);
  client.postMessage.mockClear();
  await request('a');
  expect(client.postMessage.mock.calls[0][0].nonce).toBe('current-nonce');
});

it('rejects a different client returned while routing refresh, retaining the original 401', async () => {
  const unauthorized = new Response('expired', { status: 401 });
  const client = configure('a', 'old');
  const other = configure('b', 'other');
  fetchMock.mockImplementationOnce(async () => {
    clients.set('a', other);
    return unauthorized;
  });
  expect(await request('a')).toBe(unauthorized);
  expect(fetchMock).toHaveBeenCalledOnce();
  expect(client.postMessage.mock.calls.filter(([message]) => message.type === 'SW_REFRESH_AUTH')).toHaveLength(0);
  expect(other.postMessage).not.toHaveBeenCalled();
  expect(ports.every(port => port.close.mock.calls.length === 1)).toBe(true);
});

it('ignores a late refresh answer after timeout', async () => {
  vi.useFakeTimers();
  try {
    const unauthorized = new Response('expired', { status: 401 });
    fetchMock.mockResolvedValueOnce(unauthorized);
    const client = configure('a', 'old');
    let refreshPort: { postMessage: (data: unknown) => void } | undefined;
    client.postMessage.mockImplementation((message, channels) => {
      if (message.type === 'SW_SIGN_DPOP') channels[0].postMessage({ type: 'SW_DPOP_PROOF', proof: 'proof' });
      else refreshPort = channels[0];
    });
    const pending = request('a');
    await vi.advanceTimersByTimeAsync(35001);
    expect(await pending).toBe(unauthorized);
    refreshPort!.postMessage({ type: 'SW_AUTH_REFRESHED', auth: { apiUrl: api, token: 'late', session: 1, revision: 2 } });
    await request('a');
    expect(fetchMock.mock.calls[1][1].headers.get('Authorization')).toBe('DPoP old');
    expect(ports.every(port => port.close.mock.calls.length === 1)).toBe(true);
    expect(vi.getTimerCount()).toBe(0);
  } finally { vi.useRealTimers(); }
});

it('re-signs once when a concurrent refresh rotates the token during the first proof', async () => {
  const client = configure('a', 'old');
  client.postMessage.mockImplementation((message, channels) => {
    expect(message.type).toBe('SW_SIGN_DPOP');
    if (message.token === 'old') {
      publish(client, 'fresh', 2);
      channels[0].postMessage({ type: 'SW_DPOP_PROOF', proof: null });
    } else channels[0].postMessage({ type: 'SW_DPOP_PROOF', proof: 'fresh-proof' });
  });
  await request('a');
  expect(client.postMessage).toHaveBeenCalledTimes(2);
  expect(fetchMock).toHaveBeenCalledOnce();
  expect(fetchMock.mock.calls[0][1].headers.get('Authorization')).toBe('DPoP fresh');
  expect(fetchMock.mock.calls[0][1].headers.get('DPoP')).toBe('fresh-proof');
  expect(ports.every(port => port.close.mock.calls.length === 1)).toBe(true);
});

it('does not re-sign for another login while the first proof is pending', async () => {
  const client = configure('a', 'old');
  client.postMessage.mockImplementation((_message, channels) => {
    publish(client, 'another-login', 2, 2);
    channels[0].postMessage({ type: 'SW_DPOP_PROOF', proof: null });
  });
  await request('a');
  expect(client.postMessage).toHaveBeenCalledOnce();
  expect(fetchMock).toHaveBeenCalledOnce();
  expect(fetchMock.mock.calls[0][1]).toBeUndefined();
});

it('does not re-sign after proof timeout even when the token has rotated', async () => {
  vi.useFakeTimers();
  try {
    const client = configure('a', 'old');
    client.postMessage.mockImplementation(() => { publish(client, 'fresh', 2); });
    const pending = request('a');
    await vi.advanceTimersByTimeAsync(3001);
    await pending;
    expect(client.postMessage).toHaveBeenCalledOnce();
    expect(fetchMock).toHaveBeenCalledOnce();
    expect(fetchMock.mock.calls[0][1]).toBeUndefined();
  } finally { vi.useRealTimers(); }
});

it('bounds initial re-signing even if another rotation invalidates the second proof', async () => {
  const client = configure('a', 'old');
  client.postMessage.mockImplementation((message, channels) => {
    publish(client, message.token === 'old' ? 'fresh' : 'newest', message.token === 'old' ? 2 : 3);
    channels[0].postMessage({ type: 'SW_DPOP_PROOF', proof: null });
  });
  await request('a');
  expect(client.postMessage).toHaveBeenCalledTimes(2);
  expect(fetchMock).toHaveBeenCalledOnce();
  expect(fetchMock.mock.calls[0][1]).toBeUndefined();
});
