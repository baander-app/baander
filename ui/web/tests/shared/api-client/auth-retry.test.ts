import Axios from 'axios';
import MockAdapter from 'axios-mock-adapter';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const auth = vi.hoisted(() => ({
  accessToken: 'old-access' as string | null,
  refreshToken: 'old-refresh' as string | null,
  setTokens: vi.fn(), clearAuth: vi.fn(),
}));
const crypto = vi.hoisted(() => ({ key: {}, nonce: null as string | null, proof: vi.fn() }));
vi.mock('@/features/auth/stores/auth-store', () => ({ useAuthStore: { getState: () => auth } }));
vi.mock('@/shared/crypto/dpop-store', () => ({
  getDpopKeyPair: () => crypto.key, getDpopNonce: () => crypto.nonce,
  setDpopNonce: (nonce: string) => { crypto.nonce = nonce; },
}));
vi.mock('@/shared/crypto/dpop-proof', () => ({ createDpopProof: crypto.proof }));
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance';
import { schedulerAdminApi } from '@/features/admin/api/scheduler-admin-api';

let api: MockAdapter;
let refresh: MockAdapter;
beforeEach(() => {
  vi.clearAllMocks();
  window.__BAANDER_API_URL__ = 'https://api.baander.app';
  AXIOS_INSTANCE.defaults.baseURL = window.__BAANDER_API_URL__;
  auth.accessToken = 'old-access'; auth.refreshToken = 'old-refresh'; crypto.nonce = null;
  auth.setTokens.mockImplementation((accessToken, refreshToken) => Object.assign(auth, { accessToken, refreshToken }));
  crypto.proof.mockResolvedValue('proof');
  api = new MockAdapter(AXIOS_INSTANCE);
  refresh = new MockAdapter(Axios);
});
afterEach(() => { api.restore(); refresh.restore(); });

it('bounds retries for both the initiator and queued requests', async () => {
  api.onGet('/private').reply(401);
  refresh.onPost().reply(async () => {
    await new Promise(resolve => setTimeout(resolve, 10));
    // Stop the old implementation after its erroneous second rotation.
    return refresh.history.post.length > 1 ? [500] : [200, { accessToken: 'new-access', refreshToken: 'new-refresh' }];
  });
  const outcomes = await Promise.allSettled([AXIOS_INSTANCE.get('/private'), AXIOS_INSTANCE.get('/private')]);
  expect(outcomes.every(result => result.status === 'rejected')).toBe(true);
  expect(refresh.history.post).toHaveLength(1);
  expect(api.history.get).toHaveLength(4);
  expect(auth.refreshToken).toBe('new-refresh');
});

it('retries a nonce challenge during refresh with a newly signed proof', async () => {
  api.onGet('/private').replyOnce(401).onGet('/private').reply(200);
  refresh.onPost().replyOnce(400, { error: 'use_dpop_nonce' }, { 'dpop-nonce': 'required-nonce' })
    .onPost().reply(200, { data: { accessToken: 'new-access', refreshToken: 'new-refresh' } });
  await expect(AXIOS_INSTANCE.get('/private')).resolves.toHaveProperty('status', 200);
  expect(refresh.history.post).toHaveLength(2);
  expect(crypto.proof).toHaveBeenCalledWith(crypto.key, 'POST', 'https://api.baander.app/api/auth/refresh', { nonce: 'required-nonce' });
});

it('never attaches credentials or accepts nonces from another origin', async () => {
  api.onGet('https://foreign.baander.app/api/images/x').reply(200, {}, { 'dpop-nonce': 'foreign' });
  await AXIOS_INSTANCE.get('https://foreign.baander.app/api/images/x');
  expect(api.history.get[0].headers?.Authorization).toBeUndefined();
  expect(api.history.get[0].headers?.DPoP).toBeUndefined();
  expect(crypto.proof).not.toHaveBeenCalled();
  expect(crypto.nonce).toBeNull();
});

it('does not restore a session logged out while refresh is pending', async () => {
  api.onGet('/private').replyOnce(401).onGet('/private').reply(200);
  refresh.onPost().reply(() => {
    auth.accessToken = null; auth.refreshToken = null;
    return [200, { accessToken: 'late-access', refreshToken: 'late-refresh' }];
  });
  await expect(AXIOS_INSTANCE.get('/private')).rejects.toThrow();
  expect(auth.setTokens).not.toHaveBeenCalled();
  expect(auth.accessToken).toBeNull();
});

it('retains a manual scheduler request identity across authentication refresh', async () => {
  const jobId = '019a0000-0000-7000-8000-000000000001';
  const path = `/api/admin/scheduler/jobs/${jobId}/trigger`;
  const receipt = { occurrenceId: '019a0000-0000-7000-8000-000000000002', jobId };
  api.onPost(path).replyOnce(401).onPost(path).reply(202, { data: receipt });
  refresh.onPost().reply(200, { accessToken: 'new-access', refreshToken: 'new-refresh' });

  await expect(schedulerAdminApi.trigger(jobId)).resolves.toEqual(receipt);

  expect(api.history.post).toHaveLength(2);
  const key = api.history.post[0].headers?.['Idempotency-Key'];
  expect(key).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i);
  expect(api.history.post[1].headers?.['Idempotency-Key']).toBe(key);
  expect(refresh.history.post).toHaveLength(1);
});
