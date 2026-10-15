import Axios from 'axios';
import MockAdapter from 'axios-mock-adapter';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const auth = vi.hoisted(() => ({
  accessToken: 'old-access' as string | null,
  refreshToken: 'old-refresh' as string | null,
  setTokens: vi.fn(), clearAuth: vi.fn(), adoptStoredAuth: vi.fn(),
}));
const crypto = vi.hoisted(() => ({ key: {}, nonce: null as string | null, proof: vi.fn() }));
vi.mock('@/features/auth/stores/auth-store', () => ({ useAuthStore: { getState: () => auth } }));
vi.mock('@/shared/crypto/dpop-store', () => ({
  getDpopKeyPair: () => crypto.key, getDpopNonce: () => crypto.nonce,
  setDpopNonce: (nonce: string) => { crypto.nonce = nonce; },
}));
vi.mock('@/shared/crypto/dpop-proof', () => ({ createDpopProof: crypto.proof }));
import { AXIOS_INSTANCE, ensureFreshAccessToken, getAuthSession } from '@/shared/api-client/axios-instance';
import { requestWebSocketTicket, webSocketEndpoint } from '@/shared/auth/websocket-ticket';
import { schedulerAdminApi } from '@/features/admin/api/scheduler-admin-api';
import { REFRESH_ATTEMPT_TIMEOUT_MS, REFRESH_BUDGET_MS } from '@/shared/auth/refresh-budget';
import { FakeLockManager, installLocks } from '../auth/auth-fakes';

let api: MockAdapter;
let refresh: MockAdapter;
let locks: FakeLockManager;
let restoreLocks: () => void;
beforeEach(() => {
  vi.clearAllMocks();
  locks = new FakeLockManager();
  restoreLocks = installLocks(locks);
  auth.adoptStoredAuth.mockResolvedValue(false);
  window.__BAANDER_API_URL__ = 'https://api.baander.app';
  AXIOS_INSTANCE.defaults.baseURL = window.__BAANDER_API_URL__;
  auth.accessToken = 'old-access'; auth.refreshToken = 'old-refresh'; crypto.nonce = null;
  auth.setTokens.mockImplementation((accessToken, refreshToken) => Object.assign(auth, { accessToken, refreshToken }));
  crypto.proof.mockResolvedValue('proof');
  api = new MockAdapter(AXIOS_INSTANCE);
  refresh = new MockAdapter(Axios);
});
afterEach(() => {
  api.restore();
  refresh.restore();
  restoreLocks();
});

/** A private resource that is answered 401 until the request carries the rotated token. */
function privateApi() {
  api.onGet('/private').reply(config => {
    return config.headers?.Authorization === 'DPoP new-access' ? [200, {}] : [401];
  });
}

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

it('refreshes a new session after a pending old session refresh releases the lock, and keeps its own rotation', async () => {
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
    await vi.waitFor(() => expect(api.history.get).toHaveLength(2));
    // One refresh per origin at a time: the new session waits for the old one's lock.
    expect(refresh.history.post).toHaveLength(1);
    expect(locks.isHeld()).toBe(true);
  } finally {
    releaseOld([200, { accessToken: 'old-late', refreshToken: 'old-late-refresh' }]);
    await oldRequest;
  }
  await expect(newRequest).resolves.toHaveProperty('status', 200);
  expect(refresh.history.post).toHaveLength(2);
  expect(auth.setTokens).not.toHaveBeenCalledWith('old-late', 'old-late-refresh');
  expect(auth.accessToken).toBe('other-rotated');
  expect(auth.clearAuth).not.toHaveBeenCalled();
  expect(locks.isHeld()).toBe(false);
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
    await vi.waitFor(() => expect(api.history.get).toHaveLength(2));
    releaseOld([200, { accessToken: 'old-late', refreshToken: 'old-late-refresh' }]);
    await oldRequest;
    await vi.waitFor(() => expect(refresh.history.post).toHaveLength(2));
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

describe('a refresh that fails', () => {
  let location: { href: string; origin: string };

  beforeEach(() => {
    location = { href: 'https://baander.app/library', origin: 'https://baander.app' };
    vi.stubGlobal('location', location);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  // A transport failure buys one more attempt; any other answer does not.
  const transientFailures: Array<[string, (handler: ReturnType<MockAdapter['onPost']>) => void, number]> = [
    ['a network error', handler => { handler.networkError(); }, 2],
    ['a timeout', handler => { handler.timeout(); }, 2],
    ['502', handler => { handler.reply(502); }, 1],
  ];

  for (const [failure, respond, attempts] of transientFailures) {
    it(`with ${failure} keeps the user signed in and fails only the request`, async () => {
      api.onGet('/private').reply(401);
      respond(refresh.onPost());

      await expect(AXIOS_INSTANCE.get('/private')).rejects.toThrow();

      expect(refresh.history.post).toHaveLength(attempts);
      expect(auth.clearAuth).not.toHaveBeenCalled();
      expect(auth.accessToken).toBe('old-access');
      expect(auth.refreshToken).toBe('old-refresh');
      expect(location.href).toBe('https://baander.app/library');
    });
  }

  it('with one timeout retries at once and replays the initiator and the queued request once', async () => {
    privateApi();
    refresh.onPost().timeoutOnce()
      .onPost().reply(200, { accessToken: 'new-access', refreshToken: 'new-refresh' });

    const outcomes = await Promise.all([AXIOS_INSTANCE.get('/private'), AXIOS_INSTANCE.get('/private')]);

    expect(outcomes.map(outcome => outcome.status)).toEqual([200, 200]);
    expect(refresh.history.post).toHaveLength(2);
    expect(refresh.history.post.map(attempt => attempt.timeout)).toEqual([REFRESH_ATTEMPT_TIMEOUT_MS, REFRESH_ATTEMPT_TIMEOUT_MS]);
    expect(api.history.get).toHaveLength(4);
    expect(auth.accessToken).toBe('new-access');
    expect(auth.clearAuth).not.toHaveBeenCalled();
    expect(location.href).toBe('https://baander.app/library');
  });

  it('through a native media caller with 502 keeps the user signed in', async () => {
    refresh.onPost().reply(502);

    await expect(ensureFreshAccessToken(getAuthSession(), 'old-access')).rejects.toThrow();

    expect(auth.clearAuth).not.toHaveBeenCalled();
    expect(location.href).toBe('https://baander.app/library');
  });

  const rejections: Array<[string, number, unknown]> = [
    ['401 for a refused refresh token', 401, { error: { message: 'Invalid refresh token.', code: 401 } }],
    ['400 invalid_grant', 400, { error: 'invalid_grant' }],
  ];

  for (const [rejection, status, body] of rejections) {
    it(`with ${rejection} signs the user out and sends them to the login page`, async () => {
      api.onGet('/private').reply(401);
      refresh.onPost().reply(status, body);

      await expect(AXIOS_INSTANCE.get('/private')).rejects.toThrow();

      expect(auth.clearAuth).toHaveBeenCalledOnce();
      expect(location.href).toBe('/login');
    });
  }

  it('with a second nonce challenge after the nonce retry keeps the user signed in', async () => {
    api.onGet('/private').reply(401);
    // A restarting server forgets the nonce it just issued and challenges again.
    refresh.onPost().replyOnce(400, { error: 'use_dpop_nonce' }, { 'dpop-nonce': 'first-nonce' })
      .onPost().replyOnce(400, { error: 'use_dpop_nonce' }, { 'dpop-nonce': 'second-nonce' });

    await expect(AXIOS_INSTANCE.get('/private')).rejects.toThrow();

    expect(refresh.history.post).toHaveLength(2);
    expect(auth.clearAuth).not.toHaveBeenCalled();
    expect(auth.refreshToken).toBe('old-refresh');
    expect(location.href).toBe('https://baander.app/library');
  });

  it('with a 400 that does not refuse the grant keeps the user signed in', async () => {
    api.onGet('/private').reply(401);
    refresh.onPost().reply(400, { error: { message: 'DPoP proof header is required.', code: 400 } });

    await expect(AXIOS_INSTANCE.get('/private')).rejects.toThrow();

    expect(auth.clearAuth).not.toHaveBeenCalled();
    expect(location.href).toBe('https://baander.app/library');
  });
});

describe('a refresh answered 503', () => {
  const rotated = { accessToken: 'new-access', refreshToken: 'new-refresh' };
  let location: { href: string; origin: string };
  let random: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    location = { href: 'https://baander.app/library', origin: 'https://baander.app' };
    vi.stubGlobal('location', location);
    vi.useFakeTimers();
    random = vi.spyOn(Math, 'random').mockReturnValue(0.5);
  });

  afterEach(() => {
    random.mockRestore();
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  function expectSessionKept() {
    expect(auth.clearAuth).not.toHaveBeenCalled();
    expect(auth.refreshToken).toBe('old-refresh');
    expect(location.href).toBe('https://baander.app/library');
  }

  it('waits Retry-After plus jitter, retries once and keeps the session throughout', async () => {
    privateApi();
    refresh.onPost().replyOnce(503, { error: 'temporarily_unavailable' }, { 'retry-after': '5' })
      .onPost().reply(200, rotated);

    const request = AXIOS_INSTANCE.get('/private');
    await vi.advanceTimersByTimeAsync(4_999);

    expect(refresh.history.post).toHaveLength(1);
    expectSessionKept();

    await vi.advanceTimersByTimeAsync(5_001);

    await expect(request).resolves.toHaveProperty('status', 200);
    expect(refresh.history.post).toHaveLength(2);
    expect(auth.refreshToken).toBe('new-refresh');
    expect(auth.clearAuth).not.toHaveBeenCalled();
    expect(location.href).toBe('https://baander.app/library');
  });

  it('twice keeps the session and fails the initiator and the queued request once', async () => {
    api.onGet('/private').reply(401);
    refresh.onPost().reply(503, { error: 'temporarily_unavailable' }, { 'retry-after': '5' });

    const outcomes = Promise.allSettled([AXIOS_INSTANCE.get('/private'), AXIOS_INSTANCE.get('/private')]);
    await vi.advanceTimersByTimeAsync(10_000);
    const results = await outcomes;

    expect(results.map(result => result.status)).toEqual(['rejected', 'rejected']);
    for (const result of results) {
      expect((result as PromiseRejectedResult).reason).toHaveProperty('response.status', 503);
    }
    expect(refresh.history.post).toHaveLength(2);
    expect(api.history.get).toHaveLength(2);
    expectSessionKept();
  });

  it('caps a long Retry-After at 10 s in total', async () => {
    privateApi();
    random.mockReturnValue(1);
    refresh.onPost().replyOnce(503, { error: 'temporarily_unavailable' }, { 'retry-after': '120' })
      .onPost().reply(200, rotated);

    const request = AXIOS_INSTANCE.get('/private');
    await vi.advanceTimersByTimeAsync(9_999);

    expect(refresh.history.post).toHaveLength(1);

    await vi.advanceTimersByTimeAsync(1);

    await expect(request).resolves.toHaveProperty('status', 200);
    expect(refresh.history.post).toHaveLength(2);
  });

  it('counts a nonce challenge after the wait against the budget and bounds the next attempt by what is left', async () => {
    privateApi();
    random.mockReturnValue(0);
    refresh.onPost()
      // 0 s to 10 s: the first attempt runs to its timeout before the 503 arrives.
      .replyOnce(() => {
        vi.setSystemTime(Date.now() + 10_000);
        return [503, { error: 'temporarily_unavailable' }, { 'retry-after': '5' }];
      })
      // 15 s to 25 s: the server takes its time and then challenges the nonce.
      .onPost().replyOnce(() => {
        vi.setSystemTime(Date.now() + 10_000);
        return [400, { error: 'use_dpop_nonce' }, { 'dpop-nonce': 'late-nonce' }];
      })
      .onPost().reply(200, rotated);

    const request = AXIOS_INSTANCE.get('/private');
    await vi.advanceTimersByTimeAsync(5_000);

    await expect(request).resolves.toHaveProperty('status', 200);
    expect(refresh.history.post).toHaveLength(3);
    expect(refresh.history.post.map(attempt => attempt.timeout)).toEqual([10_000, 10_000, 5_000]);
    expect(refresh.history.post[2].headers?.DPoP).toBeDefined();
    expect(crypto.proof).toHaveBeenCalledWith(crypto.key, 'POST', 'https://api.baander.app/api/auth/refresh', { nonce: 'late-nonce' });
  });

  it('does not start an attempt past the budget', async () => {
    api.onGet('/private').reply(401);
    random.mockReturnValue(1);
    refresh.onPost()
      // 0 s to 10 s: timeout, then a 503 asking for 10 s plus jitter, capped at 10 s: 20 s.
      .replyOnce(() => {
        vi.setSystemTime(Date.now() + 10_000);
        return [503, { error: 'temporarily_unavailable' }, { 'retry-after': '10' }];
      })
      // 20 s to 30 s: the nonce challenge arrives as the budget runs out.
      .onPost().replyOnce(() => {
        vi.setSystemTime(Date.now() + 10_000);
        return [400, { error: 'use_dpop_nonce' }, { 'dpop-nonce': 'late-nonce' }];
      })
      .onPost().reply(200, rotated);

    const request = AXIOS_INSTANCE.get('/private').catch(error => error);
    await vi.advanceTimersByTimeAsync(10_000);

    await expect(request).resolves.toHaveProperty('response.status', 400);
    expect(refresh.history.post).toHaveLength(2);
    expectSessionKept();
  });

  it('signs every attempt with a fresh proof from the same key', async () => {
    privateApi();
    let signed = 0;
    crypto.proof.mockImplementation(async () => `proof-${++signed}`);
    refresh.onPost().replyOnce(503, { error: 'temporarily_unavailable' }, { 'retry-after': '1' })
      .onPost().timeoutOnce()
      .onPost().replyOnce(400, { error: 'use_dpop_nonce' }, { 'dpop-nonce': 'required-nonce' })
      .onPost().reply(200, rotated);

    const request = AXIOS_INSTANCE.get('/private');
    await vi.advanceTimersByTimeAsync(2_000);

    await expect(request).resolves.toHaveProperty('status', 200);
    expect(refresh.history.post).toHaveLength(4);
    const proofs = refresh.history.post.map(attempt => attempt.headers?.DPoP);
    expect(new Set(proofs).size).toBe(4);
    expect(crypto.proof.mock.calls.every(call => call[0] === crypto.key)).toBe(true);
    expect(crypto.proof).toHaveBeenCalledWith(crypto.key, 'POST', 'https://api.baander.app/api/auth/refresh', { nonce: 'required-nonce' });
  });
});

describe('a refresh shared between tabs', () => {
  let location: { href: string; origin: string };

  beforeEach(() => {
    location = { href: 'https://baander.app/library', origin: 'https://baander.app' };
    vi.stubGlobal('location', location);
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  function expectSessionKept() {
    expect(auth.clearAuth).not.toHaveBeenCalled();
    expect(auth.accessToken).toBe('old-access');
    expect(auth.refreshToken).toBe('old-refresh');
    expect(location.href).toBe('https://baander.app/library');
  }

  it('adopts the rotation another tab made under the lock and never calls the refresh endpoint', async () => {
    privateApi();
    refresh.onPost().reply(200, { accessToken: 'server-access', refreshToken: 'server-refresh' });
    auth.adoptStoredAuth.mockImplementation(async () => {
      expect(locks.isHeld()).toBe(true);
      Object.assign(auth, { accessToken: 'new-access', refreshToken: 'new-refresh' });
      return true;
    });

    await expect(AXIOS_INSTANCE.get('/private')).resolves.toHaveProperty('status', 200);

    expect(refresh.history.post).toHaveLength(0);
    expect(auth.adoptStoredAuth).toHaveBeenCalledOnce();
    expect(auth.setTokens).not.toHaveBeenCalled();
    expect(api.history.get).toHaveLength(2);
    expect(api.history.get[1].headers?.Authorization).toBe('DPoP new-access');
    expect(locks.isHeld()).toBe(false);
  });

  it('does not call the server when the failed token was replaced while it waited for the lock', async () => {
    privateApi();
    refresh.onPost().reply(200, { accessToken: 'server-access', refreshToken: 'server-refresh' });
    const release = locks.hold();

    const request = AXIOS_INSTANCE.get('/private');
    await vi.waitFor(() => expect(locks.requests).toHaveLength(2));
    // The `rotated` event from the other tab was adopted before the lock was granted; the epochs now match.
    Object.assign(auth, { accessToken: 'new-access', refreshToken: 'new-refresh' });
    release();

    await expect(request).resolves.toHaveProperty('status', 200);
    expect(auth.adoptStoredAuth).toHaveBeenCalledOnce();
    expect(refresh.history.post).toHaveLength(0);
    expect(api.history.get[1].headers?.Authorization).toBe('DPoP new-access');
  });

  it('holds the lock until the rotation is written and releases it afterwards', async () => {
    privateApi();
    let release!: () => void;
    auth.setTokens.mockImplementation((accessToken, refreshToken) => {
      Object.assign(auth, { accessToken, refreshToken });
      return new Promise<void>(resolve => { release = resolve; });
    });
    refresh.onPost().reply(200, { accessToken: 'new-access', refreshToken: 'new-refresh' });

    const request = AXIOS_INSTANCE.get('/private');
    await vi.waitFor(() => expect(auth.setTokens).toHaveBeenCalledOnce());
    expect(locks.isHeld()).toBe(true);

    release();
    await expect(request).resolves.toHaveProperty('status', 200);
    expect(locks.isHeld()).toBe(false);
  });

  it('fails as transient at the budget when another tab never releases the lock, after one re-read', async () => {
    vi.useFakeTimers();
    api.onGet('/private').reply(401);
    refresh.onPost().reply(200, { accessToken: 'server-access', refreshToken: 'server-refresh' });
    locks.hold();

    const request = AXIOS_INSTANCE.get('/private').catch(error => error);
    await vi.advanceTimersByTimeAsync(REFRESH_BUDGET_MS - 1);
    expect(auth.adoptStoredAuth).not.toHaveBeenCalled();

    await vi.advanceTimersByTimeAsync(1);

    await expect(request).resolves.toHaveProperty('name', 'AuthCoordinationError');
    expect(auth.adoptStoredAuth).toHaveBeenCalledOnce();
    expect(refresh.history.post).toHaveLength(0);
    expectSessionKept();
  });

  it('adopts after an aborted lock wait when the other tab rotated meanwhile', async () => {
    vi.useFakeTimers();
    privateApi();
    locks.hold();
    auth.adoptStoredAuth.mockImplementation(async () => {
      Object.assign(auth, { accessToken: 'new-access', refreshToken: 'new-refresh' });
      return true;
    });

    const request = AXIOS_INSTANCE.get('/private');
    await vi.advanceTimersByTimeAsync(REFRESH_BUDGET_MS);

    await expect(request).resolves.toHaveProperty('status', 200);
    expect(refresh.history.post).toHaveLength(0);
    expect(auth.clearAuth).not.toHaveBeenCalled();
  });

  it('fails as transient and keeps the session without the Web Locks API', async () => {
    restoreLocks();
    api.onGet('/private').reply(401);
    refresh.onPost().reply(200, { accessToken: 'server-access', refreshToken: 'server-refresh' });

    const error = await AXIOS_INSTANCE.get('/private').catch(reason => reason);

    expect(error).toHaveProperty('name', 'AuthCoordinationError');
    expect(refresh.history.post).toHaveLength(0);
    expect(auth.adoptStoredAuth).not.toHaveBeenCalled();
    expectSessionKept();
  });
});

describe('WebSocket ticket issuance', () => {
  it('issues through the existing DPoP-authenticated HTTP client with cancellation', async () => {
    const ticket = 'a'.repeat(64);
    const controller = new AbortController();
    api.onPost('/api/ws/tickets').reply(200, { data: { ticket, expiresAt: '2026-10-15T12:00:30Z' } });
    await expect(requestWebSocketTicket(controller.signal)).resolves.toBe(ticket);
    expect(api.history.post[0].headers?.Authorization).toBe('DPoP old-access');
    expect(api.history.post[0].headers?.DPoP).toBe('proof');
    expect(api.history.post[0].signal).toBe(controller.signal);
    expect(crypto.proof).toHaveBeenCalledWith(crypto.key, 'post', 'https://api.baander.app/api/ws/tickets', {
      accessToken: 'old-access', nonce: undefined,
    });
  });

  it('refreshes an expired access token before issuing a connection ticket', async () => {
    api.onPost('/api/ws/tickets').replyOnce(401).onPost('/api/ws/tickets')
      .reply(200, { data: { ticket: 'b'.repeat(64), expiresAt: '2026-10-15T12:00:30Z' } });
    refresh.onPost().reply(200, { accessToken: 'new-access', refreshToken: 'new-refresh' });
    await expect(requestWebSocketTicket(new AbortController().signal)).resolves.toBe('b'.repeat(64));
    expect(api.history.post.map(request => request.headers?.Authorization)).toEqual([
      'DPoP old-access', 'DPoP new-access',
    ]);
    expect(refresh.history.post).toHaveLength(1);
  });

  it('signs a fresh proof when ticket issuance challenges the DPoP nonce', async () => {
    api.onPost('/api/ws/tickets').replyOnce(400, { error: 'use_dpop_nonce' }, { 'dpop-nonce': 'ticket-nonce' })
      .onPost('/api/ws/tickets').reply(200, { data: { ticket: 'c'.repeat(64), expiresAt: '2026-10-15T12:00:30Z' } });
    await requestWebSocketTicket(new AbortController().signal);
    expect(crypto.proof).toHaveBeenLastCalledWith(crypto.key, 'post', 'https://api.baander.app/api/ws/tickets', {
      accessToken: 'old-access', nonce: 'ticket-nonce',
    });
    expect(api.history.post).toHaveLength(2);
  });

  it.each([
    {},
    { data: { ticket: 'raw-access-token', expiresAt: '2026-10-15T12:00:30Z' } },
    { data: { ticket: 'A'.repeat(64), expiresAt: '2026-10-15T12:00:30Z' } },
    { data: { ticket: 'a'.repeat(64) + '\n', expiresAt: '2026-10-15T12:00:30Z' } },
    { data: { ticket: 'a'.repeat(64), expiresAt: 'invalid' } },
  ])('rejects an invalid ticket receipt without exposing its contents', async body => {
    api.onPost('/api/ws/tickets').reply(200, body);
    await expect(requestWebSocketTicket(new AbortController().signal)).rejects.toThrow('Invalid WebSocket ticket response');
  });

  it('builds an exact upgrade path from the configured API origin', () => {
    expect(webSocketEndpoint()).toBe('wss://api.baander.app/api/ws');
    window.__BAANDER_API_URL__ = 'http://local.baander.app:8080/base';
    expect(webSocketEndpoint()).toBe('ws://local.baander.app:8080/api/ws');
  });
});
