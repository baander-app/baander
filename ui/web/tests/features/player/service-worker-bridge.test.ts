import { beforeEach, expect, it, vi } from 'vitest';

const auth = vi.hoisted(() => ({ accessToken: 'token' as string | null }));
const crypto = vi.hoisted(() => ({ key: {}, proof: vi.fn() }));
vi.mock('@/features/auth/stores/auth-store', () => ({ getAuthSnapshot: () => auth }));
vi.mock('@/shared/crypto/dpop-store', () => ({
  getDpopKeyPair: () => crypto.key, getDpopNonce: () => null, setDpopNonce: vi.fn(),
}));
vi.mock('@/shared/crypto/dpop-proof', () => ({ createDpopProof: crypto.proof }));

let listener: (event: unknown) => Promise<void>;
let active: { postMessage: ReturnType<typeof vi.fn> };
let bridge: typeof import('@/features/player/services/service-worker-bridge');
beforeEach(async () => {
  vi.resetModules(); vi.clearAllMocks();
  auth.accessToken = 'token'; crypto.proof.mockResolvedValue('proof');
  window.__BAANDER_API_URL__ = 'https://api.baander.test';
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

function sign(source: unknown = active, url = 'https://api.baander.test/api/images/cover', token = 'token') {
  const port = { postMessage: vi.fn(), close: vi.fn() };
  return { port, done: listener({ source, data: { type: 'SW_SIGN_DPOP', method: 'GET', url, token }, ports: [port] }) };
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
  expect(active.postMessage).toHaveBeenCalledWith({ type: 'SW_SET_API_URL', apiUrl: 'https://api.baander.test' });
  expect(active.postMessage).toHaveBeenCalledWith({ type: 'SW_SET_TOKEN', token: null });
});

it('does not post a stale token after awaiting worker registration', async () => {
  auth.accessToken = null;
  await bridge.postTokenToWorker('old-token');
  expect(active.postMessage).not.toHaveBeenCalledWith({ type: 'SW_SET_TOKEN', token: 'old-token' });
});
