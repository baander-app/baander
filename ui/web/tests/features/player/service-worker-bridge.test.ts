import { beforeEach, expect, it, vi } from 'vitest';

const auth = vi.hoisted(() => ({ accessToken: 'token' as string | null }));
const crypto = vi.hoisted(() => ({ key: {}, session: 1, proof: vi.fn() }));
const refresh = vi.hoisted(() => vi.fn());
vi.mock('@/features/auth/stores/auth-store', () => ({ getAuthSnapshot: () => auth }));
vi.mock('@/shared/crypto/dpop-store', () => ({
  getDpopKeyPair: () => crypto.key, getDpopNonce: () => null, setDpopNonce: vi.fn(),
}));
vi.mock('@/shared/crypto/dpop-proof', () => ({ createDpopProof: crypto.proof }));
vi.mock('@/shared/api-client/axios-instance', () => ({
  getAuthSession: () => crypto.session, ensureFreshAccessToken: refresh,
}));

let listener: (event: unknown) => Promise<void>;
let active: { postMessage: ReturnType<typeof vi.fn> };
let bridge: typeof import('@/features/player/services/service-worker-bridge');
beforeEach(async () => {
  vi.resetModules(); vi.clearAllMocks();
  auth.accessToken = 'token'; crypto.key = {}; crypto.session = 1; crypto.proof.mockResolvedValue('proof');
  refresh.mockResolvedValue('token');
  window.__BAANDER_API_URL__ = 'https://api.baander.app';
  class Worker { postMessage = vi.fn(); }
  vi.stubGlobal('ServiceWorker', Worker);
  active = new Worker();
  Object.defineProperty(navigator, 'serviceWorker', { configurable: true, value: {
    getRegistration: async () => ({ active }),
    addEventListener: (_name: string, callback: typeof listener) => { listener = callback; },
  } });
  bridge = await import('@/features/player/services/service-worker-bridge');
  bridge.initServiceWorkerListener();
});

function sign(source: unknown = active, url = 'https://api.baander.app/api/images/cover', token = 'token') {
  const port = { postMessage: vi.fn(), close: vi.fn() };
  return { port, done: listener({ source, data: { type: 'SW_SIGN_DPOP', method: 'GET', url, token, session: crypto.session }, ports: [port] }) };
}

it('signs configured API URLs and closes the reply port', async () => {
  const { port, done } = sign(); await done;
  expect(port.postMessage).toHaveBeenCalledWith(expect.objectContaining({ proof: 'proof' }));
  expect(port.close).toHaveBeenCalledOnce();
});

it('rejects an unrelated worker even when it has the correct type', async () => {
  const { port, done } = sign(new ServiceWorker()); await done;
  expect(crypto.proof).not.toHaveBeenCalled();
  expect(port.close).toHaveBeenCalledOnce();
});

it('rejects a request signed for a stale token', async () => {
  const { port, done } = sign(active, undefined, 'old-token'); await done;
  expect(crypto.proof).not.toHaveBeenCalled();
  expect(port.postMessage).toHaveBeenCalledWith({ type: 'SW_DPOP_PROOF', proof: null });
  expect(port.close).toHaveBeenCalledOnce();
});

it('does not return a proof after logout during signing', async () => {
  crypto.proof.mockImplementation(async () => { auth.accessToken = null; return 'late-proof'; });
  const { port, done } = sign(); await done;
  expect(port.postMessage).toHaveBeenCalledWith({ type: 'SW_DPOP_PROOF', proof: null });
});

it('reinitializes API configuration and token on worker restart, including logout', async () => {
  auth.accessToken = null;
  await listener({ source: active, data: { type: 'SW_REQUEST_TOKEN' }, ports: [] });
  expect(active.postMessage).toHaveBeenCalledExactlyOnceWith({
    type: 'SW_SET_AUTH', apiUrl: 'https://api.baander.app', token: null, session: 1, revision: 1,
  });
});

it('does not post a stale token after awaiting worker registration', async () => {
  auth.accessToken = null;
  await bridge.postTokenToWorker('old-token');
  expect(active.postMessage).not.toHaveBeenCalled();
});

function requestRefresh(overrides: Record<string, unknown> = {}, source: unknown = active) {
  const port = { postMessage: vi.fn(), close: vi.fn() };
  return { port, done: listener({ source, data: {
    type: 'SW_REFRESH_AUTH', method: 'GET', url: 'https://api.baander.app/api/images/cover',
    token: 'token', session: 1, ...overrides,
  }, ports: [port] }) };
}

it('shares refresh and replies with the exact atomically published snapshot', async () => {
  refresh.mockImplementation(async () => { auth.accessToken = 'rotated'; return 'rotated'; });
  const { port, done } = requestRefresh(); await done;
  expect(refresh).toHaveBeenCalledExactlyOnceWith(1, 'token');
  const snapshot = { apiUrl: 'https://api.baander.app', token: 'rotated', session: 1, revision: 1 };
  expect(active.postMessage).toHaveBeenCalledExactlyOnceWith({ type: 'SW_SET_AUTH', ...snapshot });
  expect(port.postMessage).toHaveBeenCalledExactlyOnceWith({ type: 'SW_AUTH_REFRESHED', auth: snapshot });
  expect(port.close).toHaveBeenCalledOnce();
});

it.each([
  { session: 2 },
  { token: null },
  { method: 'POST' },
  { url: 'https://foreign.baander.app/api/images/cover' },
  { url: 'https://api.baander.app/api/admin/users' },
])('rejects invalid refresh context %j without calling the coordinator', async overrides => {
  const { port, done } = requestRefresh(overrides); await done;
  expect(refresh).not.toHaveBeenCalled();
  expect(port.postMessage).toHaveBeenCalledExactlyOnceWith({ type: 'SW_AUTH_REFRESHED', auth: null });
  expect(port.close).toHaveBeenCalledOnce();
});

it('does not refresh for an unrelated worker', async () => {
  const { port, done } = requestRefresh({}, new ServiceWorker()); await done;
  expect(refresh).not.toHaveBeenCalled();
  expect(active.postMessage).not.toHaveBeenCalled();
  expect(port.close).toHaveBeenCalledOnce();
});

it.each(['logout', 'replacement'])('does not publish a refresh reply after %s', async change => {
  refresh.mockImplementation(async () => {
    crypto.key = {}; crypto.session = 2;
    auth.accessToken = change === 'logout' ? null : 'other-user-token';
    return 'old-session-replacement';
  });
  const { port, done } = requestRefresh(); await done;
  expect(active.postMessage).not.toHaveBeenCalled();
  expect(port.postMessage).toHaveBeenCalledExactlyOnceWith({ type: 'SW_AUTH_REFRESHED', auth: null });
  expect(port.close).toHaveBeenCalledOnce();
});

it('closes the refresh port and reports failure when refresh rejects', async () => {
  refresh.mockRejectedValue(new Error('Refresh unavailable'));
  const { port, done } = requestRefresh(); await done;
  expect(port.postMessage).toHaveBeenCalledExactlyOnceWith({ type: 'SW_AUTH_REFRESHED', auth: null });
  expect(port.close).toHaveBeenCalledOnce();
});

it('increments credential revisions across rotation and logout', async () => {
  await bridge.initWorkerApiUrl();
  auth.accessToken = 'rotated';
  await bridge.postTokenToWorker('rotated');
  auth.accessToken = null; crypto.session = 0;
  await bridge.postTokenToWorker(null);
  expect(active.postMessage.mock.calls.map(([message]) => [message.token, message.session, message.revision]))
    .toEqual([['token', 1, 1], ['rotated', 1, 2], [null, 0, 3]]);
});

it('rejects proof requests from another session even if the token matches', async () => {
  const port = { postMessage: vi.fn(), close: vi.fn() };
  await listener({ source: active, data: { type: 'SW_SIGN_DPOP', method: 'GET',
    url: 'https://api.baander.app/api/images/cover', token: 'token', session: 2 }, ports: [port] });
  expect(crypto.proof).not.toHaveBeenCalled();
  expect(port.postMessage).toHaveBeenCalledExactlyOnceWith({ type: 'SW_DPOP_PROOF', proof: null });
  expect(port.close).toHaveBeenCalledOnce();
});
