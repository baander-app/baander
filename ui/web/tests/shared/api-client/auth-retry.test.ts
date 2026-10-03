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
import { AXIOS_INSTANCE, ensureFreshAccessToken, getAuthSession } from '@/shared/api-client/axios-instance';
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

it('does not replay an old session mutation with a new account’s credentials', async () => {
  api.onPost('/private-action').replyOnce(() => {
    crypto.key = {};
    auth.accessToken = 'other-access';
    auth.refreshToken = 'other-refresh';
    return [401];
  }).onPost('/private-action').reply(200);

  await expect(AXIOS_INSTANCE.post('/private-action', { value: 'old-account-action' })).rejects.toThrow();
  expect(api.history.post).toHaveLength(1);
  expect(refresh.history.post).toHaveLength(0);
  expect(auth.accessToken).toBe('other-access');
  expect(auth.clearAuth).not.toHaveBeenCalled();
});

it('discards an old session response and its nonce after account replacement', async () => {
  api.onGet('/private').reply(() => {
    crypto.key = {};
    auth.accessToken = 'other-access';
    auth.refreshToken = 'other-refresh';
    return [200, { privateData: 'previous-account' }, { 'dpop-nonce': 'old-session-nonce' }];
  });

  await expect(AXIOS_INSTANCE.get('/private')).rejects.toThrow();
  expect(crypto.nonce).toBeNull();
  expect(auth.accessToken).toBe('other-access');
});

it('starts a new session refresh independently of a pending old session refresh', async () => {
  let releaseOld!: (reply: [number, object]) => void;
  const oldReply = new Promise<[number, object]>(resolve => { releaseOld = resolve; });
  api.onGet('/old-private').reply(401);
  api.onGet('/new-private').replyOnce(401).onGet('/new-private').reply(200);
  refresh.onPost().replyOnce(() => oldReply)
    .onPost().reply(200, { accessToken: 'other-rotated', refreshToken: 'other-rotated-refresh' });
  const oldRequest = AXIOS_INSTANCE.get('/old-private').catch(error => error);
  let newRequest: Promise<unknown> | undefined;
  try {
    await vi.waitFor(() => expect(refresh.history.post).toHaveLength(1));
    crypto.key = {};
    auth.accessToken = 'other-access';
    auth.refreshToken = 'other-refresh';
    newRequest = AXIOS_INSTANCE.get('/new-private').catch(error => error);
    await vi.waitFor(() => expect(refresh.history.post).toHaveLength(2));
    await expect(newRequest).resolves.toHaveProperty('status', 200);
  } finally {
    releaseOld([200, { accessToken: 'old-late', refreshToken: 'old-late-refresh' }]);
    await Promise.all([oldRequest, newRequest]);
  }
  expect(auth.accessToken).toBe('other-rotated');
  expect(auth.clearAuth).not.toHaveBeenCalled();
});

it('keeps the new refresh slot when an obsolete refresh finishes', async () => {
  let releaseOld!: (reply: [number, object]) => void;
  let releaseNew!: (reply: [number, object]) => void;
  const oldReply = new Promise<[number, object]>(resolve => { releaseOld = resolve; });
  const newReply = new Promise<[number, object]>(resolve => { releaseNew = resolve; });
  api.onGet('/old-private').reply(401);
  api.onGet('/new-private').replyOnce(401).onGet('/new-private').reply(200);
  api.onGet('/queued-new-private').replyOnce(401).onGet('/queued-new-private').reply(200);
  refresh.onPost().replyOnce(() => oldReply).onPost().replyOnce(() => newReply)
    .onPost().reply(500);
  const oldRequest = AXIOS_INSTANCE.get('/old-private').catch(error => error);
  let newRequest: Promise<unknown> | undefined;
  let queuedRequest: Promise<unknown> | undefined;
  try {
    await vi.waitFor(() => expect(refresh.history.post).toHaveLength(1));
    crypto.key = {};
    auth.accessToken = 'other-access';
    auth.refreshToken = 'other-refresh';
    newRequest = AXIOS_INSTANCE.get('/new-private').catch(error => error);
    await vi.waitFor(() => expect(refresh.history.post).toHaveLength(2));
    releaseOld([200, { accessToken: 'old-late', refreshToken: 'old-late-refresh' }]);
    await oldRequest;
    queuedRequest = AXIOS_INSTANCE.get('/queued-new-private').catch(error => error);
    await vi.waitFor(() => expect(api.history.get).toHaveLength(3));
    releaseNew([200, { accessToken: 'other-rotated', refreshToken: 'other-rotated-refresh' }]);
    await expect(newRequest).resolves.toHaveProperty('status', 200);
    await expect(queuedRequest).resolves.toHaveProperty('status', 200);
    expect(refresh.history.post).toHaveLength(2);
    expect(auth.clearAuth).not.toHaveBeenCalled();
  } finally {
    releaseOld([200, { accessToken: 'old-late', refreshToken: 'old-late-refresh' }]);
    releaseNew([200, { accessToken: 'other-rotated', refreshToken: 'other-rotated-refresh' }]);
    await Promise.all([oldRequest, newRequest, queuedRequest]);
  }
});

it('reuses a completed rotation for a late 401 in the same session', async () => {
  let releaseLate!: (reply: [number]) => void;
  const lateReply = new Promise<[number]>(resolve => { releaseLate = resolve; });
  api.onGet('/late-private').replyOnce(() => lateReply).onGet('/late-private').reply(200);
  api.onGet('/private').replyOnce(401).onGet('/private').reply(200);
  refresh.onPost().reply(200, { accessToken: 'new-access', refreshToken: 'new-refresh' });
  const lateRequest = AXIOS_INSTANCE.get('/late-private');
  try {
    await vi.waitFor(() => expect(api.history.get).toHaveLength(1));
    await AXIOS_INSTANCE.get('/private');
  } finally {
    releaseLate([401]);
  }
  await expect(lateRequest).resolves.toHaveProperty('status', 200);
  expect(refresh.history.post).toHaveLength(1);
  expect(api.history.get.at(-1)?.headers?.Authorization).toBe('DPoP new-access');
});


it('shares one refresh between a native caller and an Axios 401', async () => {
  let release!: (reply: [number, object]) => void;
  const pending = new Promise<[number, object]>(resolve => { release = resolve; });
  refresh.onPost().reply(() => pending);
  api.onGet('/private').replyOnce(401).onGet('/private').reply(200);
  const native = ensureFreshAccessToken(getAuthSession(), 'old-access');
  const request = AXIOS_INSTANCE.get('/private');
  try {
    await vi.waitFor(() => expect(api.history.get).toHaveLength(1));
    expect(refresh.history.post).toHaveLength(1);
  } finally {
    release([200, { accessToken: 'new-access', refreshToken: 'new-refresh' }]);
  }
  await expect(native).resolves.toBe('new-access');
  await expect(request).resolves.toHaveProperty('status', 200);
  expect(refresh.history.post).toHaveLength(1);
});

it('rejects a direct refresh from an old session without HTTP or clearing the new login', async () => {
  const session = getAuthSession();
  crypto.key = {};
  auth.accessToken = 'other-access'; auth.refreshToken = 'other-refresh';
  await expect(ensureFreshAccessToken(session, 'old-access')).rejects.toThrow();
  expect(refresh.history.post).toHaveLength(0);
  expect(auth.clearAuth).not.toHaveBeenCalled();
});

it('returns the current token to a late native failure without rotating again', async () => {
  const session = getAuthSession();
  auth.accessToken = 'new-access'; auth.refreshToken = 'new-refresh';
  await expect(ensureFreshAccessToken(session, 'old-access')).resolves.toBe('new-access');
  expect(refresh.history.post).toHaveLength(0);
});
