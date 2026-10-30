import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

vi.mock('@/features/auth/stores/auth-store', () => ({
  useAuthStore: {
    getState: () => ({ accessToken: null, refreshToken: null }),
  },
}));

vi.mock('@/shared/crypto/dpop-store', () => ({
  getDpopKeyPair: vi.fn(() => null),
  getDpopNonce: () => null,
  setDpopNonce: vi.fn(),
}));

vi.mock('@/shared/crypto/dpop-proof', () => ({
  createDpopProof: vi.fn(),
}));

import { buildHtu, customInstance, AXIOS_INSTANCE } from '../axios-instance';
import MockAdapter from 'axios-mock-adapter';
import { getDpopKeyPair } from '@/shared/crypto/dpop-store';
import { createDpopProof } from '@/shared/crypto/dpop-proof';
import { advanceAuthorization, bindOwnershipToSignal, captureOwnership, setAuthorityPending, synchronizeOwnership } from '@/shared/live/ownership';

describe('DPoP request URI', () => {
  it.each([
    ['http://baander.app:49153/api/auth/login', 'http://baander.app:49153/api/auth/login'],
    ['https://baander.app:8443/api/auth/login', 'https://baander.app:8443/api/auth/login'],
    ['http://baander.app:49153/api/images/cover/file?preset=thumb#preview', 'http://baander.app:49153/api/images/cover/file'],
    ['https://baander.app:8443/api/images/cover/file?preset=thumb#preview', 'https://baander.app:8443/api/images/cover/file'],
  ])('binds %s to its actual scheme, authority and path', (requestUrl, expectedHtu) => {
    expect(buildHtu(requestUrl)).toBe(expectedHtu);
  });

  it('resolves relative requests against the configured API origin', () => {
    const previousApiUrl = window.__BAANDER_API_URL__;
    window.__BAANDER_API_URL__ = 'http://api.baander.app:49153';
    try {
      expect(buildHtu('/api/auth/login?redirect=movies#form')).toBe('http://api.baander.app:49153/api/auth/login');
    } finally {
      window.__BAANDER_API_URL__ = previousApiUrl;
    }
  });
});

describe('customInstance', () => {
  let mock: MockAdapter;

  beforeEach(() => {
    mock = new MockAdapter(AXIOS_INSTANCE);
  });

  afterEach(() => mock.restore());

  it('returns body data without transport metadata', async () => {
    const body = { data: { name: 'test', value: 42 } };
    mock.onGet('/api/test').reply(200, body, { 'X-Baander-Test': 'header-val' });

    const result = await customInstance<Record<string, unknown>>('/api/test', {
      method: 'GET',
    });

    expect(result).toEqual(body);
    expect(result.data).toEqual({ name: 'test', value: 42 });
    expect(result).not.toHaveProperty('headers');
  });

  it('handles backend envelope without double-nesting', async () => {
    const body = { data: { challengeKey: 'abc', options: { rp: 'test' } } };
    mock.onPost('/api/auth/passkey/options').reply(200, body);

    const result = await customInstance<Record<string, unknown>>(
      '/api/auth/passkey/options',
      { method: 'POST' },
    );

    expect(result.data).toEqual({ challengeKey: 'abc', options: { rp: 'test' } });
    expect(result).toEqual(body);
  });

  it('handles paginated response with data array', async () => {
    const body = { data: [{ id: 1 }, { id: 2 }], current_page: 1, last_page: 3 };
    mock.onGet('/api/artists').reply(200, body);

    const result = await customInstance<Record<string, unknown>>('/api/artists', {
      method: 'GET',
    });

    expect(result.data).toEqual([{ id: 1 }, { id: 2 }]);
    expect(result.current_page).toBe(1);
    expect(result.last_page).toBe(3);
    expect(result).toEqual(body);
  });

  it('handles 204 No Content', async () => {
    mock.onDelete('/api/test/1').reply(204, '');

    const result = await customInstance<void>('/api/test/1', {
      method: 'DELETE',
    });

    expect(result).toBeUndefined();
  });

  it('propagates error responses', async () => {
    mock.onPost('/api/test').reply(422, { message: 'Validation failed' });

    await expect(
      customInstance('/api/test', { method: 'POST', body: '{}' }),
    ).rejects.toThrow();
  });

  it('preserves body properties without transport metadata', async () => {
    const body = { token: 'abc123', expires: 3600 };
    mock.onPost('/api/auth/refresh').reply(200, body);

    const result = await customInstance<Record<string, unknown>>('/api/auth/refresh', {
      method: 'POST',
      body: JSON.stringify({ refreshToken: 'rt' }),
    });

    expect(result.token).toBe('abc123');
    expect(result.expires).toBe(3600);
    expect(result).toEqual(body);
    expect(result).not.toHaveProperty('headers');
  });
});


describe('file-based desktop API requests', () => {
  it('signs configured API requests while keeping proofs off foreign requests', async () => {
    const previousBase = AXIOS_INSTANCE.defaults.baseURL;
    const api = 'https://api.baander.app';
    const mock = new MockAdapter(AXIOS_INSTANCE);
    vi.stubGlobal('window', {
      __BAANDER_API_URL__: api,
      location: { origin: 'null', href: 'file:///opt/baander/index.html#/login' },
    });
    AXIOS_INSTANCE.defaults.baseURL = api;
    vi.mocked(getDpopKeyPair).mockReturnValue({} as NonNullable<ReturnType<typeof getDpopKeyPair>>);
    vi.mocked(createDpopProof).mockResolvedValue('signed-proof');
    mock.onGet('/api/test').reply(config => {
      expect(config.headers?.DPoP).toBe('signed-proof');
      return [200, {}];
    });
    mock.onGet('https://foreign.baander.app/data').reply(config => {
      expect(config.headers?.DPoP).toBeUndefined();
      expect(config.headers?.Authorization).toBeUndefined();
      return [200, {}];
    });
    try {
      await AXIOS_INSTANCE.get('/api/test');
      await AXIOS_INSTANCE.get('https://foreign.baander.app/data', {
        headers: { DPoP: 'must-not-leak', Authorization: 'must-not-leak' },
      });
    } finally {
      mock.restore();
      AXIOS_INSTANCE.defaults.baseURL = previousBase;
      vi.mocked(getDpopKeyPair).mockReturnValue(null);
      vi.mocked(createDpopProof).mockReset();
      vi.unstubAllGlobals();
    }
  });
});


describe('API ownership fencing', () => {
  let mock: MockAdapter;

  beforeEach(() => {
    mock = new MockAdapter(AXIOS_INSTANCE);
    synchronizeOwnership('principal-a');
  });

  afterEach(() => {
    mock.restore();
    setAuthorityPending(false);
    synchronizeOwnership(null);
  });

  it('holds resource reads until authority recovery while allowing control requests', async () => {
    setAuthorityPending(true);
    mock.onGet('/api/protected').reply(200, { data: 'permitted' });
    mock.onGet('/api/auth/me').reply(200, { data: 'current-account' });
    mock.onGet('/api/auth/passkey').reply(200, { data: [] });
    mock.onPost('/api/auth/login/passkey').reply(200, {});
    mock.onGet('/api/live/recovery').reply(200, { data: 'authority' });
    mock.onPost('/api/ws/tickets').reply(200, { data: 'ticket' });
    const resource = AXIOS_INSTANCE.get('/api/protected');
    const account = AXIOS_INSTANCE.get('/api/auth/me');
    const passkeys = AXIOS_INSTANCE.get('/api/auth/passkey');

    await expect(AXIOS_INSTANCE.post('/api/auth/login/passkey')).resolves.toHaveProperty('status', 200);
    await expect(AXIOS_INSTANCE.get('/api/live/recovery')).resolves.toHaveProperty('status', 200);
    await expect(AXIOS_INSTANCE.post('/api/ws/tickets')).resolves.toHaveProperty('status', 200);
    expect(mock.history.get.map(config => config.url)).toEqual(['/api/live/recovery']);

    setAuthorityPending(false);
    await expect(resource).resolves.toHaveProperty('data', { data: 'permitted' });
    await expect(account).resolves.toHaveProperty('data', { data: 'current-account' });
    await expect(passkeys).resolves.toHaveProperty('data', { data: [] });
  });

  it('cancels a queued read when its signal aborts during authority recovery', async () => {
    setAuthorityPending(true);
    const controller = new AbortController();
    mock.onGet('/api/protected').reply(200, {});
    const result = AXIOS_INSTANCE.get('/api/protected', { signal: controller.signal }).catch(error => error);
    await Promise.resolve();
    controller.abort();

    expect(await result).toMatchObject({ code: 'ERR_CANCELED' });
    expect(mock.history.get).toHaveLength(0);
  });

  it('rejects a stale query signal before issuing another HTTP attempt', async () => {
    const controller = new AbortController();
    bindOwnershipToSignal(controller.signal, captureOwnership());
    advanceAuthorization();
    mock.onGet('/api/protected').reply(200, {});

    await expect(AXIOS_INSTANCE.get('/api/protected', { signal: controller.signal }))
      .rejects.toMatchObject({ code: 'ERR_CANCELED' });
    expect(mock.history.get).toHaveLength(0);
  });

  it('rejects a successful old-principal response even when its DPoP key is unchanged', async () => {
    let finish!: () => void;
    let started!: () => void;
    const received = new Promise<void>(resolve => { started = resolve; });
    mock.onGet('/api/protected').reply(() => new Promise(resolve => {
      finish = () => resolve([200, { data: 'old-private-data' }]);
      started();
    }));
    const result = AXIOS_INSTANCE.get('/api/protected').then(
      response => response.data,
      error => error,
    );
    await received;
    synchronizeOwnership('principal-b');
    finish();
    expect(await result).toMatchObject({ code: 'ERR_CANCELED' });
  });

  it('rejects successful responses started before same-account access loss', async () => {
    let finish!: () => void;
    let started!: () => void;
    const received = new Promise<void>(resolve => { started = resolve; });
    mock.onGet('/api/protected').reply(() => new Promise(resolve => {
      finish = () => resolve([200, { data: 'removed-access' }]);
      started();
    }));
    const result = AXIOS_INSTANCE.get('/api/protected').then(
      response => response.data,
      error => error,
    );
    await received;
    advanceAuthorization();
    finish();
    expect(await result).toMatchObject({ code: 'ERR_CANCELED' });
  });

  it('fences old errors before nonce retries can issue a replacement request', async () => {
    let finish!: () => void;
    let started!: () => void;
    const received = new Promise<void>(resolve => { started = resolve; });
    mock.onGet('/api/protected').reply(() => new Promise(resolve => {
      finish = () => resolve([400, { error: 'use_dpop_nonce' }, { 'dpop-nonce': 'old-nonce' }]);
      started();
    }));
    const result = AXIOS_INSTANCE.get('/api/protected').catch(error => error);
    await received;
    advanceAuthorization();
    finish();
    expect(await result).toMatchObject({ code: 'ERR_CANCELED' });
    expect(mock.history.get).toHaveLength(1);
  });
});
