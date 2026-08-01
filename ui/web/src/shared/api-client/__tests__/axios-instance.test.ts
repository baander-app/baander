import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@/features/auth/stores/auth-store', () => ({
  useAuthStore: {
    getState: () => ({ accessToken: null, refreshToken: null }),
  },
}));

vi.mock('@/shared/crypto/dpop-store', () => ({
  getDpopKeyPair: () => null,
  getDpopNonce: () => null,
  setDpopNonce: vi.fn(),
}));

vi.mock('@/shared/crypto/dpop-proof', () => ({
  createDpopProof: vi.fn(),
}));

import { customInstance, AXIOS_INSTANCE } from '../axios-instance';
import MockAdapter from 'axios-mock-adapter';

describe('customInstance', () => {
  let mock: MockAdapter;

  beforeEach(() => {
    mock = new MockAdapter(AXIOS_INSTANCE);
  });

  it('returns body data with status and headers', async () => {
    const body = { data: { name: 'test', value: 42 } };
    mock.onGet('/api/test').reply(200, body, { 'x-custom': 'header-val' });

    const result = await customInstance<Record<string, unknown>>('/api/test', {
      method: 'GET',
    });

    expect(result.status).toBe(200);
    expect(result.data).toEqual({ name: 'test', value: 42 });
    expect(result.headers).toBeDefined();
  });

  it('handles backend envelope without double-nesting', async () => {
    const body = { data: { challengeKey: 'abc', options: { rp: 'test' } } };
    mock.onPost('/api/auth/passkey/options').reply(200, body);

    const result = await customInstance<Record<string, unknown>>(
      '/api/auth/passkey/options',
      { method: 'POST' },
    );

    expect(result.data).toEqual({ challengeKey: 'abc', options: { rp: 'test' } });
    expect(result.status).toBe(200);
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
    expect(result.status).toBe(200);
  });

  it('handles 204 No Content', async () => {
    mock.onDelete('/api/test/1').reply(204);

    const result = await customInstance<Record<string, unknown>>('/api/test/1', {
      method: 'DELETE',
    });

    expect(result.status).toBe(204);
  });

  it('propagates error responses', async () => {
    mock.onPost('/api/test').reply(422, { message: 'Validation failed' });

    await expect(
      customInstance('/api/test', { method: 'POST', body: '{}' }),
    ).rejects.toThrow();
  });

  it('preserves body properties alongside status and headers', async () => {
    const body = { token: 'abc123', expires: 3600 };
    mock.onPost('/api/auth/refresh').reply(200, body);

    const result = await customInstance<Record<string, unknown>>('/api/auth/refresh', {
      method: 'POST',
      body: JSON.stringify({ refreshToken: 'rt' }),
    });

    expect(result.token).toBe('abc123');
    expect(result.expires).toBe(3600);
    expect(result.status).toBe(200);
    expect(result.headers).toBeDefined();
  });
});
